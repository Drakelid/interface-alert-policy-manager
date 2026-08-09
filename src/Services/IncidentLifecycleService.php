<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;

class IncidentLifecycleService
{
    public function __construct(private readonly OutageRecorder $outages) {}

    public function beginEpisode(Incident $incident, array $context): array
    {
        foreach (['trigger_notified_via_digest', 'digest_queued_at', 'flap_notified', 'up_seen_at', 'last_reconciled_down_at'] as $key) {
            unset($context[$key]);
        }
        $context['observation_count'] = 1;

        return [
            'episode_uuid' => (string) Str::uuid(),
            'first_seen_at' => now(),
            'triggered_at' => null,
            'recovered_at' => null,
            'acknowledged_at' => null,
            'acknowledged_by' => null,
            'pre_acknowledgement_state' => null,
            'notification_count' => 0,
            'last_notification_at' => null,
            'suppression_reason' => null,
            // A still-active operator mute is intentional across a quick recurrence;
            // expired episode-local mute data is never carried into the new outage.
            'muted_until' => $incident->muted_until?->isFuture() ? $incident->muted_until : null,
            'context_json' => $context,
        ];
    }

    public function acknowledge(Incident $incident, ?int $actorId = null): void
    {
        if ($incident->state === IncidentState::Recovered) {
            throw new \DomainException('A recovered incident cannot be acknowledged.');
        }
        if ($incident->state === IncidentState::Acknowledged) {
            return;
        }
        $prior = $incident->state;
        $incident->update(['state' => IncidentState::Acknowledged, 'pre_acknowledgement_state' => $prior->value, 'acknowledged_at' => now(), 'acknowledged_by' => $actorId]);
        $incident->events()->create(['event_type' => 'acknowledged', 'event_message' => $actorId ? 'Incident acknowledged.' : 'Acknowledged upstream in LibreNMS.', 'actor_user_id' => $actorId, 'event_data' => ['previous_state' => $prior->value]]);
    }

    public function unacknowledge(Incident $incident, ?int $actorId = null): IncidentState
    {
        if ($incident->state !== IncidentState::Acknowledged) {
            throw new \DomainException('Only an acknowledged incident can be unacknowledged.');
        }
        $target = IncidentState::tryFrom((string) $incident->pre_acknowledgement_state) ?? ($incident->suppression_reason ? IncidentState::Suppressed : ($incident->triggered_at ? IncidentState::Active : IncidentState::Pending));
        if ($target === IncidentState::Recovered || $incident->recovered_at !== null) {
            throw new \DomainException('A recovered incident cannot be resurrected.');
        }
        $incident->update(['state' => $target, 'pre_acknowledgement_state' => null, 'acknowledged_at' => null, 'acknowledged_by' => null]);
        $incident->events()->create(['event_type' => 'unacknowledged', 'event_message' => 'Acknowledgement removed.', 'actor_user_id' => $actorId, 'event_data' => ['restored_state' => $target->value]]);

        return $target;
    }

    public function recover(Incident $incident, string $message): bool
    {
        return DB::transaction(function () use ($incident, $message): bool {
            $locked = Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if ($locked->state === IncidentState::Recovered) {
                return false;
            }
            $reason = $locked->suppression_reason;
            $locked->update(['state' => IncidentState::Recovered, 'recovered_at' => now(), 'last_seen_at' => now(), 'suppression_reason' => null]);
            $locked->events()->create(['event_type' => 'recovered', 'event_message' => $message]);
            $this->outages->record($locked->fresh(), $reason);
            $incident->setRawAttributes($locked->fresh()->getAttributes(), true);

            return true;
        });
    }
}
