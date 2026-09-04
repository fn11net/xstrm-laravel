<?php

namespace Xstrm\Xstrm\Performance;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Xstrm\Xstrm\Config;
use Xstrm\Xstrm\Xstrm;

/**
 * Emits one `txn` event per request (§5).
 *
 * Route-level aggregates only. Spans are v2, and the `spans` key ships empty
 * so the shape does not change when they arrive.
 */
class PerformanceCollector
{
    protected ?float $startedAt = null;

    /** Decided once per request, so every event from it agrees on being sampled. */
    protected ?bool $sampled = null;

    public function __construct(
        protected Xstrm $xstrm,
        protected Config $config,
        protected Metrics $metrics,
    ) {}

    public function start(?float $at = null): void
    {
        // Prefer the moment PHP received the request over the moment this
        // middleware ran: bootstrapping is time the visitor waited too.
        $this->startedAt = $at
            ?? (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    }

    public function record(Request $request, Response $response): void
    {
        try {
            if (! $this->shouldRecord()) {
                return;
            }

            $this->xstrm->record([
                't' => 'txn',
                'ts' => now()->toIso8601ZuluString(),
                'route' => $request->route()?->getName(),
                'uri' => $this->uri($request),
                'method' => $request->method(),
                'status' => $response->getStatusCode(),
                'ms' => round((microtime(true) - $this->startedAt) * 1000, 1),
                'mem_mb' => round(memory_get_peak_usage(true) / 1_048_576, 1),
                ...$this->metrics->toArray(),
                'spans' => [],
            ]);
        } catch (Throwable) {
            // A measurement is never worth an exception in someone's app.
        }
    }

    /**
     * The route pattern, not the URL.
     *
     * /products/hoodie and /products/tee are one route with one latency
     * distribution; grouping by URL would produce a table with a million rows
     * and no percentile worth reading.
     */
    protected function uri(Request $request): string
    {
        $uri = $request->route()?->uri();

        return '/'.ltrim($uri ?? $request->getPathInfo(), '/');
    }

    protected function shouldRecord(): bool
    {
        if (! $this->config->performanceEnabled() || $this->startedAt === null) {
            return false;
        }

        return $this->isSampled();
    }

    /**
     * Sampling is per request, decided once and remembered. Rolling separately
     * per event would let a request report its transaction but not its errors.
     */
    public function isSampled(): bool
    {
        if ($this->sampled !== null) {
            return $this->sampled;
        }

        $rate = $this->config->performanceSampleRate();

        if ($rate >= 1.0) {
            return $this->sampled = true;
        }

        if ($rate <= 0.0) {
            return $this->sampled = false;
        }

        return $this->sampled = (mt_rand() / mt_getrandmax()) < $rate;
    }

    public function reset(): void
    {
        $this->startedAt = null;
        $this->sampled = null;
        $this->metrics->reset();
    }

    public function metrics(): Metrics
    {
        return $this->metrics;
    }
}
