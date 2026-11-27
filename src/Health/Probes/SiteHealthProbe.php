<?php

namespace Cinderwatch\WordPress\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;

/**
 * Reports the critical and recommended counts WordPress caches from its own
 * Site Health screen in the `health-check-site-status-result` transient.
 */
class SiteHealthProbe implements Probe
{
    public function id(): string
    {
        return 'site-health';
    }

    public function title(): string
    {
        return 'Site Health';
    }

    public function examine(): Observation
    {
        $cached = get_transient('health-check-site-status-result');

        if (! is_string($cached) || $cached === '') {
            return Observation::notApplicable('Site Health has not been run yet; visit Tools > Site Health.');
        }

        $counts = json_decode($cached, true);

        if (! is_array($counts)) {
            return Observation::notApplicable('Site Health results could not be read.');
        }

        $critical = (int) ($counts['critical'] ?? 0);
        $recommended = (int) ($counts['recommended'] ?? 0);
        $facts = ['critical' => $critical, 'recommended' => $recommended];

        if ($critical > 0) {
            return Observation::unhealthy("{$critical} critical", "Site Health reports {$critical} critical issue(s).", $facts);
        }

        return Observation::healthy($recommended > 0 ? "{$recommended} recommended" : 'Good', $facts);
    }
}
