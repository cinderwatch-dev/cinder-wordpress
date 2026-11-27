<?php

use Brain\Monkey\Functions;
use Cinderwatch\Health\Condition;
use Cinderwatch\WordPress\Health\Probes\CoreUpdatesProbe;
use Cinderwatch\WordPress\Health\Probes\DatabaseConnectionProbe;
use Cinderwatch\WordPress\Health\Probes\DebugDisplayProbe;
use Cinderwatch\WordPress\Health\Probes\PluginUpdatesProbe;
use Cinderwatch\WordPress\Health\Probes\SiteHealthProbe;
use Cinderwatch\WordPress\Health\Probes\ThemeUpdatesProbe;
use Cinderwatch\WordPress\Health\Probes\UpdateApi;
use Cinderwatch\WordPress\Health\Probes\WpCronProbe;
use Cinderwatch\WordPress\Tests\TestCase;

uses(TestCase::class);

it('names every probe', function (string $class, string $id, string $title) {
    $probe = new $class;

    expect($probe->id())->toBe($id)
        ->and($probe->title())->toBe($title);
})->with([
    [CoreUpdatesProbe::class, 'wordpress-core', 'WordPress core'],
    [DatabaseConnectionProbe::class, 'database', 'Database'],
    [DebugDisplayProbe::class, 'debug-display', 'Debug output'],
    [PluginUpdatesProbe::class, 'wordpress-plugins', 'Plugin updates'],
    [SiteHealthProbe::class, 'site-health', 'Site Health'],
    [ThemeUpdatesProbe::class, 'wordpress-themes', 'Theme updates'],
    [WpCronProbe::class, 'wp-cron', 'WP-Cron'],
])->group('health');

it('loads the wp-admin update api when it is missing', function () {
    $this->withoutFunctions('get_core_updates');

    UpdateApi::load();

    expect(get_included_files())->toContain(ABSPATH.'wp-admin/includes/update.php');
})->group('health');

it('degrades when a core upgrade is offered', function () {
    Functions\when('get_core_updates')->justReturn([
        (object) ['response' => 'latest', 'current' => '6.8.1'],
        (object) ['response' => 'upgrade', 'current' => '6.9'],
    ]);

    $observation = (new CoreUpdatesProbe)->examine();

    expect($observation->condition)->toBe(Condition::Degraded)
        ->and($observation->summary)->toBe('6.9 available')
        ->and($observation->facts)->toBe(['available_version' => '6.9']);
})->group('health');

it('degrades without a version when the offer does not say which', function () {
    Functions\when('get_core_updates')->justReturn([(object) ['response' => 'upgrade']]);

    $observation = (new CoreUpdatesProbe)->examine();

    expect($observation->summary)->toBe('Upgrade available')
        ->and($observation->facts)->toBe(['available_version' => null]);
})->group('health');

it('is healthy when core is up to date', function (mixed $offers) {
    Functions\when('get_core_updates')->justReturn($offers);

    expect((new CoreUpdatesProbe)->examine()->condition)->toBe(Condition::Healthy);
})->with([
    'latest' => [[(object) ['response' => 'latest']]],
    'no offers' => [false],
])->group('health');

it('degrades when plugin or theme updates are pending', function (string $class, string $function, string $factKey) {
    Functions\when('get_core_updates')->justReturn([]);
    Functions\when($function)->justReturn(['akismet' => (object) [], 'hello' => (object) []]);

    $observation = (new $class)->examine();

    expect($observation->condition)->toBe(Condition::Degraded)
        ->and($observation->summary)->toBe('2 pending')
        ->and($observation->facts)->toBe(['count' => 2, $factKey => ['akismet', 'hello']]);
})->with([
    'plugins' => [PluginUpdatesProbe::class, 'get_plugin_updates', 'plugins'],
    'themes' => [ThemeUpdatesProbe::class, 'get_theme_updates', 'themes'],
])->group('health');

it('is healthy when plugins and themes are up to date', function (string $class, string $function) {
    Functions\when('get_core_updates')->justReturn([]);
    Functions\when($function)->justReturn([]);

    expect((new $class)->examine()->condition)->toBe(Condition::Healthy);
})->with([
    'plugins' => [PluginUpdatesProbe::class, 'get_plugin_updates'],
    'themes' => [ThemeUpdatesProbe::class, 'get_theme_updates'],
])->group('health');

it('checks the database connection', function () {
    expect((new DatabaseConnectionProbe)->examine()->summary)->toBe('Unavailable');

    $GLOBALS['wpdb'] = new class
    {
        public bool $connected = false;

        public function check_connection(bool $allowBail): bool
        {
            return $this->connected;
        }
    };

    expect((new DatabaseConnectionProbe)->examine())
        ->condition->toBe(Condition::Unhealthy)
        ->summary->toBe('Unreachable');

    $GLOBALS['wpdb']->connected = true;

    expect((new DatabaseConnectionProbe)->examine())
        ->condition->toBe(Condition::Healthy)
        ->summary->toBe('Connected');
})->group('health');

it('fails when debug output is shown on a production site', function () {
    $this->defineConstants(['WP_DEBUG' => true, 'WP_DEBUG_DISPLAY' => true]);

    $observation = (new DebugDisplayProbe)->examine();

    expect($observation->condition)->toBe(Condition::Unhealthy)
        ->and($observation->facts)->toBe(['displaying_errors' => true, 'environment' => 'production']);
})->group('health');

it('allows debug output outside production', function () {
    $this->defineConstants(['WP_DEBUG' => true]);
    Functions\when('wp_get_environment_type')->justReturn('staging');

    expect((new DebugDisplayProbe)->examine())
        ->condition->toBe(Condition::Healthy)
        ->summary->toBe('Shown (non-production)');
})->group('health');

it('is healthy while debug output is hidden', function (array $constants) {
    $this->defineConstants($constants);

    expect((new DebugDisplayProbe)->examine())
        ->condition->toBe(Condition::Healthy)
        ->summary->toBe('Hidden');
})->with([
    'debug off' => [[]],
    'display off' => [['WP_DEBUG' => true, 'WP_DEBUG_DISPLAY' => false]],
])->group('health');

it('degrades when wp-cron events are overdue beyond the grace period', function () {
    Functions\when('_get_cron_array')->justReturn([
        time() - 3600 => ['late_hook' => [], 'other_late_hook' => []],
        time() - 60 => ['due_now' => []],
        time() + 600 => ['future' => []],
    ]);

    $observation = (new WpCronProbe(graceSeconds: 600))->examine();

    expect($observation->condition)->toBe(Condition::Degraded)
        ->and($observation->facts)->toBe(['late_events' => 2, 'externally_triggered' => false, 'oldest_late_by_minutes' => 60])
        ->and($observation->detail)->not->toContain('DISABLE_WP_CRON');
})->group('health');

it('points at the system cron when DISABLE_WP_CRON is set', function () {
    $this->defineConstants(['DISABLE_WP_CRON' => true]);
    Functions\when('_get_cron_array')->justReturn([time() - 3600 => ['late_hook' => []]]);

    expect((new WpCronProbe)->examine()->detail)->toContain('DISABLE_WP_CRON is set');

    Functions\when('_get_cron_array')->justReturn([]);

    expect((new WpCronProbe)->examine()->summary)->toBe('On time (system cron)');
})->group('health');

it('is healthy when no wp-cron events are overdue', function () {
    Functions\when('_get_cron_array')->justReturn([time() + 60 => ['soon' => []]]);

    expect((new WpCronProbe)->examine())
        ->condition->toBe(Condition::Healthy)
        ->summary->toBe('On time');
})->group('health');

it('reads critical issues from the cached site health result', function () {
    $this->transients['health-check-site-status-result'] = json_encode(['good' => 10, 'recommended' => 2, 'critical' => 1]);

    $observation = (new SiteHealthProbe)->examine();

    expect($observation->condition)->toBe(Condition::Unhealthy)
        ->and($observation->summary)->toBe('1 critical')
        ->and($observation->facts)->toBe(['critical' => 1, 'recommended' => 2]);
})->group('health');

it('is healthy when site health has no critical issues', function (array $counts, string $summary) {
    $this->transients['health-check-site-status-result'] = json_encode($counts);

    expect((new SiteHealthProbe)->examine())
        ->condition->toBe(Condition::Healthy)
        ->summary->toBe($summary);
})->with([
    'recommendations' => [['good' => 10, 'recommended' => 3, 'critical' => 0], '3 recommended'],
    'all good' => [['good' => 10], 'Good'],
])->group('health');

it('treats site health as not applicable until it can be read', function (mixed $cached) {
    $this->transients['health-check-site-status-result'] = $cached;

    expect((new SiteHealthProbe)->examine()->condition)->toBe(Condition::NotApplicable);
})->with([
    'never run' => [false],
    'unreadable' => ['not json'],
])->group('health');
