<?php

namespace Cinderwatch\WordPress;

/**
 * Works out which WordPress front door the current PHP process came through.
 */
class EntryPoint
{
    /**
     * One of `cli`, `cron`, `rest`, `ajax`, `admin` or `web`.
     */
    public static function type(): string
    {
        return match (true) {
            self::isCli() => 'cli',
            self::isCron() => 'cron',
            self::isRest() => 'rest',
            self::flag('DOING_AJAX') => 'ajax',
            is_admin() => 'admin',
            default => 'web',
        };
    }

    /**
     * The `cinder.entry.kind`: REST, AJAX and admin requests all count as `web`.
     */
    public static function kind(): string
    {
        return match (self::type()) {
            'cli' => 'cli',
            'cron' => 'cron',
            default => 'web',
        };
    }

    public static function isCli(): bool
    {
        return self::flag('WP_CLI');
    }

    public static function isCron(): bool
    {
        return self::flag('DOING_CRON');
    }

    public static function isRest(): bool
    {
        return self::flag('REST_REQUEST');
    }

    public static function requestUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? null;

        return is_string($uri) && $uri !== '' ? $uri : '/';
    }

    private static function flag(string $constant): bool
    {
        return defined($constant) && (bool) constant($constant);
    }
}
