<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Xstrm\Xstrm\Errors\ErrorCollector;
use Xstrm\Xstrm\Xstrm;

beforeEach(function () {
    config()->set('xstrm.transport.mode', 'inline');
    Http::fake();
});

function collector(): ErrorCollector
{
    return app(ErrorCollector::class);
}

function events(): array
{
    return app(Xstrm::class)->events();
}

it('captures an exception with its type, message and frames', function () {
    collector()->capture(new RuntimeException('the database is on fire'));

    $event = events()[0];

    expect($event['t'])->toBe('err')
        ->and($event['level'])->toBe('error')
        ->and($event['exception'][0]['type'])->toBe('RuntimeException')
        ->and($event['exception'][0]['value'])->toBe('the database is on fire')
        ->and($event['exception'][0]['frames'])->not->toBeEmpty()
        ->and($event['trace'])->not->toBeEmpty();
});

it('reports the same exception object only once, however many hooks see it', function () {
    // The three hooks overlap on purpose; the dedup is what stops that
    // becoming three copies of one error.
    $e = new RuntimeException('boom');

    collector()->capture($e);
    collector()->capture($e);
    collector()->capture($e, 'critical');

    expect(events())->toHaveCount(1);
});

it('still reports a second, separate exception of the same kind', function () {
    collector()->capture(new RuntimeException('boom'));
    collector()->capture(new RuntimeException('boom'));

    expect(events())->toHaveCount(2);
});

it('ignores the exceptions the config ignores', function (string $class) {
    $e = $class === ValidationException::class
        ? ValidationException::withMessages(['email' => 'invalid'])
        : new $class('nope');

    collector()->capture($e);

    expect(events())->toBeEmpty();
})->with([
    ValidationException::class,
    AuthenticationException::class,
    Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
]);

it('records nothing when errors are switched off', function () {
    config()->set('xstrm.errors.enabled', false);
    app()->forgetInstance(Xstrm\Xstrm\Config::class);
    app()->forgetInstance(ErrorCollector::class);

    collector()->capture(new RuntimeException('boom'));

    expect(events())->toBeEmpty();
});

it('keeps the whole cause chain, outermost first', function () {
    // A QueryException caused by a PDOException is one error with two links,
    // and the cause is usually the interesting half.
    $root = new PDOException('SQLSTATE[42S02]: no such table');
    $wrapper = new RuntimeException('could not load orders', 0, $root);

    collector()->capture($wrapper);

    $chain = events()[0]['exception'];

    expect($chain)->toHaveCount(2)
        ->and($chain[0]['type'])->toBe('RuntimeException')
        ->and($chain[1]['type'])->toBe('PDOException');
});

it('survives a cycle in the cause chain', function () {
    // getPrevious() is not guaranteed acyclic once anyone constructs
    // exceptions by hand, and a hang here would hang the customer's app.
    $a = new RuntimeException('a');
    $b = new RuntimeException('b', 0, $a);

    $property = new ReflectionProperty(Exception::class, 'previous');
    $property->setAccessible(true);
    $property->setValue($a, $b);

    collector()->capture($b);

    expect(events()[0]['exception'])->not->toBeEmpty();
});

it('flags in-app frames and gives only those source context', function () {
    collector()->capture(new RuntimeException('boom'));

    $frames = events()[0]['exception'][0]['frames'];

    foreach ($frames as $frame) {
        if (! ($frame['in_app'] ?? false)) {
            expect($frame)->not->toHaveKey('context');
        }
    }

    expect($frames)->not->toBeEmpty();
});

/** A real HTTP request, so the context path under test is the one production runs. */
function webRequest(array $headers = []): void
{
    $request = Illuminate\Http\Request::create('https://shop.test/orders', 'POST');

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    app()->instance('request', $request);
    app(Xstrm::class)->markWebRequest();
}

it('never sends the client ip in the request context', function () {
    // The error payload is the likeliest place for an address to leak, because
    // it carries every header.
    webRequest([
        'X-Forwarded-For' => '203.0.113.9',
        'X-Real-IP' => '203.0.113.9',
        'Forwarded' => 'for=203.0.113.9',
    ]);

    collector()->capture(new RuntimeException('boom'));

    expect(json_encode(events()[0]))->not->toContain('203.0.113.9');
});

it('filters secrets out of the headers it does send', function () {
    webRequest([
        'Authorization' => 'Bearer super-secret-token',
        'Cookie' => 'session=abc123',
        'X-Api-Token' => 'another-secret',
        'Accept' => 'text/html',
    ]);

    collector()->capture(new RuntimeException('boom'));

    $headers = events()[0]['context']['headers'] ?? [];

    expect(json_encode(events()[0]))
        ->not->toContain('super-secret-token')
        ->not->toContain('abc123')
        ->not->toContain('another-secret')
        ->and($headers['accept'] ?? null)->toBe('text/html')
        ->and($headers['authorization'] ?? null)->toBe('[filtered]');
});

it('records the request that broke, so the error is reproducible', function () {
    webRequest();

    collector()->capture(new RuntimeException('boom'));

    expect(events()[0]['context'])
        ->toMatchArray(['method' => 'POST', 'url' => 'https://shop.test/orders']);
});

it('says so plainly when there was no request at all', function () {
    // A console command and a queued job both still have a Request object.
    collector()->capture(new RuntimeException('boom'));

    expect(events()[0]['context'])->toBe(['runner' => 'console']);
});

it('sends no user unless the customer opted in', function () {
    webRequest();

    collector()->capture(new RuntimeException('boom'));

    expect(events()[0]['context'])->not->toHaveKey('user');
});

it('caps a very long exception message', function () {
    collector()->capture(new RuntimeException(str_repeat('x', 10_000)));

    expect(strlen(events()[0]['exception'][0]['value']))->toBeLessThanOrEqual(4000);
});

it('captures an exception logged through the log', function () {
    // Laravel's own exception handler reports through the log, so this hook
    // is what catches an uncaught exception in a real app.
    Log::error('something broke', ['exception' => new RuntimeException('via log')]);

    expect(events())->toHaveCount(1)
        ->and(events()[0]['exception'][0]['value'])->toBe('via log');
});

it('ignores log lines below error level', function () {
    Log::warning('just a warning', ['exception' => new RuntimeException('not sent')]);
    Log::info('fyi');

    expect(events())->toBeEmpty();
});
