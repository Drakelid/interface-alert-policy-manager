<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;

class RecoveryActionScheduler
{
    public function __construct(private readonly NotificationHistory $history, private readonly ReceiverResolver $receivers) {}

    public function reschedulePolicy(int $policyId): void
    {
        Incident::where('policy_id', $policyId)->where('state', IncidentState::Recovered)
            ->update(['recovery_action_due_at' => now()]);
    }

    public function scheduleNext(Incident $snapshot): void
    {
        DB::transaction(function () use ($snapshot): void {
            $incident = Incident::with('policy.actions.destination')->whereKey($snapshot->id)->lockForUpdate()->first();
            if (! $incident || $incident->episode_uuid !== $snapshot->episode_uuid || $incident->state !== IncidentState::Recovered) {
                return;
            }
            $next = null;
            if ($incident->policy?->enabled && $incident->policy->notifications_enabled && $incident->policy->notify_recovery && $incident->recovered_at) {
                foreach ($incident->policy->actions->filter(fn ($action) => $action->enabled && $action->phase->value === 'recovery') as $action) {
                    if (! $action->destination?->enabled) {
                        $next = $this->earlier($next, now()->addMinutes(5));

                        continue;
                    }
                    $receivers = $this->receivers->forAction($action, incident: $incident);
                    if ($receivers === []) {
                        $next = $this->earlier($next, now()->addMinutes(5));
                    }
                    foreach ($receivers as $receiver) {
                        $hash = hash('sha256', $receiver);
                        $pending = NotificationOutbox::where('incident_id', $incident->id)->where('episode_uuid', $incident->episode_uuid)
                            ->where('policy_action_id', $action->id)->where('destination_id', $action->destination_id)
                            ->where('phase', 'recovery')->where('receiver_hash', $hash)
                            ->whereIn('status', ['pending', 'queued', 'processing', 'failed'])->latest('id')->first();
                        if ($pending) {
                            $due = in_array($pending->status, ['pending', 'failed'], true) && $pending->available_at?->isFuture()
                                ? $pending->available_at : now()->addMinute();
                        } else {
                            $stats = $this->history->forOccurrence($incident, $action->destination_id, $action->id, 'recovery', $hash);
                            $maximum = $action->maximum_sends ?? ($incident->policy->maximum_repeats === null ? null : 1 + $incident->policy->maximum_repeats);
                            if ($stats['completed'] > 0 && ($action->repeat_seconds === null || ($maximum !== null && $stats['completed'] >= $maximum))) {
                                continue;
                            }
                            $due = $stats['completed'] === 0
                                ? $incident->recovered_at->copy()->addSeconds((int) $action->delay_seconds)
                                : $stats['last_completed']?->copy()->addSeconds((int) $action->repeat_seconds);
                            // A due action that produced no outbox has a rendering
                            // or receiver configuration failure. Retry deliberately.
                            if (! $due || ! $due->isFuture()) {
                                $due = now()->addMinutes(5);
                            }
                        }
                        if ($incident->muted_until?->isFuture() && $incident->muted_until->isAfter($due)) {
                            $due = $incident->muted_until;
                        }
                        $next = $this->earlier($next, $due);
                    }
                }
            }
            Incident::whereKey($incident->id)->update(['recovery_action_due_at' => $next]);
        }, 3);
    }

    private function earlier(?CarbonInterface $current, CarbonInterface $candidate): CarbonInterface
    {
        return $current === null || $candidate->isBefore($current) ? $candidate : $current;
    }
}
