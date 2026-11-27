<?php

use Brain\Monkey\Functions;
use Cinderwatch\Config\CinderConfig;
use Cinderwatch\WordPress\Http\WordPressHttpClient;
use Cinderwatch\WordPress\Tests\TestCase;
use Nyholm\Psr7\Request;

uses(TestCase::class);

it('sends psr requests through wp_remote_request', function () {
    $requests = $this->fakeHttp(202, '{"ok":true}');

    $client = new WordPressHttpClient(CinderConfig::make('key'));
    $response = $client->sendRequest(new Request('POST', 'https://cinder.test/api/v1/errors', ['Accept' => ['application/json', 'text/plain']], '{"a":1}'));

    expect($response->getStatusCode())->toBe(202)
        ->and((string) $response->getBody())->toBe('{"ok":true}')
        ->and($requests[0]['url'])->toBe('https://cinder.test/api/v1/errors')
        ->and($requests[0]['args'])->toMatchArray([
            'method' => 'POST',
            'body' => '{"a":1}',
            'redirection' => 0,
            'blocking' => true,
            'sslverify' => true,
        ])
        ->and($requests[0]['args']['headers']['Accept'])->toBe('application/json, text/plain');
})->group('http');

it('maps wordpress response headers onto the psr response', function () {
    Functions\when('wp_remote_request')->justReturn([]);
    Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    Functions\when('wp_remote_retrieve_body')->justReturn('');
    Functions\when('wp_remote_retrieve_headers')->justReturn([
        'content-type' => 'application/json',
        'set-cookie' => ['a=1', 'b=2'],
    ]);

    $response = (new WordPressHttpClient(CinderConfig::make('key')))->sendRequest(new Request('GET', 'https://cinder.test'));

    expect($response->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($response->getHeader('Set-Cookie'))->toBe(['a=1', 'b=2']);
})->group('http');

it('answers 503 when wordpress could not make the request', function () {
    Functions\when('wp_remote_request')->justReturn(new WP_Error('http_request_failed', 'cURL error 28'));

    $response = (new WordPressHttpClient(CinderConfig::make('key')))->sendRequest(new Request('GET', 'https://cinder.test'));

    expect($response->getStatusCode())->toBe(503)
        ->and((string) $response->getBody())->toBe('cURL error 28');
})->group('http');

it('answers 503 when the response has no status code', function () {
    $this->fakeHttp(0, '');

    $response = (new WordPressHttpClient(CinderConfig::make('key')))->sendRequest(new Request('GET', 'https://cinder.test'));

    expect($response->getStatusCode())->toBe(503);
})->group('http');

it('skips ssl verification on local sites', function () {
    $requests = $this->fakeHttp();
    Functions\when('wp_get_environment_type')->justReturn('local');

    (new WordPressHttpClient(CinderConfig::make('key')))->sendRequest(new Request('GET', 'https://cinder.test'));

    expect($requests[0]['args']['sslverify'])->toBeFalse();
})->group('http');

it('lets CINDER_SSL_VERIFY and the cinder_sslverify filter decide ssl verification', function () {
    $requests = $this->fakeHttp();
    $client = new WordPressHttpClient(CinderConfig::make('key'));

    $this->defineConstants(['CINDER_SSL_VERIFY' => false]);
    $client->sendRequest(new Request('GET', 'https://cinder.test'));

    add_filter('cinder_sslverify', fn (): bool => true);
    $client->sendRequest(new Request('GET', 'https://cinder.test'));

    expect($requests[0]['args']['sslverify'])->toBeFalse()
        ->and($requests[1]['args']['sslverify'])->toBeTrue();
})->group('http');
