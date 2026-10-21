<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;

class NotificationHistory
{
    /** @return array{completed:int,last_completed:?CarbonInterface} */
    public function forOccurrence(Incident $incident, int $destinationId, ?int $actionId, string $phase, string $receiverHash): array
    {
        $outbox = NotificationOutbox::where('incident_id', $incident->id)->where('episode_uuid', $incident->episode_uuid)
            ->where('destination_id', $destinationId)->where('policy_action_id', $actionId)
            ->where('phase', $phase)->where('receiver_hash', $receiverHash)->whereIn('status', ['sent', 'dry_run'])
            ->selectRaw('COUNT(*) completed, MAX(delivered_at) last_completed')->first();
        // New delivery logs describe attempts, not additional occurrences. Old
        // redacted receivers are unknowable: retain the old shared action budget
        // for each current receiver instead of inventing a recipient identity.
        $legacy = DeliveryLog::where('incident_id', $incident->id)->whereNull('notification_outbox_id')
            ->where('destination_id', $destinationId)->where('policy_action_id', $actionId)->where('phase', $phase)
            ->whereIn('status', ['sent', 'dry_run'])
            ->where(fn ($query) => $query->where('receiver_hash', $receiverHash)->orWhereNull('receiver_hash'))
            ->where('created_at', '>=', $incident->first_seen_at)
            ->where(fn ($query) => $query->where('episode_uuid', $incident->episode_uuid)->orWhereNull('episode_uuid'))
            ->selectRaw('COUNT(*) completed, MAX(COALESCE(sent_at, created_at)) last_completed')->first();
        $legacyDigest = $this->legacyDigestCompletion($incident, $phase);
        $timestamps = array_filter([$outbox?->getAttribute('last_completed'), $legacy?->getAttribute('last_completed'), $legacyDigest?->toDateTimeString()]);

        return [
            'completed' => (int) $outbox?->getAttribute('completed') + (int) $legacy?->getAttribute('completed') + ($legacyDigest === null ? 0 : 1),
            'last_completed' => $timestamps === [] ? null : CarbonImmutable::parse(max($timestamps)),
        ];
    }

    private function legacyDigestCompletion(Incident $incident, string $phase): ?CarbonInterface
    {
        $timestamp = $incident->context_json['trigger_notified_via_digest'] ?? null;
        if ($phase !== 'trigger' || ! is_string($timestamp) || $timestamp === '') {
            return null;
        }
        try {
            $completedAt = CarbonImmutable::parse($timestamp);
        } catch (\Throwable) {
            return null;
        }
        if (! $incident->first_seen_at || $completedAt->isBefore($incident->first_seen_at)) {
            return null;
        }
        // Modern membership preserves exact destination/receiver coverage. Its
        // episode flag must never become a global success that hides a failed
        // destination's individual fallback. Only pre-outbox digests lack links.
        $modern = DB::table('iapm_notification_outbox_incidents as membership')
            ->join('iapm_notification_outbox as outbox', 'outbox.id', '=', 'membership.notification_outbox_id')
            ->where('membership.incident_id', $incident->id)->where('membership.episode_uuid', $incident->episode_uuid)
            ->where('outbox.phase', 'digest')->exists();

        return $modern ? null : $completedAt;
    }
}
