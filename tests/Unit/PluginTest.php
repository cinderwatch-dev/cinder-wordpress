<?php

use Brain\Monkey\Functions;
use Cinderwatch\Health\Probe;
use Cinderwatch\Health\Probes\DiskUsageProbe;
use Cinderwatch\Health\Probes\PhpRuntimeProbe;
use Cinderwatch\WordPress\Cli\CinderCommand;
use Cinderwatch\WordPress\Plugin;
use Cinderwatch\WordPress\Tests\TestCase;

uses(TestCase::class);

it('boots without registering error handlers until a key is configured', function () {
    Plugin::instance()->boot();

    expect($this->phpHandlers)->toBe([])
        ->and($this->hasHook('init'))->toBeTrue()
        ->and($this->hasHook('shutdown'))->toBeTrue()
        ->and($this->hasHook('cinder_log'))->toBeTrue()
        ->and($this->hasHook('pre_http_request'))->toBeTrue()
        ->and($this->hasHook('parse_request'))->toBeFalse()
        ->and($this->hasHook('admin_init'))->toBeFalse()
        ->and(WP_CLI::$commands)->toBe([]);
})->group('plugin');

it('registers the error handlers once a key is configured', function () {
    $this->settings(['key' => 'project-key']);

    Plugin::instance()->boot();

    expect($this->phpHandlers)->toHaveKeys(['error', 'exception', 'shutdown']);
})->group('plugin');

it('does not register the error handlers when error reporting is off', function () {
    $this->settings(['key' => 'project-key', 'report_errors' => false]);

    Plugin::instance()->boot();

    expect($this->phpHandlers)->toBe([]);
})->group('plugin');

it('only boots once', function () {
    Plugin::instance()->boot();
    Plugin::instance()->boot();

    expect($this->hooks['cinder_log'])->toHaveCount(1);
})->group('plugin');

it('adds the settings page in the admin and the command in wp-cli', function () {
    Functions\when('is_admin')->justReturn(true);
    Functions\expect('add_options_page')->once();
    $this->defineConstants(['WP_CLI' => true]);

    Plugin::instance()->boot();

    expect($this->hasHook('admin_init'))->toBeTrue()
        ->and(WP_CLI::$commands)->toBe(['cinder' => CinderCommand::class]);
})->group('plugin');

it('applies the cinder_should_report and cinder_before_send filters to reports', function () {
    $this->settings(['key' => 'project-key', 'report_errors' => false]);
    $transport = $this->fakeCinder();

    add_filter('cinder_should_report', fn (bool $report, Throwable $throwable): bool => $throwable->getMessage() !== 'ignored', 10, 2);
    add_filter('cinder_before_send', fn (array $report): array => [...$report, 'filtered' => true]);

    Plugin::instance()->boot();
    $client = Plugin::instance()->manager()->client();

    expect($client->report(new RuntimeException('ignored')))->toBeNull()
        ->and($client->report(new RuntimeException('sent')))->toHaveKey('filtered', true)
        ->and($transport->sent)->toHaveCount(1);
})->group('plugin');

it('keeps the report when cinder_before_send does not return an array', function () {
    $this->fakeCinder();
    add_filter('cinder_before_send', fn (): string => 'nope');

    Plugin::instance()->boot();

    expect(Plugin::instance()->manager()->client()->report(new RuntimeException('boom')))->toBeArray();
})->group('plugin');

it('flushes everything queued when wordpress shuts down', function () {
    $transport = $this->fakeCinder();
    Plugin::instance()->boot();

    cinder_log('Order placed');

    expect($transport->sent)->toBe([]);

    $this->doAction('shutdown');

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['url'])->toEndWith('/api/v1/logs');
})->group('plugin');

it('builds the client from the wordpress configuration', function () {
    $this->settings(['key' => 'project-key', 'base_url' => 'https://cinder.test']);

    $manager = Plugin::instance()->manager();

    expect($manager)->toBe(Plugin::instance()->manager())
        ->and($manager->client()->config()->key)->toBe('project-key')
        ->and($manager->client()->config()->baseUrl)->toBe('https://cinder.test');
})->group('plugin');

it('registers the built-in health probes', function () {
    $ids = array_map(fn (Probe $probe): string => $probe->id(), Plugin::instance()->manager()->health()->probes());

    expect($ids)->toBe([
        (new PhpRuntimeProbe)->id(),
        (new DiskUsageProbe(path: ABSPATH))->id(),
        'database',
        'wordpress-core',
        'wordpress-plugins',
        'wordpress-themes',
        'debug-display',
        'wp-cron',
        'site-health',
    ]);
})->group('plugin');

it('lets the cinder_health_probes filter change the probes', function () {
    add_filter('cinder_health_probes', fn (array $probes): array => [$probes[0], 'not a probe']);

    expect(Plugin::instance()->manager()->health()->probes())->toHaveCount(1);
})->group('plugin');

it('keeps the built-in probes when cinder_health_probes does not return an array', function () {
    add_filter('cinder_health_probes', fn (): null => null);

    expect(Plugin::instance()->manager()->health()->probes())->toHaveCount(9);
})->group('plugin');

it('syncs wp-cron hooks on activation and leaves everything alone on deactivation', function () {
    $requests = $this->fakeHttp(200, '{"tasks":[]}');
    $this->settings(['key' => 'project-key']);
    Functions\when('_get_cron_array')->justReturn([]);
    Functions\when('wp_get_schedules')->justReturn([]);

    Plugin::instance()->activate();
    Plugin::instance()->deactivate();

    expect($requests)->toHaveCount(1)
        ->and($requests[0]['args']['method'])->toBe('PUT');
})->group('plugin');
