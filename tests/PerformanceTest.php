<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Xstrm\Xstrm\Performance\Metrics;
use Xstrm\Xstrm\Performance\PerformanceCollector;
use Xstrm\Xstrm\Xstrm;

beforeEach(function () {
    config()->set('xstrm.transport.mode', 'inline');
    Http::fake();
});

function perf(): PerformanceCollector
{
    return app(PerformanceCollector::class);
}

function txn(): ?array
{
    foreach (app(Xstrm::class)->events() as $event) {
        if ($event['t'] === 'txn') {
            return $event;
        }
    }

    return null;
}

/**
 * Events as they actually went on the wire.
 *
 * After a real request the in-memory buffer is empty — the middleware flushes
 * it — so the sent envelope is the only honest place to look.
 */
function sentEvents(): Illuminate\Support\Collection
{
    return collect(Http::recorded())
        ->flatMap(function ($pair) {
            $body = $pair[0]->body();
            $decoded = @gzdecode($body);

            return json_decode($decoded !== false ? $decoded : $body, true)['events'] ?? [];
        });
}

function recordTxn(string $uri = '/orders', string $method = 'POST', int $status = 200): void
{
    $collector = perf();
    $collector->start(microtime(true) - 0.05);   // 50ms ago

    $collector->record(Request::create($uri, $method), new Response('', $status));
}

it('emits one transaction per request', function () {
    recordTxn();

    $txn = txn();

    expect($txn)->not->toBeNull()
        ->and($txn['method'])->toBe('POST')
        ->and($txn['status'])->toBe(200)
        ->and($txn['ms'])->toBeGreaterThan(40.0)
        ->and($txn['mem_mb'])->toBeGreaterThan(0)
        // Spans are v2; the key ships empty so the shape does not change.
        ->and($txn['spans'])->toBe([]);
});

it('groups by the route pattern, not the url', function () {
    // /products/hoodie and /products/tee are one route with one latency
    // distribution. Grouping by URL gives a million rows and no usable p95.
    Route::get('/products/{slug}', fn () => 'ok')->name('products.show');

    $this->get('/products/hoodie');
    $this->get('/products/tee');

    $uris = sentEvents()->where('t', 'txn')->pluck('uri')->unique();

    expect($uris)->toHaveCount(1)
        ->and($uris->first())->toBe('/products/{slug}');
});

it('counts queries, their time, and the slow ones', function () {
    config()->set('xstrm.performance.slow_query_ms', 100);

    $metrics = app(Metrics::class);
    $metrics->query(10.0, 100);
    $metrics->query(250.5, 100);
    $metrics->query(5.0, 100);

    recordTxn();

    expect(txn()['db'])->toBe(['count' => 3, 'ms' => 265.5, 'slow' => 1]);
});

it('counts cache hits and misses', function () {
    $metrics = app(Metrics::class);
    $metrics->cacheHit();
    $metrics->cacheHit();
    $metrics->cacheMiss();

    recordTxn();

    expect(txn()['cache'])->toBe(['hits' => 2, 'misses' => 1]);
});

it('counts outbound http time', function () {
    $metrics = app(Metrics::class);
    $metrics->http(210.0);

    recordTxn();

    expect(txn()['http'])->toBe(['count' => 1, 'ms' => 210.0]);
});

it('times a real query through the listener', function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);

    DB::statement('create table probes (id integer)');
    DB::select('select * from probes');

    recordTxn();

    expect(txn()['db']['count'])->toBeGreaterThan(0);
});

it('records the transaction for a request that produced no pageview', function () {
    // A POST that took four seconds is exactly what this module exists to show,
    // and it never produces a pageview.
    Route::post('/orders', fn () => response()->json(['ok' => true]))->name('orders.store');

    $this->postJson('/orders');

    $events = sentEvents();

    expect($events->where('t', 'txn'))->toHaveCount(1)
        ->and($events->where('t', 'pv'))->toHaveCount(0);
});

it('records nothing when performance is switched off', function () {
    config()->set('xstrm.performance.enabled', false);
    app()->forgetInstance(Xstrm\Xstrm\Config::class);
    app()->forgetInstance(PerformanceCollector::class);

    recordTxn();

    expect(txn())->toBeNull();
});

it('records nothing at a zero sample rate, and everything at one', function () {
    config()->set('xstrm.performance.sample_rate', 0.0);
    app()->forgetInstance(Xstrm\Xstrm\Config::class);
    app()->forgetInstance(PerformanceCollector::class);

    recordTxn();
    expect(txn())->toBeNull();

    config()->set('xstrm.performance.sample_rate', 1.0);
    app()->forgetInstance(Xstrm\Xstrm\Config::class);
    app()->forgetInstance(PerformanceCollector::class);

    recordTxn();
    expect(txn())->not->toBeNull();
});

it('decides sampling once per request, not once per event', function () {
    // Rolling separately per event would let one request report its
    // transaction but not its errors, which makes trace ids useless.
    config()->set('xstrm.performance.sample_rate', 0.5);
    app()->forgetInstance(Xstrm\Xstrm\Config::class);
    app()->forgetInstance(PerformanceCollector::class);

    $collector = perf();
    $first = $collector->isSampled();

    foreach (range(1, 50) as $ignored) {
        expect($collector->isSampled())->toBe($first);
    }
});

it('clears its counters on reset, for Octane', function () {
    $metrics = app(Metrics::class);
    $metrics->query(10.0, 100);
    $metrics->cacheHit();
    $metrics->http(5.0);

    perf()->reset();

    expect($metrics->dbCount)->toBe(0)
        ->and($metrics->cacheHits)->toBe(0)
        ->and($metrics->httpCount)->toBe(0)
        ->and($metrics->dbMs)->toBe(0.0);
});

it('never throws, whatever the response is', function () {
    expect(fn () => perf()->record(Request::create('/'), new Response))
        ->not->toThrow(Exception::class);
});
