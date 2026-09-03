<?php

namespace Xstrm\Xstrm;

/**
 * A parsed XSTRM_DSN: https://<key>@<host>/<project_id>
 *
 * Parsing NEVER throws. A malformed or missing DSN yields null, which disables
 * the package silently (spec §4.3, §4.5 rule 5). Crashing a customer's app on
 * boot because their env var has a typo is the one unforgivable failure mode.
 */
final class Dsn
{
    private function __construct(
        public readonly string $endpoint,
        public readonly string $key,
        public readonly string $projectId,
    ) {}

    public static function parse(?string $dsn): ?self
    {
        $dsn = trim((string) $dsn);

        if ($dsn === '') {
            return null;
        }

        $parts = parse_url($dsn);

        if ($parts === false) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $key = $parts['user'] ?? null;
        $projectId = trim((string) ($parts['path'] ?? ''), '/');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if ($host === null || $host === '' || $key === null || $key === '') {
            return null;
        }

        // The project id is the whole path and must be a positive integer —
        // a nested path means the DSN was mangled, not that we should guess.
        if ($projectId === '' || ! ctype_digit($projectId) || (int) $projectId < 1) {
            return null;
        }

        $endpoint = $scheme.'://'.$host;

        if (isset($parts['port'])) {
            $endpoint .= ':'.$parts['port'];
        }

        return new self($endpoint, rawurldecode($key), $projectId);
    }

    public function url(string $path): string
    {
        return $this->endpoint.'/'.ltrim($path, '/');
    }
}
