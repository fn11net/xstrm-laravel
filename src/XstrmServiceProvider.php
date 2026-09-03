<?php

namespace Xstrm\Xstrm;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;
use Xstrm\Xstrm\Commands\InstallCommand;
use Xstrm\Xstrm\Http\Middleware\TrackPageview;

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
    }

    public function packageBooted(): void
    {
        // Booting must never throw, whatever the app's state (§4.5 rule 5).
        try {
            $this->registerMiddleware();
            $this->registerFlushHooks();
        } catch (Throwable) {
            // Boot failures disable the package rather than break the app.
        }
    }

    protected function registerMiddleware(): void
    {
        if (! $this->app->runningInConsole()) {
            $this->app->make(Kernel::class)->pushMiddleware(TrackPageview::class);
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
