<img src="https://banners.beyondco.de/Cinder%20WordPress.png?theme=light&packageManager=composer+require&packageName=cinderwatch%2Fcinder-wordpress&pattern=circuitBoard&style=style_2&description=Errors%2C+logs%2C+traces%2C+health+and+schedule+monitoring+for+WordPress.&md=1&showWatermark=0&fontSize=150px&images=fire&widths=500&heights=500">

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cinderwatch/cinder-wordpress.svg?style=flat-square)](https://packagist.org/packages/cinderwatch/cinder-wordpress)
[![Total Downloads](https://img.shields.io/packagist/dt/cinderwatch/cinder-wordpress.svg?style=flat-square)](https://packagist.org/packages/cinderwatch/cinder-wordpress)
[![GitHub-Tests](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/run-tests.yml/badge.svg?branch=main)](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/run-tests.yml)
[![GitHub Code Style](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/fix-php-code-style-issues.yml/badge.svg?branch=main)](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/fix-php-code-style-issues.yml)
[![PHPStan](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/phpstan.yml/badge.svg)](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/phpstan.yml)

> **Develop in the [cinder-clients](https://github.com/cinderwatch-dev/cinder-clients) monorepo** (`packages/cinder-wordpress`). This GitHub repository is a read-only split mirror; do not commit here.

This package was developed to give you a quick start to sending errors, logs, performance traces, health checks and WP-Cron runs from a WordPress site to [Cinder](https://cinderwatch.dev).

## 📑 Table of Contents

- [What is Cinder?](#-what-is-cinder)
- [Requirements](#-requirements)
- [Installation](#️-installation)
  - [Composer and Bedrock](#composer-and-bedrock)
  - [Plugin Zip](#plugin-zip)
- [Configuration](#configuration)
  - [wp-config.php Constants](#wp-configphp-constants)
  - [Environment Variables](#environment-variables)
  - [Settings Page](#settings-page)
  - [Deployed Commit](#deployed-commit)
- [Error Reporting](#error-reporting)
  - [Reporting Manually](#reporting-manually)
  - [Filtering Reports](#filtering-reports)
  - [Grouping Reports](#grouping-reports)
  - [WordPress Context](#wordpress-context)
- [Logging](#logging)
  - [Deprecation Notices](#deprecation-notices)
- [Performance Tracing](#performance-tracing)
  - [Database Queries](#database-queries)
  - [Outgoing HTTP](#outgoing-http)
- [Health Checks](#health-checks)
  - [Health Endpoint](#health-endpoint)
  - [Probes](#probes)
  - [Changing the Probes](#changing-the-probes)
- [WP-Cron Monitoring](#wp-cron-monitoring)
- [Must-Use Loader](#must-use-loader)
- [WP-CLI Commands](#wp-cli-commands)
- [Hooks Reference](#hooks-reference)
- [Testing](#-testing)
- [Changelog](#-changelog)
- [Contributing](#️-contributing)
  - [Code Style](#code-style)
- [Security Vulnerabilities](#-security-vulnerabilities)
- [Credits](#-credits)
- [License](#-license)

## 💡 What is Cinder?

Cinder is an application monitoring service. It groups the errors your site throws, stores its logs, shows where time
goes in requests and commands, polls the health checks you define, and tells you when a scheduled task runs late or
fails.

This package is the WordPress plugin. It builds on [cinderwatch/cinder-php](https://github.com/cinderwatch-dev/cinder-php),
which holds the framework-agnostic client.

## 🛠 Requirements

| Package | PHP | WordPress |
|---------|-----|-----------|
| **v1.x** | `^8.1` | **6.4+** |

## ⚙️ Installation

### Composer and Bedrock

The package is a `wordpress-plugin` Composer package, so [composer/installers](https://github.com/composer/installers)
puts it in your plugins directory (`web/app/plugins/cinder-wordpress` on Bedrock):

```bash
composer require cinderwatch/cinder-wordpress
```

Then activate it:

```bash
wp plugin activate cinder-wordpress
```

### Plugin Zip

Every release has a `cinder.zip` attached with its dependencies bundled and prefixed, so they cannot clash with other
plugins that ship the same libraries. Download it from the
[releases page](https://github.com/cinderwatch-dev/cinder-clients/releases) and upload it under
**Plugins > Add New Plugin > Upload Plugin**, or install it with WP-CLI:

```bash
wp plugin install cinder.zip --activate
```

To build the zip yourself, run `packages/cinder-wordpress/bin/build-zip` in the monorepo. It writes
`packages/cinder-wordpress/build/cinder.zip`.

## Configuration

Every setting is read from a `wp-config.php` constant first, then an environment variable with the same name, then
the **Settings > Cinder** page. The only setting you must provide is the project key:

```php
define('CINDER_KEY', 'your-project-key');
```

Nothing is sent to Cinder until a key is set.

### wp-config.php Constants

```php
// The project key from Cinder. Required.
define('CINDER_KEY', 'your-project-key');

// Where reports are sent.
define('CINDER_URL', 'https://cinderwatch.dev');

// How the site is named in Cinder. Defaults to the site title.
define('CINDER_SERVICE_NAME', 'Shop');

// The version you deployed, if you track one.
define('CINDER_SERVICE_VERSION', '1.4.0');

// Defaults to wp_get_environment_type(), e.g. production or staging.
define('CINDER_STAGE', 'production');

// Switch each kind of reporting on or off. All default to true.
define('CINDER_ERRORS_ENABLED', true);
define('CINDER_LOGS_ENABLED', true);
define('CINDER_TRACING_ENABLED', true);

// Share of requests to trace, from 0.0 to 1.0. Defaults to 1.0.
define('CINDER_TRACE_SAMPLE_RATE', 0.25);

// Gzip request bodies sent to Cinder. Defaults to false.
define('CINDER_GZIP', false);

// The deployed commit is detected for you. Set these only to override it.
define('CINDER_VCS_REVISION', 'a1b2c3d');
define('CINDER_VCS_BRANCH', 'main');
define('CINDER_VCS_MESSAGE', 'Fix the checkout total');

// The health endpoint stays off until you enable it and set a secret.
define('CINDER_HEALTH_ENDPOINT_ENABLED', true);
define('CINDER_HEALTH_CHECK_SECRET', 'a-long-random-string');

// WP-Cron monitoring is on by default once a key is set.
define('CINDER_SCHEDULE_MONITOR_ENABLED', true);

// Minutes a WP-Cron hook may run late before Cinder flags it. Defaults to 15.
define('CINDER_CRON_GRACE_MINUTES', 15);

// Verify Cinder's TLS certificate. Defaults to true, or false when wp_get_environment_type() is local.
define('CINDER_SSL_VERIFY', true);
```

### Environment Variables

Every constant above except `CINDER_SSL_VERIFY` can come from an environment variable instead, which suits
Bedrock's `.env`:

```dotenv
CINDER_KEY=your-project-key
CINDER_TRACE_SAMPLE_RATE=0.25
CINDER_LOGS_ENABLED=false
```

Boolean variables are on for `1`, `true`, `on` and `yes`, and off for anything else.

### Settings Page

**Settings > Cinder** is available to users with the `manage_options` capability. It stores its values in the
`cinder_settings` option:

| Field | Option key | Overridden by |
|-------|------------|---------------|
| API key | `key` | `CINDER_KEY` |
| Base URL | `base_url` | `CINDER_URL` |
| Report errors | `report_errors` | `CINDER_ERRORS_ENABLED` |
| Report logs | `report_logs` | `CINDER_LOGS_ENABLED` |
| Report traces | `report_traces` | `CINDER_TRACING_ENABLED` |
| Health endpoint | `health_endpoint_enabled` | `CINDER_HEALTH_ENDPOINT_ENABLED` |
| Secret | `health_check_secret` | `CINDER_HEALTH_CHECK_SECRET` |

The API key, base URL and secret fields are disabled when a constant or environment variable already sets them. The
page also has a **Send test error** button and an **Install mu-plugin loader** button.

The remaining settings have no field. They are still read from the same option under `service_name`,
`service_version`, `stage`, `trace_sample_rate`, `gzip`, `vcs_detect`, `vcs_revision`, `vcs_branch`, `vcs_message`,
`schedule_monitor_enabled` and `cron_grace_minutes`, but constants are the simpler way to set them.

### Deployed Commit

Every error, trace and log is tagged with the commit that is running. Cinder reads the commit, branch and message
from the `.git` folder of your site, starting at the WordPress folder and looking up to three folders above it, so
Bedrock-style projects with WordPress in `web/wp` work too. It reads the files directly, so it works on hosts that
disable `shell_exec`.

Without a `.git` folder the commit is taken from `GITHUB_SHA`, `VERCEL_GIT_COMMIT_SHA`, `RENDER_GIT_COMMIT` or
`SOURCE_VERSION` when your host sets one. Otherwise define `CINDER_VCS_REVISION` (and optionally the branch and
message) from your deploy. A defined revision always wins. Turn detection off with:

```php
define('CINDER_VCS_DETECT', false);
```

## Error Reporting

Once a key is set, Cinder registers PHP exception and error handlers when the plugin boots on `plugins_loaded`, and
looks for a fatal error at shutdown. Handlers that were registered before Cinder still run.

Warnings and notices are reported when `error_reporting()` includes them. Deprecations are never reported as errors,
see [Deprecation Notices](#deprecation-notices) to log them instead.

Send a test error from **Settings > Cinder** or with:

```bash
wp cinder test
```

### Reporting Manually

Report a caught exception with extra context, and optionally mark it as handled:

```php
use Cinderwatch\WordPress\Cinder;

try {
    $gateway->charge($order);
} catch (PaymentFailed $exception) {
    Cinder::report($exception, ['order_id' => $order->get_id()], handled: true);
}
```

`Cinder::report()` returns the payload that was sent, or `null` when the report was filtered out.

### Filtering Reports

Return `false` from `cinder_should_report` to drop a report, and use `cinder_before_send` to change the payload before
it leaves the site:

```php
add_filter('cinder_should_report', function (bool $report, Throwable $throwable): bool {
    return ! $throwable instanceof MyExpectedException;
}, 10, 2);

add_filter('cinder_before_send', function (array $report, Throwable $throwable): array {
    unset($report['context']['user.email']);

    return $report;
}, 10, 2);
```

A `cinder_before_send` callback that does not return an array is ignored.

### Grouping Reports

Cinder groups similar reports into one issue. Pass a fingerprint to choose the group for a single report:

```php
Cinder::report($exception, fingerprint: ['payment-gateway', 'timeout']);
```

Or decide the group for every report, returning `null` to keep the default:

```php
cinder()->manager()->fingerprintUsing(function (Throwable $throwable): ?array {
    return $throwable instanceof GatewayTimeout ? ['payment-gateway', 'timeout'] : null;
});
```

### WordPress Context

Every report is tagged with:

| Attribute | Value |
|-----------|-------|
| `wordpress.request_type` | `cli`, `cron`, `rest`, `ajax`, `admin` or `web` |
| `cinder.entry.kind` | `cli`, `cron` or `web` |
| `cinder.entry.name` | The request path, or `wp-cron` |
| `wordpress.theme` | The active theme's stylesheet |
| `wordpress.blog_id` | The current site, on multisite |
| `wordpress.plugin` | The plugin found in the stack trace, or failing that the theme |
| `http.request.method`, `url.full`, `url.path`, `user_agent.original` | The request, outside WP-CLI |
| `user.id`, `user.email` | The logged in user, outside WP-CLI |

When an error is reported during a WP-Cron run, the hook that was running also gets a failed heartbeat, see
[WP-Cron Monitoring](#wp-cron-monitoring).

## Logging

Logs are sent in one batch at the end of the request. Use the helper, the action or the class:

```php
cinder_log('Payment captured', 'info', ['order_id' => 42]);

do_action('cinder_log', 'Stock is running low', 'warning', ['sku' => 'TEE-M']);

Cinder::log('Webhook signature mismatch', 'error');
```

The level is one of `trace`, `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert` or `emergency`.
Context values under keys such as `password`, `token`, `secret` and `authorization` are redacted before they are
sent. Logs written during a request carry its trace id, so they show up next to the trace in Cinder.

### Deprecation Notices

To log `_doing_it_wrong()` and deprecated function notices as warnings, enable the `cinder_capture_deprecation_logs`
filter:

```php
add_filter('cinder_capture_deprecation_logs', '__return_true');
```

## Performance Tracing

Each request is traced from `init` to `shutdown`. The root span is named after what was served:

| Request | Span name |
|---------|-----------|
| Front end | `Request - /shop/cart` |
| REST API | `Request - /wp/v2/posts/(?P<id>[\d]+)` |
| Admin screen | `Request - woocommerce` (the `page` query argument, or `admin`) |
| WP-Cron | `Request - wp-cron` |
| WP-CLI | `Command - wp option get` |

WP-CLI spans only contain the registered command path, so arguments such as `wp eval` code or option values never
leave the site.

Set `CINDER_TRACE_SAMPLE_RATE` to trace a share of requests, or `CINDER_TRACING_ENABLED` to `false` to switch tracing
off.

### Database Queries

Queries are read from `$wpdb->queries` at shutdown and recorded as spans with their SQL, duration and calling
function. This needs `SAVEQUERIES`, so the plugin defines it as `true` when it loads, unless you have defined
`SAVEQUERIES` yourself or set the `CINDER_TRACING_ENABLED` constant to `false`. WordPress keeps every query in memory
while `SAVEQUERIES` is on.

### Outgoing HTTP

Requests made with the WordPress HTTP API (`wp_remote_get()`, `wp_remote_post()` and so on) are recorded as spans with
the URL, method and response status. Requests to Cinder itself are left out.

## Health Checks

### Health Endpoint

Enable the endpoint and set a secret:

```php
define('CINDER_HEALTH_ENDPOINT_ENABLED', true);
define('CINDER_HEALTH_CHECK_SECRET', 'a-long-random-string');
```

Then point a Cinder health monitor at `https://example.com/cinder/v1/health` with the secret as a bearer token. The
path works without pretty permalinks, and the same snapshot is served by the REST route `/wp-json/cinder/v1/health`.

```bash
curl -H "Authorization: Bearer a-long-random-string" https://example.com/cinder/v1/health
```

```json
{
    "generated_at": "2026-11-27T09:30:00.000000Z",
    "condition": "healthy",
    "probes": [
        {
            "id": "database",
            "title": "Database",
            "condition": "healthy",
            "summary": "Connected",
            "detail": "",
            "facts": {}
        }
    ]
}
```

Requests without the right secret get a 404, as does every request while no secret is set. A snapshot younger than
five minutes is served again instead of running the probes on every poll. The latest snapshot is kept in the
`cinder_health_snapshot` transient, which `wp cinder health` also refreshes.

### Probes

| Id | Checks |
|----|--------|
| `php-runtime` | The running PHP version |
| `disk-usage` | Disk usage where WordPress is installed. Degraded above 70%, unhealthy above 90% |
| `database` | `$wpdb` still has a live connection |
| `wordpress-core` | Whether a core upgrade is available |
| `wordpress-plugins` | Plugin updates waiting to be installed |
| `wordpress-themes` | Theme updates waiting to be installed |
| `debug-display` | `WP_DEBUG` output being shown to visitors on a production site |
| `wp-cron` | WP-Cron events more than 10 minutes overdue |
| `site-health` | Critical issues from **Tools > Site Health**, once it has been run |

A probe that throws is reported as errored instead of breaking the endpoint.

### Changing the Probes

The `cinder_health_probes` filter receives the list of probes and must return a list of `Cinderwatch\Health\Probe`
instances. Anything else in the list is dropped.

```php
use Cinderwatch\Health\AliasedProbe;
use Cinderwatch\Health\Probes\DiskUsageProbe;

add_filter('cinder_health_probes', function (array $probes): array {
    // Probes are keyed by id, so alias a second disk probe to keep the default one.
    $probes[] = new AliasedProbe(
        new DiskUsageProbe(path: '/var/www/uploads', warnAbovePercent: 80, failAbovePercent: 95),
        id: 'uploads-disk',
        title: 'Uploads disk',
    );

    return $probes;
});
```

Write your own probe by implementing `Cinderwatch\Health\Probe`:

```php
use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;

class StripeWebhookProbe implements Probe
{
    public function id(): string
    {
        return 'stripe-webhooks';
    }

    public function title(): string
    {
        return 'Stripe webhooks';
    }

    public function examine(): Observation
    {
        $failed = (int) get_option('shop_failed_webhooks', 0);

        return $failed > 0
            ? Observation::degraded("{$failed} failed", 'Stripe webhooks failed in the last hour.', ['failed' => $failed])
            : Observation::healthy('Delivering');
    }
}
```

To replace the probes completely, call `Cinder::probes([...])` after the plugin has loaded.

## WP-Cron Monitoring

Once a key is set, every recurring WP-Cron hook is registered with Cinder as a scheduled task. The task is keyed by the
hook name and uses the hook's shortest interval, the site timezone and the `CINDER_CRON_GRACE_MINUTES` grace period.
One-off events are skipped, and at most 500 hooks are tracked.

Hooks are synced when the plugin is activated, and again on `init` whenever the list of recurring hooks changes. Each
monitored hook then sends heartbeats as it runs from WP-Cron:

- `started` when the hook begins,
- `succeeded` with the duration, peak memory and exit code `0` when it finishes,
- `failed` with the error message (up to 1,000 characters) and exit code `1` when an error is reported while it runs.

Running a hook outside WP-Cron, for example with `do_action()`, sends nothing. WP-Cron only runs when the site gets
traffic, so for dependable timings set `DISABLE_WP_CRON` and trigger `wp-cron.php` from a system cron. The `wp-cron`
health probe tells you when events are overdue either way.

Sync by hand with `wp cinder schedule_sync`, or switch monitoring off with
`define('CINDER_SCHEDULE_MONITOR_ENABLED', false);`.

## Must-Use Loader

WordPress loads must-use plugins before regular ones. The loader is a small `cinder-loader.php` in your mu-plugins
directory that loads Cinder from there, so `SAVEQUERIES` is on before other plugins run their first queries and those
queries show up in traces. Install it from **Settings > Cinder** or with:

```bash
wp cinder install_loader
```

The loader points at the plugin's current path, so install it again if you move the plugin.

## WP-CLI Commands

| Command | Does |
|---------|------|
| `wp cinder test` | Sends a test exception |
| `wp cinder health [--strict]` | Runs the probes, prints each finding and stores the snapshot the endpoint serves. `--strict` exits with `1` when a probe is degraded, unhealthy or errored |
| `wp cinder schedule_sync` | Syncs the recurring WP-Cron hooks with Cinder |
| `wp cinder install_loader` | Installs the must-use loader |

`wp cinder health --strict` suits a deploy check or a system cron that alerts on a non-zero exit.

## Hooks Reference

| Hook | Type | Arguments | Use |
|------|------|-----------|-----|
| `cinder_should_report` | filter | `bool $report`, `Throwable $throwable` | Return `false` to drop an error report |
| `cinder_before_send` | filter | `array $report`, `Throwable $throwable` | Change an error report before it is sent |
| `cinder_health_probes` | filter | `list<Probe> $probes` | Add, remove or replace health probes |
| `cinder_capture_deprecation_logs` | filter | `bool $capture` | Log `_doing_it_wrong()` and deprecated function notices |
| `cinder_sslverify` | filter | `bool $verify` | Decide whether requests to Cinder verify TLS |
| `cinder_log` | action | `string $message`, `string $level`, `array $context` | Write a log |

## 🚧 Testing

Copy your own phpunit.xml-file.

```bash
cp phpunit.xml.dist phpunit.xml
```

Run the tests:

```bash
./vendor/bin/pest
```

The tests use [Brain Monkey](https://github.com/Brain-WP/BrainMonkey) and small WordPress and WP-CLI test doubles, so
they need no WordPress install.

## 📝 Changelog

Please see [CHANGELOG](CHANGELOG.md) for recent changes.

## ✏️ Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

```bash
composer test
```

### Code Style

```bash
./vendor/bin/pint
```

## 🧑‍💻 Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on reporting security vulnerabilities.

## 🙏 Credits

- [Rhys Lees](https://github.com/RhysLees)
- [All Contributors](../../contributors)
- [Brain Monkey by Giuseppe Mazzapica](https://github.com/Brain-WP/BrainMonkey)
- [Strauss by Brian Henry](https://github.com/BrianHenryIE/strauss)

## 🎭 License

The MIT License (MIT). Please have a look at [License File](LICENSE.md) for more information.
