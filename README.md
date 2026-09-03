# Xstrm for Laravel

Analytics, error tracking and performance monitoring for Laravel. Privacy-first, EU-hosted.

```bash
composer require xstrm/laravel-xstrm
php artisan xstrm:install
```

```dotenv
XSTRM_DSN=https://sk_live_9f2a4c...@in.xstrm.net/17
```

That is the entire required setup.

## Safety

The package is designed so that it can never take your application down:

- Every collector and the transport are wrapped in `try/catch (\Throwable)`. Failures are swallowed and logged at `debug` level, never higher.
- A malformed or missing `XSTRM_DSN` disables the package silently. It never throws during boot.
- HTTP timeout is 2 seconds, connect timeout 1 second.
- After 3 consecutive transport failures the circuit opens for 5 minutes.
- Sending happens after the response is delivered, never before.
- At most 500 events are held per request; the rest are dropped and counted.

## Switches

| Variable | Default | Effect |
|---|---|---|
| `XSTRM_ENABLED` | `true` | Global kill switch |
| `XSTRM_ANALYTICS` | `true` | Pageviews |
| `XSTRM_ERRORS` | `true` | Error capture |
| `XSTRM_PERF` | `true` | Performance |
| `XSTRM_TRANSPORT` | `auto` | `auto`, `queue`, `inline` or `null` |

## Licence

MIT.
