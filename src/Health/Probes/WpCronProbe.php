<?php

namespace Cinderwatch\WordPress\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;

/**
 * Looks for WP-Cron events that should have run already. The grace period
 * stops events that are merely due this minute being flagged.
 */
class WpCronProbe implements Probe
{
    public function __construct(
        private readonly int $graceSeconds = 600,
    ) {}

    public function id(): string
    {
        return 'wp-cron';
    }

    public function title(): string
    {
        return 'WP-Cron';
    }

    public function examine(): Observation
    {
        $externallyTriggered = defined('DISABLE_WP_CRON') && (bool) constant('DISABLE_WP_CRON');
        $cutoff = time() - $this->graceSeconds;
        $late = 0;
        $oldest = null;

        foreach (_get_cron_array() as $runAt => $hooks) {
            if ((int) $runAt > $cutoff) {
                continue;
            }

            $late += count($hooks);
            $oldest = $oldest === null ? (int) $runAt : min($oldest, (int) $runAt);
        }

        $facts = ['late_events' => $late, 'externally_triggered' => $externallyTriggered];

        if ($late > 0) {
            $minutes = intdiv(time() - (int) $oldest, 60);

            return Observation::degraded(
                "{$late} late",
                "{$late} WP-Cron hook(s) are overdue; the oldest by {$minutes} minute(s).".($externallyTriggered ? ' DISABLE_WP_CRON is set, so check the system cron that triggers wp-cron.php.' : ''),
                [...$facts, 'oldest_late_by_minutes' => $minutes],
            );
        }

        return Observation::healthy($externallyTriggered ? 'On time (system cron)' : 'On time', $facts);
    }
}
