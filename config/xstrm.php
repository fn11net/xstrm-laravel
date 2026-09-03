<?php

return [
    'dsn' => env('XSTRM_DSN'),
    'enabled' => env('XSTRM_ENABLED', true),
    'environment' => env('XSTRM_ENV', env('APP_ENV')),
    'release' => env('XSTRM_RELEASE'),

    'transport' => [
        'mode' => env('XSTRM_TRANSPORT', 'auto'),   // auto|queue|inline|null
        'timeout' => 2,
        'connect_timeout' => 1,
        'queue' => env('XSTRM_QUEUE', 'default'),
    ],

    'analytics' => [
        'enabled' => env('XSTRM_ANALYTICS', true),
        'source' => env('XSTRM_ANALYTICS_SOURCE', 'server'),  // server|client
        'ignore_paths' => ['horizon/*', 'telescope/*', 'nova-api/*', '_debugbar/*'],
        'ignore_bots' => true,
    ],

    'errors' => [
        'enabled' => env('XSTRM_ERRORS', true),
        'ignore' => [
            \Illuminate\Validation\ValidationException::class,
            \Illuminate\Auth\AuthenticationException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        ],
        'send_pii' => env('XSTRM_SEND_PII', false),
        'capture_body' => false,
        'scrub_keys' => [
            'password', 'password_confirmation', 'token', 'secret',
            'authorization', 'cookie', 'credit_card', 'cvv', 'iban',
        ],
    ],

    'performance' => [
        'enabled' => env('XSTRM_PERF', true),
        'sample_rate' => env('XSTRM_PERF_SAMPLE', 1.0),
        'track_queries' => true,
        'track_cache' => true,
        'track_http' => true,
        'track_queue' => true,
        'slow_query_ms' => 100,
    ],

    // Hard ceiling on events held for a single request (§4.5 rule 6).
    'max_events_per_request' => 500,

    'circuit_breaker' => [
        'failures' => 3,        // consecutive failures before the circuit opens
        'cooldown' => 300,      // seconds to stay open
    ],
];
