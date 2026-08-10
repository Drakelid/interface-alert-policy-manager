<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    /** @var array<int, array{count:int,last:?string}> */
    private array $primed = [];

    public function prime(Collection $incidents): void
    {
        $ids = $incidents->filter(fn (Incident $incident): bool => $this->enabled($incident->policy))->pluck('id');
        $this->primed = $ids->mapWithKeys(fn ($id) => [(int) $id => ['count' => 0, 'last' => null]])->all();
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('iapm_incident_events as ie')
            ->join('iapm_incidents as i', 'i.id', '=', 'ie.incident_id')
            ->join('iapm_policies as p', 'p.id', '=', 'i.policy_id')
            ->whereIn('ie.incident_id', $ids)->where('ie.event_type', 'reopened')
            ->where('ie.created_at', '>=', now()->subDay())
            ->selectRaw('ie.incident_id, SUM(CASE WHEN ie.created_at >= DATE_SUB(NOW(), INTERVAL p.flap_window_seconds SECOND) THEN 1 ELSE 0 END) flap_count, MAX(ie.created_at) last_reopen')
            ->groupBy('ie.incident_id')->get()->each(function ($row): void {
                $this->primed[(int) $row->incident_id] = ['count' => (int) $row->flap_count, 'last' => $row->last_reopen];
            });
    }

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
        if ($policy !== null && array_key_exists((int) $incident->id, $this->primed)) {
            $stats = $this->primed[(int) $incident->id];
            $settle = (int) ($policy->flap_settle_seconds ?: $policy->flap_window_seconds);

            return $this->enabled($policy)
                && $stats['count'] >= (int) $policy->flap_threshold
                && is_string($stats['last'])
                && CarbonImmutable::parse($stats['last'])->addSeconds($settle)->isFuture();
        }

        return $policy !== null && $this->isFlapping($incident, $policy) && ! $this->settled($incident, $policy);
    }
}
