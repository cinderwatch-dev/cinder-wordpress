<?php

use Brain\Monkey\Functions;
use Cinderwatch\ScheduleMonitor\HeartbeatEvent;
use Cinderwatch\WordPress\ScheduleMonitor\WpCronMonitor;
use Cinderwatch\WordPress\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->settings(['key' => 'project-key', 'base_url' => 'https://cinder.test']);

    Functions\when('wp_get_schedules')->justReturn([
        'hourly' => ['interval' => 3600, 'display' => 'Once Hourly'],
        'twicedaily' => ['interval' => 43200, 'display' => 'Twice Daily'],
    ]);
    Functions\when('_get_cron_array')->justReturn([
        time() + 60 => [
            'wp_version_check' => ['abc' => ['schedule' => 'twicedaily', 'args' => [], 'interval' => 43200]],
            'send_invoice' => ['def' => ['schedule' => false, 'args' => [42]]],
        ],
        time() + 120 => [
            'wp_version_check' => ['ghi' => ['schedule' => 'hourly', 'args' => [], 'interval' => 3600]],
            'custom_every_ten' => ['jkl' => ['schedule' => 'every_ten', 'args' => [], 'interval' => 600]],
        ],
    ]);
});

function syncResponse(): string
{
    return json_encode(['tasks' => [
        ['key' => 'wp_version_check', 'id' => 'task-1', 'heartbeat_url' => 'https://cinder.test/api/v1/heartbeats/task-1'],
        ['key' => 'custom_every_ten', 'id' => 'task-2', 'heartbeat_url' => 'https://cinder.test/api/v1/heartbeats/task-2'],
    ]]);
}

it('needs a key and schedule monitoring switched on', function () {
    expect(WpCronMonitor::instance()->enabled())->toBeTrue();

    $this->defineConstants(['CINDER_SCHEDULE_MONITOR_ENABLED' => false]);
    expect(WpCronMonitor::instance()->enabled())->toBeFalse();

    $this->defineConstants(['CINDER_SCHEDULE_MONITOR_ENABLED' => true]);
    $this->settings([]);
    expect(WpCronMonitor::instance()->enabled())->toBeFalse();
})->group('schedule');

it('syncs recurring hooks as interval tasks', function () {
    $requests = $this->fakeHttp(200, syncResponse());

    $registered = WpCronMonitor::instance()->sync();

    expect($requests[0]['url'])->toBe('https://cinder.test/api/v1/schedule')
        ->and($requests[0]['args']['method'])->toBe('PUT')
        ->and(json_decode($requests[0]['args']['body'], true)['tasks'])->toBe([
            ['key' => 'wp_version_check', 'summary' => 'WP-Cron hook', 'cron' => null, 'interval_minutes' => 60, 'timezone' => 'UTC', 'grace_minutes' => 15],
            ['key' => 'custom_every_ten', 'summary' => 'WP-Cron hook', 'cron' => null, 'interval_minutes' => 60, 'timezone' => 'UTC', 'grace_minutes' => 15],
        ])
        ->and($registered)->toHaveCount(2)
        ->and($this->wpOptions['cinder_schedule_monitor_map'])->toBe(['wp_version_check' => 'task-1', 'custom_every_ten' => 'task-2'])
        ->and($this->wpOptions['cinder_schedule_monitor_hash'])->toBeString()
        ->and($this->hooks)->toHaveKeys(['wp_version_check', 'custom_every_ten']);
})->group('schedule');

it('does nothing while disabled', function () {
    $this->settings([]);
    $requests = $this->fakeHttp();
    $monitor = WpCronMonitor::instance();

    $monitor->register();
    $monitor->activate();
    $monitor->maybeSync();

    expect($monitor->sync())->toBe([])
        ->and($requests)->toHaveCount(0)
        ->and($this->hooks)->toBe([]);
})->group('schedule');

it('syncs again on init only when the hooks have changed', function () {
    $requests = $this->fakeHttp(200, syncResponse());
    $monitor = WpCronMonitor::instance();

    $monitor->register();
    $this->doAction('init');
    $this->doAction('init');

    expect($requests)->toHaveCount(1);

    $this->wpOptions['cinder_schedule_monitor_hash'] = 'stale';
    $monitor->maybeSync();

    expect($requests)->toHaveCount(2);
})->group('schedule');

it('sends started and succeeded heartbeats around each wp-cron run', function () {
    $this->wpOptions['cinder_schedule_monitor_map'] = ['wp_version_check' => 'task-1'];
    $this->defineConstants(['DOING_CRON' => true]);
    $requests = $this->fakeHttp();

    WpCronMonitor::instance()->register();
    $this->doAction('wp_version_check');

    $started = json_decode($requests[0]['args']['body'], true);
    $succeeded = json_decode($requests[1]['args']['body'], true);

    expect($requests[0]['url'])->toBe('https://cinder.test/api/v1/heartbeats/task-1')
        ->and($requests[0]['args']['headers'])->not->toHaveKey('Authorization')
        ->and($started)->toBe(['event' => 'started'])
        ->and($succeeded['event'])->toBe('succeeded')
        ->and($succeeded['exit_code'])->toBe(0)
        ->and($succeeded)->toHaveKeys(['duration_ms', 'memory_bytes']);
})->group('schedule');

it('registers the heartbeat callbacks once per hook', function () {
    $this->wpOptions['cinder_schedule_monitor_map'] = ['wp_version_check' => 'task-1'];
    $this->fakeHttp(200, json_encode(['tasks' => [['key' => 'wp_version_check', 'id' => 'task-1']]]));

    WpCronMonitor::instance()->register();
    WpCronMonitor::instance()->sync();

    expect($this->hooks['wp_version_check'])->toHaveCount(2);
})->group('schedule');

it('ignores hooks fired outside wp-cron', function () {
    $this->wpOptions['cinder_schedule_monitor_map'] = ['wp_version_check' => 'task-1'];
    $requests = $this->fakeHttp();

    WpCronMonitor::instance()->register();
    $this->doAction('wp_version_check');

    expect($requests)->toHaveCount(0);
})->group('schedule');

it('reports the running hook as failed', function () {
    $this->wpOptions['cinder_schedule_monitor_map'] = ['wp_version_check' => 'task-1'];
    $this->defineConstants(['DOING_CRON' => true]);
    $requests = $this->fakeHttp();
    $monitor = WpCronMonitor::instance();

    $monitor->register();
    $monitor->reportCurrentHookFailed('not running');

    expect($requests)->toHaveCount(0);

    $this->hooks['wp_version_check'][0]['callback']();
    $monitor->reportCurrentHookFailed(str_repeat('x', 1200));
    $monitor->reportCurrentHookFailed('already reported');

    $failed = json_decode($requests[1]['args']['body'], true);

    expect($requests)->toHaveCount(2)
        ->and($failed['event'])->toBe('failed')
        ->and($failed['exit_code'])->toBe(1)
        ->and(strlen($failed['error']))->toBe(1000);
})->group('schedule');

it('skips heartbeats for hooks cinder does not know', function () {
    $requests = $this->fakeHttp();

    WpCronMonitor::instance()->heartbeat('unknown_hook', HeartbeatEvent::Succeeded);

    expect($requests)->toHaveCount(0);
})->group('schedule');

it('ignores a stored hook map that is not an array', function () {
    $this->wpOptions['cinder_schedule_monitor_map'] = 'corrupt';

    WpCronMonitor::instance()->register();

    expect(array_keys($this->hooks))->toBe(['init']);
})->group('schedule');
