<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Carbon\CarbonImmutable;
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

    public function recover(Incident $incident, string $message, array $attributes = []): bool
    {
        return DB::transaction(function () use ($incident, $message, $attributes): bool {
            $locked = Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if ($locked->state === IncidentState::Recovered) {
                return false;
            }
            $incomingSourceAt = $attributes['context_json']['last_source_event_at'] ?? null;
            $currentSourceAt = $locked->context_json['last_source_event_at'] ?? null;
            if (is_string($incomingSourceAt) && is_string($currentSourceAt) && CarbonImmutable::parse($incomingSourceAt)->lessThan(CarbonImmutable::parse($currentSourceAt))) {
                return false;
            }
            $reason = $locked->suppression_reason;
            $locked->update(array_merge($attributes, ['state' => IncidentState::Recovered, 'recovered_at' => now(), 'last_seen_at' => now(), 'suppression_reason' => null]));
            $locked->events()->create(['event_type' => 'recovered', 'event_message' => $message]);
            $this->outages->record($locked->fresh(), $reason);
            $incident->setRawAttributes($locked->fresh()->getAttributes(), true);

            return true;
        });
    }

    /**
     * Recover a chunk of incidents with the same lifecycle semantics as recover().
     *
     * Reconciliation can discover hundreds of deleted ports at once. Performing
     * the lock, event, outage, and update queries per incident creates thousands
     * of network round trips and can overrun the command cadence. This method
     * keeps the whole chunk atomic while retaining one event and one immutable
     * outage per episode.
     */
    public function recoverMany(iterable $incidents, string $message, ?string $sourceEventAt = null): int
    {
        $ids = collect($incidents)->pluck('id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($ids, $message, $sourceEventAt): int {
            $recoveredAt = now();
            $locked = Incident::query()
                ->whereIn('id', $ids)
                ->where('state', '!=', IncidentState::Recovered)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($sourceEventAt !== null) {
                $locked = $locked->reject(function (Incident $incident) use ($sourceEventAt): bool {
                    $current = $incident->context_json['last_source_event_at'] ?? null;

                    return is_string($current) && CarbonImmutable::parse($sourceEventAt)->lessThan(CarbonImmutable::parse($current));
                })->values();
            }
            if ($locked->isEmpty()) {
                return 0;
            }

            $flapping = DB::table('iapm_incident_events')
                ->whereIn('incident_id', $locked->pluck('id'))
                ->where('event_type', 'flapping')
                ->get(['incident_id', 'created_at'])
                ->groupBy('incident_id');
            $eventRows = [];
            $outageRows = [];
            foreach ($locked as $incident) {
                $episode = (string) ($incident->episode_uuid ?: throw new \LogicException('Cannot record an outage without an episode UUID.'));
                $firstSeen = $incident->first_seen_at ?? $recoveredAt;
                $wasFlapping = $flapping->get($incident->id, collect())->contains(
                    fn ($event): bool => $event->created_at >= $firstSeen->format('Y-m-d H:i:s')
                );
                $eventRows[] = [
                    'incident_id' => $incident->id,
                    'event_type' => 'recovered',
                    'event_message' => $message,
                    'event_data' => null,
                    'actor_user_id' => null,
                    'created_at' => $recoveredAt,
                    'updated_at' => $recoveredAt,
                ];
                $outageRows[] = [
                    'incident_id' => $incident->id,
                    'episode_uuid' => $episode,
                    'device_id' => $incident->device_id,
                    'port_id' => $incident->port_id,
                    'policy_id' => $incident->policy_id,
                    'severity' => $incident->severity->value,
                    'started_at' => $firstSeen,
                    'triggered_at' => $incident->triggered_at,
                    'recovered_at' => $recoveredAt,
                    'detect_seconds' => $incident->triggered_at ? max(0, $firstSeen->diffInSeconds($incident->triggered_at)) : null,
                    'duration_seconds' => max(0, $firstSeen->diffInSeconds($recoveredAt)),
                    'notification_count' => (int) $incident->notification_count,
                    'was_flapping' => $wasFlapping,
                    'suppression_reason' => $incident->suppression_reason,
                    'created_at' => $recoveredAt,
                    'updated_at' => $recoveredAt,
                ];
            }

            $incidentUpdate = [
                'state' => IncidentState::Recovered,
                'recovered_at' => $recoveredAt,
                'last_seen_at' => $recoveredAt,
                'suppression_reason' => null,
                'updated_at' => $recoveredAt,
            ];
            if ($sourceEventAt !== null) {
                $quoted = DB::connection()->getPdo()->quote($sourceEventAt) ?: "''";
                $incidentUpdate['context_json'] = DB::raw("JSON_SET(context_json, '$.last_source_event_at', {$quoted})");
            }
            Incident::query()->whereIn('id', $locked->pluck('id'))->update($incidentUpdate);
            DB::table('iapm_incident_events')->insert($eventRows);
            DB::table('iapm_outages')->insertOrIgnore($outageRows);

            return $locked->count();
        });
    }
}
