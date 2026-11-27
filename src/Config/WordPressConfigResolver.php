<?php

namespace Cinderwatch\WordPress\Config;

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Support\DeployedRevision;
use Cinderwatch\WordPress\Plugin;

/**
 * Reads each setting from a wp-config.php constant, then the environment, then
 * the Settings > Cinder option, falling back to a default.
 */
class WordPressConfigResolver
{
    private const OPTION_KEY = 'cinder_settings';

    public static function resolve(): CinderConfig
    {
        $options = self::options();

        $revision = DeployedRevision::resolve(
            rtrim(ABSPATH, '/'),
            self::nullableString('CINDER_VCS_REVISION', 'vcs_revision', $options),
            self::nullableString('CINDER_VCS_BRANCH', 'vcs_branch', $options),
            self::nullableString('CINDER_VCS_MESSAGE', 'vcs_message', $options),
            self::bool('CINDER_VCS_DETECT', 'vcs_detect', $options, true),
        );

        return new CinderConfig(
            key: self::string('CINDER_KEY', 'key', $options, ''),
            baseUrl: self::string('CINDER_URL', 'base_url', $options, 'https://cinderwatch.dev'),
            serviceName: self::string('CINDER_SERVICE_NAME', 'service_name', $options, self::defaultServiceName()),
            serviceVersion: self::nullableString('CINDER_SERVICE_VERSION', 'service_version', $options),
            stage: self::nullableString('CINDER_STAGE', 'stage', $options) ?? wp_get_environment_type(),
            reportErrors: self::bool('CINDER_ERRORS_ENABLED', 'report_errors', $options, true),
            reportTraces: self::bool('CINDER_TRACING_ENABLED', 'report_traces', $options, true),
            reportLogs: self::bool('CINDER_LOGS_ENABLED', 'report_logs', $options, true),
            traceSampleRate: self::float('CINDER_TRACE_SAMPLE_RATE', 'trace_sample_rate', $options, 1.0),
            gzip: self::bool('CINDER_GZIP', 'gzip', $options, false),
            applicationPath: ABSPATH,
            sdkName: Plugin::SDK_NAME,
            sdkVersion: Plugin::SDK_VERSION,
            frameworkName: 'wordpress',
            frameworkVersion: self::wordpressVersion(),
            vcsRevision: $revision->revision,
            vcsBranch: $revision->branch,
            vcsMessage: $revision->message,
        );
    }

    public static function healthEndpointEnabled(): bool
    {
        return self::bool('CINDER_HEALTH_ENDPOINT_ENABLED', 'health_endpoint_enabled', self::options(), false);
    }

    public static function healthCheckSecret(): string
    {
        return self::string('CINDER_HEALTH_CHECK_SECRET', 'health_check_secret', self::options(), '');
    }

    public static function scheduleMonitorEnabled(): bool
    {
        return self::bool('CINDER_SCHEDULE_MONITOR_ENABLED', 'schedule_monitor_enabled', self::options(), true);
    }

    /**
     * How many minutes late a WP-Cron hook may run before Cinder flags it.
     */
    public static function cronGraceMinutes(): int
    {
        return max(0, (int) self::float('CINDER_CRON_GRACE_MINUTES', 'cron_grace_minutes', self::options(), 15));
    }

    /**
     * Whether a constant or environment variable overrides the settings page.
     */
    public static function isLocked(string $constantName): bool
    {
        return defined($constantName) || getenv($constantName) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function options(): array
    {
        $options = get_option(self::OPTION_KEY, []);

        return is_array($options) ? $options : [];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function string(string $constant, string $optionKey, array $options, string $default): string
    {
        if (defined($constant)) {
            $value = constant($constant);

            return is_scalar($value) ? (string) $value : $default;
        }

        $env = getenv($constant);

        if ($env !== false) {
            return $env;
        }

        $option = $options[$optionKey] ?? null;

        if (is_scalar($option) && (string) $option !== '') {
            return (string) $option;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function nullableString(string $constant, string $optionKey, array $options): ?string
    {
        $value = self::string($constant, $optionKey, $options, '');

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function bool(string $constant, string $optionKey, array $options, bool $default): bool
    {
        if (defined($constant)) {
            return (bool) constant($constant);
        }

        $env = getenv($constant);

        if ($env !== false) {
            return filter_var($env, FILTER_VALIDATE_BOOL);
        }

        if (array_key_exists($optionKey, $options)) {
            return (bool) $options[$optionKey];
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function float(string $constant, string $optionKey, array $options, float $default): float
    {
        if (defined($constant)) {
            return (float) constant($constant);
        }

        $env = getenv($constant);

        if ($env !== false) {
            return (float) $env;
        }

        if (isset($options[$optionKey]) && is_numeric($options[$optionKey])) {
            return (float) $options[$optionKey];
        }

        return $default;
    }

    private static function wordpressVersion(): ?string
    {
        $version = $GLOBALS['wp_version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    private static function defaultServiceName(): string
    {
        $name = get_bloginfo('name');

        return $name !== '' ? $name : 'WordPress site';
    }
}
