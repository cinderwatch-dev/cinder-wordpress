<?php

use Cinderwatch\WordPress\Tests\Fixtures\WpCliCommand;
use WP_CLI\ExitException;

/**
 * Records what commands print. Halting throws, as WP-CLI does with `capture_exit` on.
 */
class WP_CLI
{
    /** @var array<string, string> */
    public static array $commands = [];

    /** @var list<array{0: string, 1: string}> */
    public static array $output = [];

    public static ?WpCliCommand $rootCommand = null;

    public static function reset(): void
    {
        self::$commands = [];
        self::$output = [];
        self::$rootCommand = null;
    }

    public static function add_command(string $name, string $class): bool
    {
        self::$commands[$name] = $class;

        return true;
    }

    public static function get_root_command(): WpCliCommand
    {
        return self::$rootCommand ?? new WpCliCommand;
    }

    public static function log(string $message): void
    {
        self::$output[] = ['log', $message];
    }

    public static function success(string $message): void
    {
        self::$output[] = ['success', $message];
    }

    public static function warning(string $message): void
    {
        self::$output[] = ['warning', $message];
    }

    public static function error(string $message, bool $exit = true): void
    {
        self::$output[] = ['error', $message];

        if ($exit) {
            self::halt(1);
        }
    }

    public static function halt(int $code): never
    {
        throw new ExitException('', $code);
    }
}
