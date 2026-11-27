<?php

namespace Cinderwatch\WordPress\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;

/**
 * Fails when PHP errors would be printed to visitors on a production site.
 */
class DebugDisplayProbe implements Probe
{
    public function id(): string
    {
        return 'debug-display';
    }

    public function title(): string
    {
        return 'Debug output';
    }

    public function examine(): Observation
    {
        $displaying = defined('WP_DEBUG') && constant('WP_DEBUG')
            && (! defined('WP_DEBUG_DISPLAY') || constant('WP_DEBUG_DISPLAY'));
        $environment = wp_get_environment_type();
        $facts = ['displaying_errors' => $displaying, 'environment' => $environment];

        if ($displaying && $environment === 'production') {
            return Observation::unhealthy('Shown to visitors', 'WP_DEBUG output is displayed on a production site.', $facts);
        }

        return Observation::healthy($displaying ? 'Shown (non-production)' : 'Hidden', $facts);
    }
}
