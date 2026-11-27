<?php

namespace Cinderwatch\WordPress\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;

/**
 * Degrades when installed plugins have updates waiting.
 */
class PluginUpdatesProbe implements Probe
{
    public function id(): string
    {
        return 'wordpress-plugins';
    }

    public function title(): string
    {
        return 'Plugin updates';
    }

    public function examine(): Observation
    {
        UpdateApi::load();

        $slugs = array_map('strval', array_keys(get_plugin_updates()));

        if ($slugs === []) {
            return Observation::healthy('Up to date');
        }

        return Observation::degraded(
            count($slugs).' pending',
            count($slugs).' plugin update(s) waiting to be installed.',
            ['count' => count($slugs), 'plugins' => $slugs],
        );
    }
}
