<?php

use Xstrm\Xstrm\Dsn;

it('parses a well-formed dsn', function () {
    $dsn = Dsn::parse('https://sk_live_abc123@in.xstrm.net/17');

    expect($dsn)->not->toBeNull()
        ->and($dsn->endpoint)->toBe('https://in.xstrm.net')
        ->and($dsn->key)->toBe('sk_live_abc123')
        ->and($dsn->projectId)->toBe('17')
        ->and($dsn->url('/i/e'))->toBe('https://in.xstrm.net/i/e');
});

it('keeps a non-default port', function () {
    expect(Dsn::parse('http://sk_live_x@localhost:8000/3')->endpoint)
        ->toBe('http://localhost:8000');
});

// Every one of these must disable the package silently rather than throw (§4.3).
it('returns null for anything malformed', function (?string $input) {
    expect(Dsn::parse($input))->toBeNull();
})->with([
    'null' => null,
    'empty' => '',
    'whitespace' => '   ',
    'no scheme' => 'sk_live_abc@in.xstrm.net/17',
    'wrong scheme' => 'ftp://sk_live_abc@in.xstrm.net/17',
    'no key' => 'https://in.xstrm.net/17',
    'empty key' => 'https://@in.xstrm.net/17',
    'no project id' => 'https://sk_live_abc@in.xstrm.net',
    'empty project id' => 'https://sk_live_abc@in.xstrm.net/',
    'non-numeric project id' => 'https://sk_live_abc@in.xstrm.net/abc',
    'zero project id' => 'https://sk_live_abc@in.xstrm.net/0',
    'nested path' => 'https://sk_live_abc@in.xstrm.net/17/extra',
    'garbage' => 'not a url at all',
    'just a scheme' => 'https://',
]);
