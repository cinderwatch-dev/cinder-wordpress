<?php

namespace Cinderwatch\WordPress\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;

/**
 * Verifies `$wpdb` still holds a live database connection.
 */
class DatabaseConnectionProbe implements Probe
{
    public function id(): string
    {
        return 'database';
    }

    public function title(): string
    {
        return 'Database';
    }

    public function examine(): Observation
    {
        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'check_connection')) {
            return Observation::unhealthy('Unavailable', 'The $wpdb database object has not been initialised.');
        }

        if ($wpdb->check_connection(false) === false) {
            return Observation::unhealthy('Unreachable', 'WordPress lost its database connection and could not reconnect.');
        }

        return Observation::healthy('Connected');
    }
}
