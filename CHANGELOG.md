# Changelog

All notable changes to `cinderwatch/cinder-wordpress` are documented here.

## [1.0.0] - 2026-11-27

First stable release.

### Added

- Error reporting with WordPress context, including the plugin or theme found in the stack trace, and the `cinder_should_report` and `cinder_before_send` filters.
- Logging through the `cinder_log()` function and `cinder_log` action, with deprecation notices captured when the `cinder_capture_deprecation_logs` filter returns true.
- Request tracing with database queries (via `SAVEQUERIES`) and outgoing HTTP requests.
- A `/cinder/v1/health` endpoint with PHP runtime, disk, database, core, plugin and theme update, debug display, WP-Cron and Site Health probes, extendable with the `cinder_health_probes` filter.
- WP-Cron monitoring with started, succeeded and failed heartbeats for every recurring hook.
- A must-use loader that turns on `SAVEQUERIES` before other plugins load.
- The deployed commit is detected from the site's `.git` folder or the host's variables, with `CINDER_VCS_*` as overrides.
- A settings page under **Settings > Cinder**, with `wp-config.php` constants taking priority.
- The `wp cinder test`, `health`, `schedule_sync` and `install_loader` commands.
