<?php

namespace Xstrm\Xstrm;

use Illuminate\Support\Str;

/**
 * The in-request event buffer.
 *
 * Events collected during one request are assembled into a single envelope and
 * sent once, at terminate — one customer request produces at most one ingest
 * call (§4.6). There is no cross-request buffer, so there is no window in which
 * collected data can be lost.
 */
class Xstrm
{
    /** @var array<int, array<string, mixed>> */
    protected array $events = [];

    protected int $dropped = 0;

    protected ?string $traceId = null;

    public function __construct(
        protected Config $config,
        protected Transport $transport,
    ) {}

    /**
     * One trace id per request, attached to every event type. Nothing consumes
     * it in v1 — it exists so spans in v2 can link errors to transactions
     * retroactively for anyone on a recent package version (§4.7).
     */
    public function traceId(): string
    {
        return $this->traceId ??= (string) Str::uuid7();
    }

    public function record(array $event): void
    {
        if (! $this->config->enabled()) {
            return;
        }

        if (count($this->events) >= $this->config->maxEventsPerRequest()) {
            $this->dropped++;

            return;
        }

        $this->events[] = $event + ['trace' => $this->traceId()];
    }

    public function flush(): void
    {
        if ($this->events === []) {
            $this->reset();

            return;
        }

        $envelope = $this->envelope();

        $this->reset();

        $this->transport->send($envelope);
    }

    protected function envelope(): array
    {
        return [
            'v' => 1,
            'sent_at' => now()->toIso8601ZuluString(),
            'sdk' => ['name' => 'php-laravel', 'version' => Config::VERSION],
            'env' => $this->config->environment(),
            'release' => $this->config->release(),
            'dropped' => $this->dropped,
            'events' => $this->events,
        ];
    }

    /** Octane reuses the process, so the buffer must be cleared per request. */
    public function reset(): void
    {
        $this->events = [];
        $this->dropped = 0;
        $this->traceId = null;
    }

    public function events(): array
    {
        return $this->events;
    }

    public function dropped(): int
    {
        return $this->dropped;
    }
}
