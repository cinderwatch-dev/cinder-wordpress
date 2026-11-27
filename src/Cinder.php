<?php

namespace Cinderwatch\WordPress;

use Cinderwatch\CinderClient;
use Cinderwatch\Health\Inspector;
use Cinderwatch\Health\Probe;
use Throwable;

class Cinder
{
    /**
     * @param  iterable<Probe>  $probes
     */
    public static function probes(iterable $probes): void
    {
        Plugin::instance()->manager()->probes($probes);
    }

    public static function client(): CinderClient
    {
        return Plugin::instance()->manager()->client();
    }

    public static function health(): Inspector
    {
        return Plugin::instance()->manager()->health();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>|null  $fingerprint
     * @return array<string, mixed>|null
     */
    public static function report(Throwable $throwable, array $context = [], ?bool $handled = null, ?array $fingerprint = null): ?array
    {
        return Plugin::instance()->manager()->report($throwable, $context, $handled, $fingerprint);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function log(string $message, string $level = 'info', array $context = []): void
    {
        $client = self::client();

        if ($client->config()->key === '') {
            return;
        }

        $client->logs()->write($message, $level, $context);
    }
}
