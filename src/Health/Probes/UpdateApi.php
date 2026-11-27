<?php

namespace Cinderwatch\WordPress\Health\Probes;

/**
 * Loads wp-admin's update API, which is not available on front-end requests.
 *
 * @internal
 */
class UpdateApi
{
    public static function load(): void
    {
        if (! function_exists('get_core_updates')) {
            require_once ABSPATH.'wp-admin/includes/update.php';
        }
    }
}
