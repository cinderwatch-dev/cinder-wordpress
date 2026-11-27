<?php

namespace Cinderwatch\WordPress\Logs;

use Cinderwatch\WordPress\Cinder;

class LogListener
{
    public function register(): void
    {
        add_action('cinder_log', $this->handleAction(...), 10, 3);

        if (apply_filters('cinder_capture_deprecation_logs', false)) {
            add_action('doing_it_wrong_run', $this->doingItWrong(...), 10, 3);
            add_action('deprecated_function_run', $this->deprecatedFunction(...), 10, 3);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function handleAction(string $message, string $level = 'info', array $context = []): void
    {
        Cinder::log($message, $level, $context);
    }

    public function doingItWrong(string $function, string $message, string $version): void
    {
        Cinder::log("doing_it_wrong: {$function} - {$message} (since {$version})", 'warning');
    }

    public function deprecatedFunction(string $function, string $replacement, string $version): void
    {
        $suffix = $replacement !== '' ? " use {$replacement}" : '';

        Cinder::log("deprecated_function: {$function}{$suffix} (since {$version})", 'warning');
    }
}
