<?php

namespace Xstrm\Xstrm;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed reads over config/xstrm.php. Every accessor has a working default, so a
 * half-published config file degrades rather than fatals.
 */
class Config
{
    public const VERSION = '0.1.0';

    protected ?Dsn $dsn = null;

    protected bool $dsnParsed = false;

    public function __construct(protected Repository $config) {}

    public function dsn(): ?Dsn
    {
        if (! $this->dsnParsed) {
            $this->dsn = Dsn::parse($this->get('dsn'));
            $this->dsnParsed = true;
        }

        return $this->dsn;
    }

    /** The package is on only when switched on AND pointed somewhere. */
    public function enabled(): bool
    {
        return (bool) $this->get('enabled', true) && $this->dsn() !== null;
    }

    public function analyticsEnabled(): bool
    {
        return $this->enabled()
            && (bool) $this->get('analytics.enabled', true)
            && $this->get('analytics.source', 'server') === 'server';
    }

    public function ignoredPaths(): array
    {
        return (array) $this->get('analytics.ignore_paths', []);
    }

    public function ignoreBots(): bool
    {
        return (bool) $this->get('analytics.ignore_bots', true);
    }

    public function environment(): ?string
    {
        return $this->get('environment');
    }

    public function release(): ?string
    {
        return $this->get('release');
    }

    public function transportMode(): string
    {
        return (string) $this->get('transport.mode', 'auto');
    }

    public function queue(): string
    {
        return (string) $this->get('transport.queue', 'default');
    }

    public function timeout(): float
    {
        return (float) $this->get('transport.timeout', 2);
    }

    public function connectTimeout(): float
    {
        return (float) $this->get('transport.connect_timeout', 1);
    }

    public function maxEventsPerRequest(): int
    {
        return (int) $this->get('max_events_per_request', 500);
    }

    public function breakerFailures(): int
    {
        return (int) $this->get('circuit_breaker.failures', 3);
    }

    public function breakerCooldown(): int
    {
        return (int) $this->get('circuit_breaker.cooldown', 300);
    }

    protected function get(string $key, mixed $default = null): mixed
    {
        return $this->config->get("xstrm.{$key}", $default);
    }
}
