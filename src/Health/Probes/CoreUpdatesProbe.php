<?php

namespace Cinderwatch\WordPress\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;

/**
 * Degrades when WordPress has offered a core upgrade.
 */
class CoreUpdatesProbe implements Probe
{
    public function id(): string
    {
        return 'wordpress-core';
    }

    public function title(): string
    {
        return 'WordPress core';
    }

    public function examine(): Observation
    {
        UpdateApi::load();

        $offers = get_core_updates();
        $upgrades = array_values(array_filter(
            is_array($offers) ? $offers : [],
            static fn (mixed $offer): bool => is_object($offer) && ($offer->response ?? null) === 'upgrade',
        ));

        if ($upgrades === []) {
            return Observation::healthy('Up to date');
        }

        $latest = is_string($upgrades[0]->current ?? null) ? $upgrades[0]->current : null;

        return Observation::degraded(
            $latest !== null ? "{$latest} available" : 'Upgrade available',
            'A WordPress core upgrade is available.',
            ['available_version' => $latest],
        );
    }
}
