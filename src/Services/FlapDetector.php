<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;

/**
 * Detects flapping interfaces from the incident timeline. Each time a recovered
 * incident goes down again the ingestion path records a "reopened" event, so the
 * count of those events within a window is a reliable down/up cycle counter — no
 * changes to the ingestion or reconciliation hot paths are required.
 *
 * Flap dampening is opt-in per policy: it is inert unless both flap_threshold and
 * flap_window_seconds are set.
 */
class FlapDetector
{
    public function enabled(?Policy $policy): bool
    {
        return $policy !== null && $policy->flap_threshold > 0 && $policy->flap_window_seconds > 0;
    }

    public function isFlapping(Incident $incident, Policy $policy): bool
    {
        if (! $this->enabled($policy)) {
            return false;
        }

        return $incident->events()
            ->where('event_type', 'reopened')
            ->where('created_at', '>=', now()->subSeconds((int) $policy->flap_window_seconds))
            ->count() >= (int) $policy->flap_threshold;
    }

    /**
     * An interface has "settled" once no new down/up cycle has occurred for the
     * configured settle period (defaults to the flap window when unset).
     */
    public function settled(Incident $incident, Policy $policy): bool
    {
        $settle = (int) ($policy->flap_settle_seconds ?: $policy->flap_window_seconds);
        if ($settle <= 0) {
            return true;
        }

        $last = $incident->events()->where('event_type', 'reopened')->latest('created_at')->first();

        return $last === null || $last->created_at->addSeconds($settle)->isPast();
    }

    /** True while notifications should be dampened for this incident. */
    public function shouldDampen(Incident $incident, ?Policy $policy): bool
    {
        return $policy !== null && $this->isFlapping($incident, $policy) && ! $this->settled($incident, $policy);
    }
}
