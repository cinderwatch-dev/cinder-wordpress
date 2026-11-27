<?php

use Brain\Monkey\Functions;
use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Cinderwatch\WordPress\Cinder;
use Cinderwatch\WordPress\Cli\CinderCommand;
use Cinderwatch\WordPress\Health\SnapshotTransient;
use Cinderwatch\WordPress\Tests\TestCase;
use WP_CLI\ExitException;

uses(TestCase::class);

function probeReturning(Observation $observation, string $title = 'Probe'): Probe
{
    return new class($observation, $title) implements Probe
    {
        public function __construct(
            private readonly Observation $observation,
            private readonly string $title,
        ) {}

        public function id(): string
        {
            return strtolower($this->title);
        }

        public function title(): string
        {
            return $this->title;
        }

        public function examine(): Observation
        {
            return $this->observation;
        }
    };
}

it('registers the wp cinder command only inside wp-cli', function () {
    CinderCommand::register();

    expect(WP_CLI::$commands)->toBe([]);

    $this->defineConstants(['WP_CLI' => true]);
    CinderCommand::register();

    expect(WP_CLI::$commands)->toBe(['cinder' => CinderCommand::class]);
})->group('cli');

it('sends a test exception', function () {
    $transport = $this->fakeCinder();

    (new CinderCommand)->test();

    expect(WP_CLI::$output)->toBe([['success', 'Test exception sent to Cinder.']])
        ->and(json_encode($transport->sent[0]['payload']))->toContain('Cinder test exception from Test site');
})->group('cli');

it('refuses to send a test exception without a key', function () {
    $this->fakeCinder('');

    expect(fn () => (new CinderCommand)->test())->toThrow(ExitException::class)
        ->and(WP_CLI::$output)->toBe([['error', 'Cinder is not configured. Set CINDER_KEY.']]);
})->group('cli');

it('warns when a filter stops the test exception', function () {
    $this->fakeCinder();
    Cinder::client()->reportOnlyWhen(fn (): bool => false);

    (new CinderCommand)->test();

    expect(WP_CLI::$output)->toBe([['warning', 'The test exception was filtered and not sent.']]);
})->group('cli');

it('prints each health finding and stores the snapshot', function () {
    $this->fakeCinder();
    Cinder::probes([
        probeReturning(Observation::healthy('Connected'), 'Database'),
        probeReturning(Observation::degraded('2 pending', '2 plugin update(s) waiting.'), 'Plugins'),
        probeReturning(Observation::unhealthy('Shown to visitors', 'WP_DEBUG output is displayed.'), 'Debug'),
        probeReturning(Observation::notApplicable('Not run yet'), 'Site Health'),
    ]);

    (new CinderCommand)->health([], []);

    expect(WP_CLI::$output)->toBe([
        ['log', '[healthy] Database - Connected'],
        ['warning', '[degraded] Plugins - 2 pending (2 plugin update(s) waiting.)'],
        ['error', '[unhealthy] Debug - Shown to visitors (WP_DEBUG output is displayed.)'],
        ['warning', '[not applicable] Site Health - Not applicable (Not run yet)'],
    ])
        ->and((new SnapshotTransient)->load()?->findings)->toHaveCount(4);
})->group('cli');

it('exits non-zero in strict mode when a probe needs attention', function () {
    $this->fakeCinder();
    Cinder::probes([probeReturning(Observation::degraded('Late', 'An event is late.'))]);

    expect(fn () => (new CinderCommand)->health([], ['strict' => true]))->toThrow(new ExitException('', 1));
})->group('cli');

it('exits cleanly in strict mode when everything is healthy', function () {
    $this->fakeCinder();
    Cinder::probes([probeReturning(Observation::healthy('Fine'))]);

    (new CinderCommand)->health([], ['strict' => true]);

    expect(WP_CLI::$output)->toBe([['log', '[healthy] Probe - Fine']]);
})->group('cli');

it('syncs wp-cron hooks with cinder', function () {
    $this->settings(['key' => 'project-key']);
    $this->fakeHttp(200, json_encode(['tasks' => [['key' => 'wp_version_check', 'id' => 'task-1']]]));
    Functions\when('wp_get_schedules')->justReturn(['hourly' => ['interval' => 3600]]);
    Functions\when('_get_cron_array')->justReturn([time() => ['wp_version_check' => ['a' => ['schedule' => 'hourly']]]]);

    (new CinderCommand)->schedule_sync();

    expect(WP_CLI::$output)->toBe([['success', 'Synced 1 WP-Cron hooks with Cinder.']]);
})->group('cli');

it('refuses to sync wp-cron hooks without a key', function () {
    expect(fn () => (new CinderCommand)->schedule_sync())->toThrow(ExitException::class)
        ->and(WP_CLI::$output)->toBe([['error', 'Cinder schedule monitoring is not configured.']]);
})->group('cli');

it('installs the mu-plugin loader', function () {
    Functions\when('wp_mkdir_p')->alias(fn (string $directory): bool => mkdir($directory, 0777, true));

    (new CinderCommand)->install_loader();

    unlink(WPMU_PLUGIN_DIR.'/cinder-loader.php');
    rmdir(WPMU_PLUGIN_DIR);
    rmdir(dirname(WPMU_PLUGIN_DIR));

    expect(WP_CLI::$output)->toBe([['success', 'Cinder mu-plugin loader installed.']]);
})->group('cli');

it('reports when the mu-plugin loader cannot be installed', function () {
    Functions\when('wp_mkdir_p')->justReturn(false);

    expect(fn () => (new CinderCommand)->install_loader())->toThrow(ExitException::class)
        ->and(WP_CLI::$output)->toBe([['error', 'Could not install the mu-plugin loader.']]);
})->group('cli');
