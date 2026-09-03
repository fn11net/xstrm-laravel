<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Xstrm\Xstrm\Config;
use Xstrm\Xstrm\Jobs\SendEnvelope;
use Xstrm\Xstrm\Transport;

beforeEach(function () {
    config()->set('xstrm.transport.mode', 'inline');
    Cache::clear();
});

$envelope = fn () => ['v' => 1, 'events' => [['t' => 'pv', 'path' => '/']]];

it('opens the circuit after three consecutive failures', function () use ($envelope) {
    Http::fake(['*' => Http::response('', 500)]);

    $transport = app(Transport::class);

    foreach (range(1, 3) as $ignored) {
        $transport->post($envelope());
    }

    Http::assertSentCount(3);
    expect(Cache::get('xstrm:cb:open'))->toBeTrue();

    // Fourth call must not reach the network at all.
    $transport->post($envelope());
    Http::assertSentCount(3);
});

it('closes the circuit again on a success', function () use ($envelope) {
    Http::fakeSequence()
        ->push('', 500)
        ->push('', 500)
        ->push(['accepted' => 1], 202);

    $transport = app(Transport::class);
    $transport->post($envelope());
    $transport->post($envelope());

    expect(Cache::get('xstrm:cb:failures'))->toBe(2);

    $transport->post($envelope());

    expect(Cache::get('xstrm:cb:failures'))->toBeNull()
        ->and(Cache::get('xstrm:cb:open'))->toBeNull();
});

it('treats a 4xx as a rejection, not an outage', function () use ($envelope) {
    // The ingest answered — retrying would not help and would only hammer it.
    Http::fake(['*' => Http::response(['error' => 'unknown key'], 401)]);

    $transport = app(Transport::class);

    foreach (range(1, 5) as $ignored) {
        $transport->post($envelope());
    }

    expect(Cache::get('xstrm:cb:open'))->toBeNull();
    Http::assertSentCount(5);
});

it('swallows a connection failure instead of raising it', function () use ($envelope) {
    Http::fake(fn () => throw new ConnectionException('could not connect'));

    expect(fn () => app(Transport::class)->post($envelope()))->not->toThrow(Exception::class);
    expect(Cache::get('xstrm:cb:failures'))->toBe(1);
});

it('sends nothing without a dsn', function () use ($envelope) {
    config()->set('xstrm.dsn', 'nonsense');
    app()->forgetInstance(Config::class);
    app()->forgetInstance(Transport::class);
    Http::fake();

    app(Transport::class)->post($envelope());

    Http::assertNothingSent();
});

it('returns the ingest response body so the package can read its quota', function () use ($envelope) {
    Http::fake(['*' => Http::response([
        'accepted' => 1,
        'quota' => ['used' => 100, 'limit' => 1000000],
    ], 202)]);

    expect(app(Transport::class)->post($envelope()))
        ->toMatchArray(['accepted' => 1]);
});

it('queues the envelope rather than posting when a real queue exists', function () use ($envelope) {
    config()->set('xstrm.transport.mode', 'auto');
    config()->set('queue.default', 'redis');
    Illuminate\Support\Facades\Queue::fake();
    Http::fake();

    app(Transport::class)->send($envelope());

    Illuminate\Support\Facades\Queue::assertPushed(SendEnvelope::class);
    Http::assertNothingSent();
});

it('posts inline when the queue is sync', function () use ($envelope) {
    config()->set('xstrm.transport.mode', 'auto');
    config()->set('queue.default', 'sync');
    Http::fake();

    app(Transport::class)->send($envelope());

    Http::assertSentCount(1);
});

it('does nothing at all in null mode', function () use ($envelope) {
    config()->set('xstrm.transport.mode', 'null');
    Http::fake();

    app(Transport::class)->send($envelope());

    Http::assertNothingSent();
});
