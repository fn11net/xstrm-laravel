<?php

namespace Xstrm\Xstrm\Errors;

use Illuminate\Http\Request;
use Throwable;
use Xstrm\Xstrm\Config;
use Xstrm\Xstrm\Xstrm;

/**
 * Builds `err` events from exceptions.
 *
 * Three hooks feed this and they overlap on purpose, so an error caught by any
 * route is reported. Deduplication is by exception object identity: the same
 * Throwable instance is reported exactly once per request however many hooks
 * see it (§4.7).
 */
class ErrorCollector
{
    /**
     * Exceptions already reported this request.
     *
     * A WeakMap rather than a set of spl_object_hash values: PHP reuses an
     * object's hash once it is freed, so two distinct sequential exceptions can
     * share one, and the second would be silently dropped as a duplicate. A
     * WeakMap keys on identity and drops its entry when the object goes, which
     * is exactly the lifetime the dedup should have.
     */
    protected \WeakMap $seen;

    public function __construct(
        protected Xstrm $xstrm,
        protected Config $config,
        protected Frames $frames,
    ) {
        $this->seen = new \WeakMap;
    }

    public function capture(Throwable $e, string $level = 'error'): void
    {
        try {
            if (! $this->shouldCapture($e)) {
                return;
            }

            $this->seen[$e] = true;

            $this->xstrm->record([
                't' => 'err',
                'ts' => now()->toIso8601ZuluString(),
                'level' => $level,
                'exception' => $this->chain($e),
                'context' => $this->context(),
            ]);
        } catch (Throwable) {
            // Reporting an error must never become a second error.
        }
    }

    public function reset(): void
    {
        $this->seen = new \WeakMap;
    }

    protected function shouldCapture(Throwable $e): bool
    {
        if (! $this->config->errorsEnabled()) {
            return false;
        }

        if (isset($this->seen[$e])) {
            return false;
        }

        foreach ($this->config->ignoredExceptions() as $ignored) {
            if ($e instanceof $ignored) {
                return false;
            }
        }

        return true;
    }

    /**
     * The exception and everything it wrapped, outermost first. A QueryException
     * caused by a PDOException is one error with two links, and the cause is
     * usually the interesting half.
     */
    protected function chain(Throwable $e): array
    {
        $chain = [];
        $seen = [];

        while ($e !== null && count($chain) < 5) {
            $hash = spl_object_hash($e);

            if (isset($seen[$hash])) {
                break;      // A cycle in getPrevious() would otherwise hang.
            }

            $seen[$hash] = true;

            $chain[] = [
                'type' => $e::class,
                'value' => mb_substr($e->getMessage(), 0, 4000),
                'code' => $e->getCode(),
                'frames' => $this->frames->for($e),
            ];

            $e = $e->getPrevious();
        }

        return $chain;
    }

    protected function context(): array
    {
        try {
            // Console commands and queued jobs have a Request object too, so
            // only the middleware having run proves this was an HTTP request.
            if (! $this->xstrm->isWebRequest() || ! app()->bound('request')) {
                return ['runner' => 'console'];
            }

            /** @var Request $request */
            $request = request();

            return array_filter([
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'route' => $request->route()?->getName(),
                'headers' => $this->headers($request),
                'user' => $this->user(),
            ], fn ($value) => $value !== null);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Headers minus anything on the scrub list, and never the IP — the request
     * context is the most likely place for a secret to leak into an error
     * report, so the filter is a denylist applied to every header (§4.7).
     */
    protected function headers(Request $request): array
    {
        $scrub = array_map('strtolower', $this->config->scrubKeys());
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $lower = strtolower($name);

            if (in_array($lower, ['x-forwarded-for', 'x-real-ip', 'forwarded'], true)) {
                continue;
            }

            $headers[$name] = $this->scrubbed($lower, $scrub) ? '[filtered]' : implode(', ', $values);
        }

        return $headers;
    }

    protected function scrubbed(string $name, array $scrub): bool
    {
        foreach ($scrub as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Only when the customer has explicitly opted in (§4.4 send_pii). */
    protected function user(): ?array
    {
        if (! $this->config->sendPii()) {
            return null;
        }

        try {
            $user = auth()->user();

            if ($user === null) {
                return null;
            }

            return array_filter([
                'id' => $user->getAuthIdentifier(),
                'email' => $user->email ?? null,
            ], fn ($value) => $value !== null);
        } catch (Throwable) {
            return null;
        }
    }
}
