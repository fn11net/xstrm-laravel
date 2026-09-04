<?php

namespace Xstrm\Xstrm;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;
use Xstrm\Xstrm\Commands\InstallCommand;
use Xstrm\Xstrm\Errors\ErrorCollector;
use Xstrm\Xstrm\Errors\Frames;
use Xstrm\Xstrm\Performance\Metrics;
use Xstrm\Xstrm\Performance\PerformanceCollector;
use Xstrm\Xstrm\Http\Middleware\CollectRequest;

class XstrmServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('xstrm')
            ->hasConfigFile()
            ->hasCommand(InstallCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Config::class, fn ($app) => new Config($app['config']));

        $this->app->singleton(Transport::class, fn ($app) => new Transport(
            $app->make(Config::class),
            $app['cache']->store(),
        ));

        $this->app->singleton(Xstrm::class, fn ($app) => new Xstrm(
            $app->make(Config::class),
            $app->make(Transport::class),
        ));

        $this->app->singleton(Frames::class, fn () => new Frames);

        $this->app->singleton(ErrorCollector::class, fn ($app) => new ErrorCollector(
            $app->make(Xstrm::class),
            $app->make(Config::class),
            $app->make(Frames::class),
        ));

        $this->app->singleton(Metrics::class, fn () => new Metrics);

        $this->app->singleton(PerformanceCollector::class, fn ($app) => new PerformanceCollector(
            $app->make(Xstrm::class),
            $app->make(Config::class),
            $app->make(Metrics::class),
        ));
    }

    public function packageBooted(): void
    {
        // Booting must never throw, whatever the app's state (§4.5 rule 5).
        try {
            $this->registerMiddleware();
            $this->registerErrorHooks();
            $this->registerPerformanceHooks();
            $this->registerFlushHooks();
        } catch (Throwable) {
            // Boot failures disable the package rather than break the app.
        }
    }

    /**
     * Registered unconditionally.
     *
     * Guarding this with runningInConsole() bought nothing — a middleware
     * cannot run without an HTTP request anyway — and it is true under every
     * test runner, which meant the middleware was never exercised by a single
     * test in either repository.
     */
    protected function registerMiddleware(): void
    {
        $this->app->make(Kernel::class)->pushMiddleware(CollectRequest::class);
    }

    /**
     * Three hooks, overlapping on purpose so an error caught by any route is
     * reported; the collector deduplicates by exception object identity, so no
     * error is ever sent twice (§4.7).
     */
    protected function registerErrorHooks(): void
    {
        // 1. Anything logged at error or above, which includes Laravel's own
        //    exception handler reporting through the log.
        Event::listen(MessageLogged::class, function (MessageLogged $message) {
            if (! in_array($message->level, ['error', 'critical', 'alert', 'emergency'], true)) {
                return;
            }

            $exception = $message->context['exception'] ?? null;

            if ($exception instanceof Throwable) {
                $this->collector()?->capture($exception, $message->level);
            }
        });

        // 2. Fatals and OOM, which kill the process before any listener runs
        //    and are invisible to every other hook.
        register_shutdown_function(function () {
            $error = error_get_last();

            if ($error === null || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $this->collector()?->capture(
                new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']),
                'critical',
            );
        });

        // 3. Queued jobs that failed. Their exception never reaches a request.
        Event::listen(JobFailed::class, function (JobFailed $event) {
            $this->collector()?->capture($event->exception, 'error');
        });
    }

    /**
     * Counters fed by framework events. Each listener is a few integer
     * increments — a request issuing a thousand queries fires this a thousand
     * times, so it cannot allocate.
     */
    protected function registerPerformanceHooks(): void
    {
        $config = $this->app->make(Config::class);

        if (! $config->performanceEnabled()) {
            return;
        }

        if ($config->trackQueries()) {
            DB::listen(function (QueryExecuted $query) use ($config) {
                $this->metrics()?->query($query->time, $config->slowQueryMs());
            });
        }

        if ($config->trackCache()) {
            Event::listen(CacheHit::class, fn () => $this->metrics()?->cacheHit());
            Event::listen(CacheMissed::class, fn () => $this->metrics()?->cacheMiss());
        }

        if ($config->trackHttp()) {
            $this->trackOutboundHttp();
        }
    }

    /**
     * Outbound HTTP time, via a global middleware on Laravel's client.
     *
     * Only calls made through Http:: are seen. A raw cURL or Guzzle client
     * built by hand is invisible, which is worth knowing before reading the
     * number as "all outbound time".
     */
    protected function trackOutboundHttp(): void
    {
        Http::globalMiddleware(function (callable $handler) {
            return function ($request, array $options) use ($handler) {
                $startedAt = microtime(true);

                return $handler($request, $options)->then(
                    function ($response) use ($startedAt) {
                        $this->metrics()?->http((microtime(true) - $startedAt) * 1000);

                        return $response;
                    },
                    function ($reason) use ($startedAt) {
                        // A call that failed still cost the visitor its time,
                        // and a timeout is exactly the case worth seeing.
                        $this->metrics()?->http((microtime(true) - $startedAt) * 1000);

                        return \GuzzleHttp\Promise\Create::rejectionFor($reason);
                    },
                );
            };
        });
    }

    protected function metrics(): ?Metrics
    {
        try {
            return $this->app->make(Metrics::class);
        } catch (Throwable) {
            return null;
        }
    }

    protected function collector(): ?ErrorCollector
    {
        try {
            return $this->app->make(ErrorCollector::class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Web requests flush via the terminable middleware. Everything else needs
     * its own hook, or events collected outside a request are never sent (§4.6).
     */
    protected function registerFlushHooks(): void
    {
        Event::listen(CommandFinished::class, fn () => $this->flush());
        Event::listen(JobProcessed::class, fn () => $this->flush());
        Event::listen(JobFailed::class, fn () => $this->flush());

        // Fatals and OOM kill the process before any listener runs.
        register_shutdown_function(fn () => $this->flush());

        // Octane keeps the process alive across requests, so the buffer has to
        // be cleared or one visitor's events leak into the next one's envelope.
        if (class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
            Event::listen(\Laravel\Octane\Events\RequestReceived::class, function () {
                try {
                    $this->app->make(Xstrm::class)->reset();
                    $this->app->make(ErrorCollector::class)->reset();
                    $this->app->make(PerformanceCollector::class)->reset();
                } catch (Throwable) {
                    // no-op
                }
            });
        }
    }

    protected function flush(): void
    {
        try {
            $this->app->make(Xstrm::class)->flush();
        } catch (Throwable) {
            // no-op
        }
    }
}
