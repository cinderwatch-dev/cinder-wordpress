<?php

namespace Cinderwatch\WordPress\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;

/**
 * Degrades when installed themes have updates waiting.
 */
class ThemeUpdatesProbe implements Probe
{
    public function id(): string
    {
        return 'wordpress-themes';
    }

    public function title(): string
    {
        return 'Theme updates';
    }

    public function examine(): Observation
    {
        UpdateApi::load();

        $slugs = array_map('strval', array_keys(get_theme_updates()));

        if ($slugs === []) {
            return Observation::healthy('Up to date');
        }

        return Observation::degraded(
            count($slugs).' pending',
            count($slugs).' theme update(s) waiting to be installed.',
            ['count' => count($slugs), 'themes' => $slugs],
        );
    }
}
