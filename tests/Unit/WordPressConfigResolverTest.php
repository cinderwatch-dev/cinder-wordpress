<?php

use Cinderwatch\Support\DeployedRevision;
use Cinderwatch\WordPress\Config\WordPressConfigResolver;
use Cinderwatch\WordPress\Plugin;
use Cinderwatch\WordPress\Tests\TestCase;

uses(TestCase::class);

it('uses sensible defaults when nothing is configured', function () {
    $config = WordPressConfigResolver::resolve();

    expect($config->key)->toBe('')
        ->and($config->baseUrl)->toBe('https://cinderwatch.dev')
        ->and($config->serviceName)->toBe('Test site')
        ->and($config->serviceVersion)->toBeNull()
        ->and($config->stage)->toBe('production')
        ->and($config->reportErrors)->toBeTrue()
        ->and($config->reportTraces)->toBeTrue()
        ->and($config->reportLogs)->toBeTrue()
        ->and($config->traceSampleRate)->toBe(1.0)
        ->and($config->gzip)->toBeFalse()
        ->and($config->applicationPath)->toBe(ABSPATH)
        ->and($config->sdkName)->toBe(Plugin::SDK_NAME)
        ->and($config->sdkVersion)->toBe(Plugin::SDK_VERSION)
        ->and($config->frameworkName)->toBe('wordpress')
        ->and($config->frameworkVersion)->toBeNull();
})->group('config');

it('reads every setting from the settings page', function () {
    $GLOBALS['wp_version'] = '6.8.1';
    $this->settings([
        'key' => 'option-key',
        'base_url' => 'https://cinder.test',
        'service_name' => 'Shop',
        'service_version' => '1.2.3',
        'stage' => 'staging',
        'report_errors' => '0',
        'report_traces' => '0',
        'report_logs' => '0',
        'trace_sample_rate' => '0.5',
        'gzip' => '1',
        'vcs_revision' => 'abc123',
        'vcs_branch' => 'main',
        'vcs_message' => 'Deploy',
    ]);

    $config = WordPressConfigResolver::resolve();

    expect($config->key)->toBe('option-key')
        ->and($config->baseUrl)->toBe('https://cinder.test')
        ->and($config->serviceName)->toBe('Shop')
        ->and($config->serviceVersion)->toBe('1.2.3')
        ->and($config->stage)->toBe('staging')
        ->and($config->reportErrors)->toBeFalse()
        ->and($config->reportTraces)->toBeFalse()
        ->and($config->reportLogs)->toBeFalse()
        ->and($config->traceSampleRate)->toBe(0.5)
        ->and($config->gzip)->toBeTrue()
        ->and($config->frameworkVersion)->toBe('6.8.1')
        ->and($config->vcsRevision)->toBe('abc123')
        ->and($config->vcsBranch)->toBe('main')
        ->and($config->vcsMessage)->toBe('Deploy');
})->group('config');

it('prefers environment variables over the settings page', function () {
    $this->settings(['key' => 'option-key', 'report_logs' => true, 'trace_sample_rate' => 1]);
    $this->setEnvironment([
        'CINDER_KEY' => 'environment-key',
        'CINDER_TRACING_ENABLED' => 'false',
        'CINDER_LOGS_ENABLED' => '0',
        'CINDER_TRACE_SAMPLE_RATE' => '0.25',
    ]);

    $config = WordPressConfigResolver::resolve();

    expect($config->key)->toBe('environment-key')
        ->and($config->reportTraces)->toBeFalse()
        ->and($config->reportLogs)->toBeFalse()
        ->and($config->traceSampleRate)->toBe(0.25);
})->group('config');

it('prefers wp-config constants over environment variables', function () {
    $this->setEnvironment(['CINDER_KEY' => 'environment-key', 'CINDER_GZIP' => 'false', 'CINDER_TRACE_SAMPLE_RATE' => '0.25']);
    $this->defineConstants(['CINDER_KEY' => 'constant-key', 'CINDER_GZIP' => true, 'CINDER_TRACE_SAMPLE_RATE' => 0.1]);

    $config = WordPressConfigResolver::resolve();

    expect($config->key)->toBe('constant-key')
        ->and($config->gzip)->toBeTrue()
        ->and($config->traceSampleRate)->toBe(0.1);
})->group('config');

it('ignores constants that are not scalar', function () {
    $this->defineConstants(['CINDER_URL' => ['https://cinder.test']]);

    expect(WordPressConfigResolver::resolve()->baseUrl)->toBe('https://cinderwatch.dev');
})->group('config');

it('falls back to a generic service name when the site has no title', function () {
    Brain\Monkey\Functions\when('get_bloginfo')->justReturn('');

    expect(WordPressConfigResolver::resolve()->serviceName)->toBe('WordPress site');
})->group('config');

it('takes the stage from the wordpress environment type', function () {
    Brain\Monkey\Functions\when('wp_get_environment_type')->justReturn('development');

    expect(WordPressConfigResolver::resolve()->stage)->toBe('development');
})->group('config');

it('ignores a settings option that is not an array', function () {
    $this->wpOptions['cinder_settings'] = 'corrupt';

    expect(WordPressConfigResolver::resolve()->key)->toBe('');
})->group('config');

it('keeps the health endpoint off until it is enabled', function () {
    expect(WordPressConfigResolver::healthEndpointEnabled())->toBeFalse()
        ->and(WordPressConfigResolver::healthCheckSecret())->toBe('');

    $this->settings(['health_endpoint_enabled' => '1', 'health_check_secret' => 'secret']);

    expect(WordPressConfigResolver::healthEndpointEnabled())->toBeTrue()
        ->and(WordPressConfigResolver::healthCheckSecret())->toBe('secret');
})->group('config');

it('monitors the schedule with a 15 minute grace period by default', function () {
    expect(WordPressConfigResolver::scheduleMonitorEnabled())->toBeTrue()
        ->and(WordPressConfigResolver::cronGraceMinutes())->toBe(15);

    $this->defineConstants(['CINDER_SCHEDULE_MONITOR_ENABLED' => false, 'CINDER_CRON_GRACE_MINUTES' => -5]);

    expect(WordPressConfigResolver::scheduleMonitorEnabled())->toBeFalse()
        ->and(WordPressConfigResolver::cronGraceMinutes())->toBe(0);
})->group('config');

it('knows when a setting is locked by a constant or environment variable', function () {
    $this->defineConstants(['CINDER_KEY' => 'constant-key']);
    $this->setEnvironment(['CINDER_URL' => 'https://cinder.test']);

    expect(WordPressConfigResolver::isLocked('CINDER_KEY'))->toBeTrue()
        ->and(WordPressConfigResolver::isLocked('CINDER_URL'))->toBeTrue()
        ->and(WordPressConfigResolver::isLocked('CINDER_HEALTH_CHECK_SECRET'))->toBeFalse();
})->group('config');

it('detects the deployed commit from the site folder unless turned off', function () {
    expect(WordPressConfigResolver::resolve()->vcsRevision)
        ->toBe(DeployedRevision::resolve(rtrim(ABSPATH, '/'))->revision);

    $this->defineConstants(['CINDER_VCS_DETECT' => false]);

    expect(WordPressConfigResolver::resolve()->vcsRevision)->toBeNull();
})->group('config');
