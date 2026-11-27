<?php

use Brain\Monkey\Functions;
use Cinderwatch\Health\Finding;
use Cinderwatch\Health\HealthSnapshot;
use Cinderwatch\Health\Inspector;
use Cinderwatch\Health\Observation;
use Cinderwatch\WordPress\Health\HealthEndpoint;
use Cinderwatch\WordPress\Health\Probes\WpCronProbe;
use Cinderwatch\WordPress\Health\SnapshotTransient;
use Cinderwatch\WordPress\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Functions\when('_get_cron_array')->justReturn([]);
});

function healthEndpoint(): HealthEndpoint
{
    return new HealthEndpoint(fn (): Inspector => new Inspector([new WpCronProbe]));
}

it('stays unregistered until the endpoint is enabled', function () {
    healthEndpoint()->register();

    expect($this->hasHook('parse_request'))->toBeFalse()
        ->and($this->hasHook('rest_api_init'))->toBeFalse();

    $this->settings(['health_endpoint_enabled' => true]);
    healthEndpoint()->register();

    expect($this->hasHook('parse_request'))->toBeTrue()
        ->and($this->hasHook('rest_api_init'))->toBeTrue();
})->group('health');

it('only authorises requests carrying the secret as a bearer token', function () {
    $this->settings(['health_check_secret' => 'secret']);
    $endpoint = healthEndpoint();

    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    expect($endpoint->isAuthorised())->toBeFalse();

    $_SERVER['HTTP_CINDER_HEALTH_CHECK_SECRET'] = 'secret';
    expect($endpoint->isAuthorised())->toBeFalse();
    unset($_SERVER['HTTP_CINDER_HEALTH_CHECK_SECRET']);

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong';
    expect($endpoint->isAuthorised())->toBeFalse();

    $_SERVER['HTTP_AUTHORIZATION'] = 'secret';
    expect($endpoint->isAuthorised())->toBeFalse();

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';
    expect($endpoint->isAuthorised())->toBeTrue();
    unset($_SERVER['HTTP_AUTHORIZATION']);

    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer secret';
    expect($endpoint->isAuthorised())->toBeTrue();
})->group('health');

it('never authorises requests while no secret is set', function () {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';

    expect(healthEndpoint()->isAuthorised())->toBeFalse();
})->group('health');

it('serves the snapshot as json on the pretty path', function () {
    $this->settings(['health_check_secret' => 'secret']);
    $_SERVER['REQUEST_URI'] = '/cinder/v1/health/';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';
    Functions\expect('wp_send_json')->once()->with(Mockery::on(fn (array $payload): bool => $payload['probes'][0]['id'] === 'wp-cron'), 200);

    healthEndpoint()->interceptPrettyPath();
})->group('health');

it('answers 404 on the pretty path without the secret', function () {
    $_SERVER['REQUEST_URI'] = '/cinder/v1/health';
    Functions\expect('status_header')->once()->with(404)->andThrow(new RuntimeException('halted'));
    Functions\expect('wp_send_json')->never();

    healthEndpoint()->interceptPrettyPath();
})->throws(RuntimeException::class, 'halted')->group('health');

it('leaves other requests alone', function (?string $uri) {
    $_SERVER['REQUEST_URI'] = $uri;
    Functions\expect('status_header')->never();
    Functions\expect('wp_send_json')->never();

    healthEndpoint()->interceptPrettyPath();
})->with([
    'other path' => ['/shop'],
    'no request uri' => [null],
])->group('health');

it('registers a rest route guarded by the secret', function () {
    $this->settings(['health_check_secret' => 'secret']);
    $route = [];
    Functions\when('register_rest_route')->alias(function (string $namespace, string $path, array $args) use (&$route): void {
        $route = [$namespace, $path, $args];
    });

    healthEndpoint()->registerRestRoute();

    [$namespace, $path, $args] = $route;

    expect($namespace)->toBe('cinder/v1')
        ->and($path)->toBe('/health')
        ->and($args['methods'])->toBe('GET')
        ->and($args['permission_callback']())->toBeFalse();

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';
    $response = $args['callback']();

    expect($args['permission_callback']())->toBeTrue()
        ->and($response)->toBeInstanceOf(WP_REST_Response::class)
        ->and($response->status)->toBe(200)
        ->and($response->data['probes'][0]['id'])->toBe('wp-cron');
})->group('health');

it('serves the single-node health shape', function () {
    $payload = healthEndpoint()->payload();

    expect(array_keys($payload))->toBe(['generated_at', 'condition', 'probes'])
        ->and($payload['generated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/')
        ->and(array_keys($payload['probes'][0]))->toBe(['id', 'title', 'condition', 'summary', 'detail', 'facts']);
})->group('health');

it('reuses a recent stored snapshot and inspects again once it is stale', function () {
    $inspected = 0;
    $endpoint = new HealthEndpoint(function () use (&$inspected): Inspector {
        $inspected++;

        return new Inspector([new WpCronProbe]);
    });

    (new SnapshotTransient)->save(HealthSnapshot::at(time() - 30, [new Finding('stored', 'Stored', Observation::healthy('cached'))]));

    expect($endpoint->payload()['probes'][0]['id'])->toBe('stored')
        ->and($inspected)->toBe(0);

    (new SnapshotTransient)->save(HealthSnapshot::at(time() - 3600, [new Finding('stored', 'Stored', Observation::healthy('cached'))]));

    expect($endpoint->payload()['probes'][0]['id'])->toBe('wp-cron')
        ->and($inspected)->toBe(1)
        ->and((new SnapshotTransient)->load()?->findings[0]->probeId)->toBe('wp-cron');
})->group('health');

it('keeps only the latest snapshot in a single transient', function () {
    $store = new SnapshotTransient;

    $store->save(HealthSnapshot::at(100, [new Finding('a', 'A', Observation::healthy('first'))]));
    $store->save(HealthSnapshot::at(200, [new Finding('a', 'A', Observation::healthy('second'))]));

    expect(array_keys($this->transients))->toBe([SnapshotTransient::NAME])
        ->and($store->load()?->findings[0]->observation->summary)->toBe('second');
})->group('health');
