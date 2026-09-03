<?php

use Illuminate\Support\Facades\Http;
use Xstrm\Xstrm\Config;
use Xstrm\Xstrm\Xstrm;

beforeEach(function () {
    config()->set('xstrm.transport.mode', 'inline');
    Http::fake();
});

it('caps the buffer and counts what it dropped', function () {
    config()->set('xstrm.max_events_per_request', 3);

    $xstrm = app(Xstrm::class);

    foreach (range(1, 10) as $i) {
        $xstrm->record(['t' => 'pv', 'path' => "/{$i}"]);
    }

    expect($xstrm->events())->toHaveCount(3)
        ->and($xstrm->dropped())->toBe(7);
});

it('stamps one trace id across every event in a request', function () {
    $xstrm = app(Xstrm::class);

    $xstrm->record(['t' => 'pv', 'path' => '/a']);
    $xstrm->record(['t' => 'err', 'level' => 'error']);

    $traces = array_column($xstrm->events(), 'trace');

    expect($traces)->toHaveCount(2)
        ->and($traces[0])->toBe($traces[1])
        ->and($traces[0])->not->toBeEmpty();
});

it('records nothing when there is no dsn', function () {
    config()->set('xstrm.dsn', null);
    app()->forgetInstance(Config::class);
    app()->forgetInstance(Xstrm::class);

    $xstrm = app(Xstrm::class);
    $xstrm->record(['t' => 'pv', 'path' => '/a']);

    expect($xstrm->events())->toBeEmpty();
});

it('sends one envelope holding every event, then empties itself', function () {
    $xstrm = app(Xstrm::class);

    $xstrm->record(['t' => 'pv', 'path' => '/a']);
    $xstrm->record(['t' => 'pv', 'path' => '/b']);
    $xstrm->flush();

    expect($xstrm->events())->toBeEmpty();

    Http::assertSentCount(1);

    Http::assertSent(function ($request) {
        $envelope = json_decode(gzdecode($request->body()), true);

        return $envelope['v'] === 1
            && count($envelope['events']) === 2
            && $envelope['sdk']['name'] === 'php-laravel'
            && $request->hasHeader('Authorization', 'Bearer sk_live_test')
            && $request->hasHeader('Content-Encoding', 'gzip');
    });
});

it('sends nothing when there is nothing to send', function () {
    app(Xstrm::class)->flush();

    Http::assertNothingSent();
});

it('clears the buffer on reset, for Octane', function () {
    $xstrm = app(Xstrm::class);

    $xstrm->record(['t' => 'pv', 'path' => '/a']);
    $first = $xstrm->traceId();
    $xstrm->reset();

    expect($xstrm->events())->toBeEmpty()
        ->and($xstrm->dropped())->toBe(0)
        ->and($xstrm->traceId())->not->toBe($first);
});
