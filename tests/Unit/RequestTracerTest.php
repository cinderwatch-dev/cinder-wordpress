<?php

use Brain\Monkey\Functions;
use Cinderwatch\Config\CinderConfig;
use Cinderwatch\WordPress\Tests\Fixtures\WpCliCommand;
use Cinderwatch\WordPress\Tests\TestCase;
use Cinderwatch\WordPress\Tracing\RequestTracer;

uses(TestCase::class);

function traceRequest(): void
{
    $tracer = new RequestTracer;

    $tracer->register();
    do_action('init');
    do_action('shutdown');
}

it('traces a web request as its root span', function () {
    $transport = $this->fakeCinder();
    $_SERVER['REQUEST_URI'] = '/shop/cart/?coupon=x';
    $_SERVER['REQUEST_METHOD'] = 'post';

    traceRequest();

    expect($this->exportedSpans($transport))->toBe([[
        'name' => 'Request - /shop/cart',
        'parent' => null,
        'attributes' => [
            'cinder.span.kind' => 'route',
            'cinder.entry.kind' => 'web',
            'cinder.entry.name' => '/shop/cart/',
            'wordpress.request_type' => 'web',
            'http.request.method' => 'POST',
            'url.full' => 'https://example.test/shop/cart/?coupon=x',
            'http.response.status_code' => 200,
        ],
    ]]);
})->group('tracing');

it('records the response status code', function () {
    $transport = $this->fakeCinder();
    Functions\when('Cinderwatch\WordPress\Tracing\http_response_code')->justReturn(500);

    traceRequest();

    expect($this->exportedSpans($transport)[0]['attributes']['http.response.status_code'])->toBe(500);
})->group('tracing');

it('names the span after the matched rest route', function () {
    $transport = $this->fakeCinder();
    $this->defineConstants(['REST_REQUEST' => true]);
    $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts/12';
    $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => '/wp/v2/posts/(?P<id>[\d]+)']];

    traceRequest();

    $span = $this->exportedSpans($transport)[0];

    expect($span['name'])->toBe('Request - /wp/v2/posts/(?P<id>[\d]+)')
        ->and($span['attributes']['cinder.entry.route'])->toBe('/wp/v2/posts/(?P<id>[\d]+)')
        ->and($span['attributes']['wordpress.request_type'])->toBe('rest');
})->group('tracing');

it('falls back to the path for rest requests without a route', function () {
    $transport = $this->fakeCinder();
    $this->defineConstants(['REST_REQUEST' => true]);
    $_SERVER['REQUEST_URI'] = '/wp-json/';

    traceRequest();

    expect($this->exportedSpans($transport)[0]['name'])->toBe('Request - /wp-json');
})->group('tracing');

it('names wp-cron and admin requests', function () {
    $transport = $this->fakeCinder();
    $tracer = new RequestTracer;

    $this->defineConstants(['DOING_CRON' => true]);
    $tracer->start();
    $tracer->finish();

    $this->defineConstants(['DOING_CRON' => false]);
    Functions\when('is_admin')->justReturn(true);
    $tracer->start();
    $tracer->finish();

    $_GET['page'] = 'woocommerce';
    $tracer->start();
    $tracer->finish();

    expect(array_column($this->exportedSpans($transport), 'name'))->toBe([
        'Request - wp-cron',
        'Request - admin',
        'Request - woocommerce',
    ]);
})->group('tracing');

it('names requests for the site root', function (?string $uri) {
    $transport = $this->fakeCinder();
    $_SERVER['REQUEST_URI'] = $uri;

    traceRequest();

    expect($this->exportedSpans($transport)[0]['name'])->toBe('Request - /');
})->with([
    'root' => ['/'],
    'no uri' => [null],
    'query only' => ['?p=1'],
])->group('tracing');

it('traces wp-cli commands by their command path only', function () {
    $transport = $this->fakeCinder();
    $this->defineConstants(['WP_CLI' => true]);
    $GLOBALS['argv'] = ['wp', '--path=/var/www', 'option', 'get', 'siteurl', '--format=json'];
    WP_CLI::$rootCommand = new WpCliCommand([
        'option' => new WpCliCommand(['get' => new WpCliCommand]),
    ]);

    traceRequest();

    expect($this->exportedSpans($transport))->toBe([[
        'name' => 'Command - wp option get',
        'parent' => null,
        'attributes' => [
            'cinder.span.kind' => 'command',
            'cinder.entry.kind' => 'cli',
            'cinder.entry.name' => 'wp option get',
            'cinder.entry.handler' => 'wp option get',
            'process.command' => 'wp option get',
        ],
    ]]);
})->group('tracing');

it('traces wp-cli without arguments as wp', function () {
    $transport = $this->fakeCinder();
    $this->defineConstants(['WP_CLI' => true]);
    unset($GLOBALS['argv']);

    traceRequest();

    expect($this->exportedSpans($transport)[0]['name'])->toBe('Command - wp');
})->group('tracing');

it('does not trace when tracing is switched off', function () {
    $transport = $this->fakeCinder(new CinderConfig(key: 'project-key', reportTraces: false));

    traceRequest();
    (new RequestTracer)->finish();

    expect($this->hasHook('init'))->toBeFalse()
        ->and($this->exportedSpans($transport))->toBe([]);
})->group('tracing');
