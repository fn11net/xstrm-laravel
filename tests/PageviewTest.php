<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('xstrm.transport.mode', 'inline');
    Http::fake();

    Route::get('/products/{slug}', fn () => 'a product page')->name('products.show');
    Route::post('/orders', fn () => response()->json(['ok' => true]))->name('orders.store');
    Route::get('/orders.json', fn () => response()->json(['ok' => true]));
    Route::get('/boom', fn () => response('nope', 500));
    Route::get('/horizon/dashboard', fn () => 'horizon');
});

function pageviews(): Illuminate\Support\Collection
{
    return sentEvents()->where('t', 'pv');
}

it('records a pageview for an html GET', function () {
    $this->get('/products/hoodie');

    $pv = pageviews()->first();

    expect($pv)->not->toBeNull()
        ->and($pv['path'])->toBe('/products/hoodie')
        ->and($pv['route'])->toBe('products.show')
        // The ingest resolves country from this and discards it.
        ->and($pv)->toHaveKey('ip');
});

it('records the referrer so the ingest can take its host', function () {
    $this->get('/products/hoodie', ['referer' => 'https://google.com/search?q=hoodie']);

    expect(pageviews()->first()['ref'])->toBe('https://google.com/search?q=hoodie');
});

it('skips what is not a person reading a page', function (string $method, string $uri, array $headers) {
    $this->call($method, $uri, server: $headers);

    expect(pageviews())->toBeEmpty();
})->with([
    'a POST' => ['POST', '/orders', []],
    'a JSON response' => ['GET', '/orders.json', []],
    'an error response' => ['GET', '/boom', []],
    'an ignored path' => ['GET', '/horizon/dashboard', []],
    'a crawler' => ['GET', '/products/hoodie', ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']],
    'no user agent at all' => ['GET', '/products/hoodie', ['HTTP_USER_AGENT' => '']],
]);

it('records a pageview and a transaction under one trace id', function () {
    // Nothing consumes the trace id in v1. It exists so that when spans
    // arrive, every event from one request can be linked retroactively.
    $this->get('/products/hoodie');

    $traces = sentEvents()->pluck('trace')->unique();

    expect(sentEvents())->toHaveCount(2)
        ->and($traces)->toHaveCount(1);
});

it('sends one envelope for one request', function () {
    $this->get('/products/hoodie');

    // One monitored request is one ingest call, which is the unit the whole
    // plan is priced on (§11.2).
    expect(Http::recorded())->toHaveCount(1);
});

it('records nothing when analytics are switched off', function () {
    config()->set('xstrm.analytics.enabled', false);
    app()->forgetInstance(Xstrm\Xstrm\Config::class);

    $this->get('/products/hoodie');

    expect(pageviews())->toBeEmpty();
});
