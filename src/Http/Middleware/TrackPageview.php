<?php

namespace Xstrm\Xstrm\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Xstrm\Xstrm\Bots;
use Xstrm\Xstrm\Config;
use Xstrm\Xstrm\Xstrm;

class TrackPageview
{
    public function __construct(
        protected Xstrm $xstrm,
        protected Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /** Runs after the response is sent, so nothing here delays the visitor. */
    public function terminate(Request $request, Response $response): void
    {
        try {
            if ($this->config->analyticsEnabled() && $this->shouldRecord($request, $response)) {
                $this->xstrm->record([
                    't' => 'pv',
                    'ts' => now()->toIso8601ZuluString(),
                    'path' => $request->getPathInfo(),
                    'route' => $request->route()?->getName(),
                    'ref' => $request->headers->get('referer'),
                    'ua' => $request->userAgent(),
                    // The ingest resolves country from this and discards it
                    // before anything is written. It is never persisted (§5).
                    'ip' => $request->ip(),
                    'lang' => $request->headers->get('accept-language'),
                ]);
            }
        } catch (Throwable) {
            // A pageview is never worth an exception in someone's app.
        }

        // Flush unconditionally — other collectors may have recorded events on
        // a request that produced no pageview. Under Octane the shutdown
        // function runs at worker exit, so it cannot be the only flush path.
        try {
            $this->xstrm->flush();
        } catch (Throwable) {
            // no-op
        }
    }

    protected function shouldRecord(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() >= 400) {
            return false;
        }

        if (! str_contains((string) $response->headers->get('content-type'), 'text/html')) {
            return false;
        }

        if ($request->is(...$this->config->ignoredPaths())) {
            return false;
        }

        return ! ($this->config->ignoreBots() && Bots::matches($request->userAgent()));
    }
}
