<?php

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\WordPress\Cinder;
use Cinderwatch\WordPress\Tests\TestCase;
use Cinderwatch\WordPress\Tracing\HttpCollector;

uses(TestCase::class);

beforeEach(function () {
    Brain\Monkey\Functions\when('wp_remote_retrieve_response_code')->alias(fn (array $response): int => $response['response']['code']);
});

it('records outgoing wordpress http requests as spans', function () {
    $transport = $this->fakeCinder(CinderConfig::make('project-key')->withBaseUrl('https://cinder.test'));
    $root = Cinder::client()->tracer()->open('Request - /');

    (new HttpCollector)->register();

    $url = 'https://api.stripe.com/v1/charges';
    expect(apply_filters('pre_http_request', false, ['method' => 'post'], $url))->toBeFalse();
    do_action('http_api_debug', ['response' => ['code' => 201]], 'response', 'WpOrg\Requests\Requests', ['method' => 'post'], $url);
    do_action('http_api_debug', new WP_Error('http_request_failed', 'timeout'), 'response', 'WpOrg\Requests\Requests', [], 'https://api.example.test');
    do_action('http_api_debug', null, 'response', 'WpOrg\Requests\Requests', [], 'not a url');

    Cinder::client()->tracer()->close($root);
    $spans = $this->exportedSpans($transport);

    expect($spans[0])->toBe([
        'name' => 'HTTP - api.stripe.com',
        'parent' => $root->spanId,
        'attributes' => [
            'cinder.span.kind' => 'http_client',
            'url.full' => $url,
            'http.request.method' => 'POST',
            'http.response.status_code' => 201,
        ],
    ])
        ->and($spans[1]['name'])->toBe('HTTP - api.example.test')
        ->and($spans[1]['attributes']['http.request.method'])->toBe('GET')
        ->and($spans[1]['attributes']['http.response.status_code'])->toBe(503)
        ->and($spans[2]['name'])->toBe('HTTP - not a url')
        ->and($spans[2]['attributes']['http.response.status_code'])->toBe(0);
})->group('tracing');

it('ignores requests to cinder itself and other debug contexts', function () {
    $transport = $this->fakeCinder(CinderConfig::make('project-key')->withBaseUrl('https://cinder.test'));
    $root = Cinder::client()->tracer()->open('Request - /');
    $collector = new HttpCollector;

    $collector->onPreRequest(false, [], 'https://cinder.test/api/v1/errors');
    $collector->onDebug(['response' => ['code' => 202]], 'response', 'Requests', [], 'https://cinder.test/api/v1/errors');
    $collector->onDebug(['response' => ['code' => 200]], 'transports_list', 'Requests', [], 'https://example.test');

    Cinder::client()->tracer()->close($root);

    expect(array_column($this->exportedSpans($transport), 'name'))->toBe(['Request - /']);
})->group('tracing');

it('leaves short-circuited requests untimed', function () {
    $this->fakeCinder();
    $collector = new HttpCollector;
    $preempted = ['response' => ['code' => 200]];

    expect($collector->onPreRequest($preempted, [], 'https://example.test'))->toBe($preempted)
        ->and((new ReflectionProperty($collector, 'pending'))->getValue($collector))->toBe([]);
})->group('tracing');

it('does not listen for http requests when tracing is off', function () {
    $this->fakeCinder(new CinderConfig(key: 'project-key', reportTraces: false));

    (new HttpCollector)->register();

    expect($this->hasHook('pre_http_request'))->toBeFalse()
        ->and($this->hasHook('http_api_debug'))->toBeFalse();
})->group('tracing');
