<?php

/**
 * Plugin Name:       Cinder
 * Plugin URI:        https://cinderwatch.dev
 * Description:       Error, log, trace, health and schedule monitoring for Cinder.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Rhys Lees Ltd
 * License:           MIT
 * Text Domain:       cinder
 */
if (! defined('ABSPATH')) {
    exit;
}

if (! defined('SAVEQUERIES')) {
    $cinderTraceEnabled = ! defined('CINDER_TRACING_ENABLED')
        || filter_var(constant('CINDER_TRACING_ENABLED'), FILTER_VALIDATE_BOOL);

    if ($cinderTraceEnabled) {
        define('SAVEQUERIES', true);
    }
}

define('CINDER_PLUGIN_FILE', __FILE__);
define('CINDER_PLUGIN_DIR', __DIR__);

foreach ([
    __DIR__.'/vendor-prefixed/autoload.php',
    __DIR__.'/vendor/autoload.php',
    dirname(__DIR__, 2).'/vendor/autoload.php',
] as $autoload) {
    if (is_string($autoload) && file_exists($autoload)) {
        require_once $autoload;
    }
}

use Cinderwatch\WordPress\Plugin;

add_action('plugins_loaded', function (): void {
    Plugin::instance()->boot();
}, 0);

register_activation_hook(__FILE__, function (): void {
    Plugin::instance()->activate();
});

register_deactivation_hook(__FILE__, function (): void {
    Plugin::instance()->deactivate();
});
