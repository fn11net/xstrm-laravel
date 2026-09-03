<?php

namespace Xstrm\Xstrm;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Facades\Http;
use Throwable;
use Xstrm\Xstrm\Jobs\SendEnvelope;

/**
 * Sends one envelope to the ingest.
 *
 * Nothing in here is allowed to surface an exception into the customer's app
 * (§4.5 rule 1). Every public entry point ends in catch (\Throwable) and a
 * debug-level log line at most.
 */
class Transport
{
    public function __construct(
        protected Config $config,
        protected Cache $cache,
    ) {}

    /** Dispatch or post, whichever this app can do. Never throws. */
    public function send(array $envelope): void
    {
        try {
            $mode = $this->resolveMode();

            if ($mode === 'null') {
                return;
            }

            if ($mode === 'queue') {
                SendEnvelope::dispatch($envelope)->onQueue($this->config->queue());

                return;
            }

            $this->post($envelope);
        } catch (Throwable $e) {
            $this->swallow($e);
        }
    }

    /** The actual HTTP call. Never throws. Returns the decoded body, if any. */
    public function post(array $envelope): ?array
    {
        try {
            $dsn = $this->config->dsn();

            if ($dsn === null || $this->circuitIsOpen()) {
                return null;
            }

            $body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($body === false) {
                return null;
            }

            $compressed = gzencode($body, 6);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$dsn->key,
                'Content-Type' => 'application/json',
                'Content-Encoding' => 'gzip',
                'User-Agent' => 'xstrm-php-laravel/'.Config::VERSION,
            ])
                ->timeout($this->config->timeout())
                ->connectTimeout($this->config->connectTimeout())
                ->withBody($compressed !== false ? $compressed : $body, 'application/json')
                ->post($dsn->url('/i/e'));

            if ($response->serverError() || $response->status() === 0) {
                $this->recordFailure();

                return null;
            }

            // A 4xx is a rejection, not an outage — the ingest answered, so the
            // circuit stays closed. Retrying would not help and would hammer it.
            $this->recordSuccess();

            return $response->json();
        } catch (Throwable $e) {
            $this->recordFailure();
            $this->swallow($e);

            return null;
        }
    }

    protected function resolveMode(): string
    {
        $mode = $this->config->transportMode();

        if (in_array($mode, ['queue', 'inline', 'null'], true)) {
            return $mode;
        }

        // auto: prefer the queue. Posting inline occupies a PHP-FPM worker for
        // up to the timeout even though the visitor already has their page.
        return $this->hasRealQueue() ? 'queue' : 'inline';
    }

    protected function hasRealQueue(): bool
    {
        try {
            return config('queue.default') !== 'sync';
        } catch (Throwable) {
            return false;
        }
    }

    protected function circuitIsOpen(): bool
    {
        try {
            return (bool) $this->cache->get('xstrm:cb:open');
        } catch (Throwable) {
            // A broken cache must not stop us sending; fail open.
            return false;
        }
    }

    protected function recordFailure(): void
    {
        try {
            $failures = (int) $this->cache->get('xstrm:cb:failures', 0) + 1;

            if ($failures >= $this->config->breakerFailures()) {
                $this->cache->put('xstrm:cb:open', true, $this->config->breakerCooldown());
                $this->cache->forget('xstrm:cb:failures');

                return;
            }

            $this->cache->put('xstrm:cb:failures', $failures, $this->config->breakerCooldown());
        } catch (Throwable) {
            // no-op
        }
    }

    protected function recordSuccess(): void
    {
        try {
            $this->cache->forget('xstrm:cb:failures');
            $this->cache->forget('xstrm:cb:open');
        } catch (Throwable) {
            // no-op
        }
    }

    protected function swallow(Throwable $e): void
    {
        try {
            logger()?->debug('xstrm: '.$e->getMessage());
        } catch (Throwable) {
            // no-op
        }
    }
}
