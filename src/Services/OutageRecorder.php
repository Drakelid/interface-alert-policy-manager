<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Outage;

/**
 * Writes an append-only outage record when an incident recovers. The incident
 * row is reused across outages (one per device+port), so per-outage history for
 * SLA/MTTR reporting needs its own immutable rows.
 */
class OutageRecorder
{
    public function record(Incident $incident, ?string $suppressionReason = null): Outage
    {
        $recoveredAt = $incident->recovered_at ?? now();
        $firstSeen = $incident->first_seen_at ?? $recoveredAt;

        $episode = (string) ($incident->episode_uuid ?: throw new \LogicException('Cannot record an outage without an episode UUID.'));
        try {
            return Outage::firstOrCreate(['incident_id' => $incident->id, 'episode_uuid' => $episode], [
                'incident_id' => $incident->id,
                'device_id' => $incident->device_id,
                'port_id' => $incident->port_id,
                'policy_id' => $incident->policy_id,
                'severity' => $incident->severity?->value ?? 'critical',
                'started_at' => $firstSeen,
                'triggered_at' => $incident->triggered_at,
                'recovered_at' => $recoveredAt,
                'detect_seconds' => $incident->triggered_at ? max(0, $firstSeen->diffInSeconds($incident->triggered_at)) : null,
                'duration_seconds' => max(0, $firstSeen->diffInSeconds($recoveredAt)),
                'notification_count' => (int) $incident->notification_count,
                'was_flapping' => $incident->events()->where('event_type', 'flapping')->where('created_at', '>=', $firstSeen)->exists(),
                'suppression_reason' => $suppressionReason ?? $incident->suppression_reason,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Outage::where('incident_id', $incident->id)->where('episode_uuid', $episode)->firstOrFail();
        } catch (\Throwable $exception) {
            Log::channel('iapm')->error('Failed to record outage.', ['incident_id' => $incident->id, 'episode_uuid' => $episode, 'error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
