<?php

namespace Cinderwatch\WordPress\Cli;

use Cinderwatch\Health\Condition;
use Cinderwatch\Health\Finding;
use Cinderwatch\WordPress\Cinder;
use Cinderwatch\WordPress\EntryPoint;
use Cinderwatch\WordPress\Health\SnapshotTransient;
use Cinderwatch\WordPress\MuPlugin\MuPluginLoader;
use Cinderwatch\WordPress\ScheduleMonitor\WpCronMonitor;
use Exception;
use WP_CLI;

class CinderCommand
{
    public static function register(): void
    {
        if (! EntryPoint::isCli()) {
            return;
        }

        WP_CLI::add_command('cinder', self::class);
    }

    /**
     * Send a test exception to Cinder.
     */
    public function test(): void
    {
        if (Cinder::client()->config()->key === '') {
            WP_CLI::error('Cinder is not configured. Set CINDER_KEY.');
        }

        $report = Cinder::report(new Exception('Cinder test exception from '.get_bloginfo('name')));

        if ($report === null) {
            WP_CLI::warning('The test exception was filtered and not sent.');

            return;
        }

        WP_CLI::success('Test exception sent to Cinder.');
    }

    /**
     * Run the Cinder health probes and store the snapshot served by the endpoint.
     *
     * ## OPTIONS
     *
     * [--strict]
     * : Exit non-zero when any probe is degraded, unhealthy or errored.
     *
     * @param  array<int, string>  $args
     * @param  array<string, mixed>  $assocArgs
     */
    public function health(array $args, array $assocArgs): void
    {
        $snapshot = Cinder::health()->inspect();

        (new SnapshotTransient)->save($snapshot);

        foreach ($snapshot->findings as $finding) {
            self::printFinding($finding);
        }

        if (isset($assocArgs['strict']) && $snapshot->needsAttention()) {
            WP_CLI::halt(1);
        }
    }

    private static function printFinding(Finding $finding): void
    {
        $line = sprintf('[%s] %s - %s', $finding->condition()->describe(), $finding->probeTitle, $finding->observation->summary);

        if ($finding->observation->detail !== '' && $finding->condition() !== Condition::Healthy) {
            $line .= " ({$finding->observation->detail})";
        }

        match ($finding->condition()) {
            Condition::Healthy => WP_CLI::log($line),
            Condition::Degraded, Condition::NotApplicable => WP_CLI::warning($line),
            Condition::Unhealthy, Condition::Errored => WP_CLI::error($line, false),
        };
    }

    /**
     * Sync WP-Cron hooks with Cinder schedule monitoring.
     */
    public function schedule_sync(): void
    {
        $monitor = WpCronMonitor::instance();

        if (! $monitor->enabled()) {
            WP_CLI::error('Cinder schedule monitoring is not configured.');
        }

        $synced = $monitor->sync();

        WP_CLI::success(sprintf('Synced %d WP-Cron hooks with Cinder.', count($synced)));
    }

    /**
     * Install the must-use loader so queries from every plugin are traced.
     */
    public function install_loader(): void
    {
        if (MuPluginLoader::install()) {
            WP_CLI::success('Cinder mu-plugin loader installed.');

            return;
        }

        WP_CLI::error('Could not install the mu-plugin loader.');
    }
}
