<?php

use Cinderwatch\WordPress\Cinder;
use Cinderwatch\WordPress\Plugin;

if (! function_exists('cinder')) {
    function cinder(): Plugin
    {
        return Plugin::instance();
    }
}

if (! function_exists('cinder_log')) {
    /**
     * @param  array<string, mixed>  $context
     */
    function cinder_log(string $message, string $level = 'info', array $context = []): void
    {
        Cinder::log($message, $level, $context);
    }
}
