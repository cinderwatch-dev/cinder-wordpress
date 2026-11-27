<?php

namespace Cinderwatch\WordPress;

use Cinderwatch\CinderClient;
use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Health\Inspector;
use Cinderwatch\Health\Probe;
use Cinderwatch\Health\Probes\DiskUsageProbe;
use Cinderwatch\Health\Probes\PhpRuntimeProbe;
use Cinderwatch\Transport\BufferedTransport;
use Cinderwatch\Transport\SyncTransport;
use Cinderwatch\WordPress\Admin\SettingsPage;
use Cinderwatch\WordPress\Cli\CinderCommand;
use Cinderwatch\WordPress\Config\WordPressConfigResolver;
use Cinderwatch\WordPress\Errors\ErrorHandler;
use Cinderwatch\WordPress\Errors\WordPressContextEnricher;
use Cinderwatch\WordPress\Health\HealthEndpoint;
use Cinderwatch\WordPress\Health\Probes\CoreUpdatesProbe;
use Cinderwatch\WordPress\Health\Probes\DatabaseConnectionProbe;
use Cinderwatch\WordPress\Health\Probes\DebugDisplayProbe;
use Cinderwatch\WordPress\Health\Probes\PluginUpdatesProbe;
use Cinderwatch\WordPress\Health\Probes\SiteHealthProbe;
use Cinderwatch\WordPress\Health\Probes\ThemeUpdatesProbe;
use Cinderwatch\WordPress\Health\Probes\WpCronProbe;
use Cinderwatch\WordPress\Http\WordPressHttpClient;
use Cinderwatch\WordPress\Logs\LogListener;
use Cinderwatch\WordPress\ScheduleMonitor\WpCronMonitor;
use Cinderwatch\WordPress\Tracing\HttpCollector;
use Cinderwatch\WordPress\Tracing\QueryCollector;
use Cinderwatch\WordPress\Tracing\RequestTracer;
use Throwable;

class Plugin
{
    /** Reported as `telemetry.sdk.name` / `sdk.name`. */
    public const SDK_NAME = 'cinderwatch/cinder-wordpress';

    /** Reported as `telemetry.sdk.version` / `sdk.version`. */
    public const SDK_VERSION = '1.0.0';

    private static ?self $instance = null;

    private ?CinderManager $manager = null;

    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public function manager(): CinderManager
    {
        return $this->manager ??= $this->buildManager();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $client = $this->manager()->client();

        $client->addEnricher(new WordPressContextEnricher);

        $client->reportOnlyWhen(function (Throwable $throwable): bool {
            return (bool) apply_filters('cinder_should_report', true, $throwable);
        });

        $client->beforeSend(function (array $report, Throwable $throwable): array {
            $filtered = apply_filters('cinder_before_send', $report, $throwable);

            return is_array($filtered) ? $filtered : $report;
        });

        if ($client->config()->key !== '' && $client->config()->reportErrors) {
            (new ErrorHandler)->register();
        }

        (new RequestTracer)->register();
        (new QueryCollector)->register();
        (new HttpCollector)->register();
        (new HealthEndpoint(fn (): Inspector => $this->manager()->health()))->register();
        (new LogListener)->register();
        WpCronMonitor::instance()->register();

        if (is_admin()) {
            (new SettingsPage)->register();
        }

        CinderCommand::register();

        add_action('shutdown', function (): void {
            Cinder::client()->flush();
        }, PHP_INT_MAX);
    }

    public function activate(): void
    {
        WpCronMonitor::instance()->activate();
    }

    public function deactivate(): void
    {
        // Nothing to undo: everything Cinder does is driven by configuration.
    }

    private function buildManager(): CinderManager
    {
        $config = WordPressConfigResolver::resolve();
        $client = $this->buildClient($config);

        return new CinderManager($client, new Inspector($this->healthProbes()));
    }

    private function buildClient(CinderConfig $config): CinderClient
    {
        $httpClient = new WordPressHttpClient($config);
        $transport = new BufferedTransport(new SyncTransport($config, $httpClient));

        return CinderClient::make($config, $transport);
    }

    /**
     * The `cinder_health_probes` filter receives and returns a list of probes.
     *
     * @return list<Probe>
     */
    private function healthProbes(): array
    {
        $probes = [
            new PhpRuntimeProbe,
            new DiskUsageProbe(path: ABSPATH),
            new DatabaseConnectionProbe,
            new CoreUpdatesProbe,
            new PluginUpdatesProbe,
            new ThemeUpdatesProbe,
            new DebugDisplayProbe,
            new WpCronProbe,
            new SiteHealthProbe,
        ];

        $filtered = apply_filters('cinder_health_probes', $probes);

        if (! is_array($filtered)) {
            return $probes;
        }

        return array_values(array_filter($filtered, fn (mixed $probe): bool => $probe instanceof Probe));
    }
}
