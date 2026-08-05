<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use App\Models\Device;
use App\Models\Port;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\Severity;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Requests\IngestAlertRequest;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\DependencyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\StateNormalizer;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SuppressionService;

class IngestionController extends Controller
{
    public function __invoke(IngestAlertRequest $request, StateNormalizer $states, InterfaceContextService $contexts, PolicyResolver $policies, SuppressionService $suppression, DependencyResolver $dependencies): JsonResponse
    {
        $data = $request->validated(); $state = $states->normalize($data['state']);
        if ($state === 'recovered' && ! isset($data['alert_id']) && ! isset($data['alert_uid']) && ! isset($data['rule_id'])) return response()->json(['error' => ['code' => 'correlation_required', 'message' => 'Recovery payload requires alert_id, alert_uid, or rule_id.']], 422);
        $device = Device::find($data['device_id']);
        if (! $device) return response()->json(['error' => ['code' => 'device_not_found', 'message' => 'Referenced device does not exist.']], 422);
        $device->loadMissing('parents');
        $counts = array_fill_keys(['processed', 'activated', 'pending', 'suppressed', 'recovered', 'ignored', 'failed'], 0); $seen = [];
        foreach ($data['faults'] as $fault) {
            $port = Port::where('port_id', $fault['port_id'])->where('device_id', $device->device_id)->first();
            if (! $port) { $counts['failed']++; continue; }
            $seen[] = (int) $port->port_id; $context = $contexts->forPort($port); $resolution = $policies->resolve($context);
            $fingerprint = hash('sha256', implode('|', [(string) ($data['alert_uid'] ?? ''), (string) ($data['alert_id'] ?? ''), (string) ($data['timestamp'] ?? ''), $state, (string) $context->portId]));
            if (! $resolution->policy) {
                $this->runWithUniqueRetry(function () use ($data, $context, $fingerprint, &$counts): void {
                    $incident = Incident::where('incident_key', Incident::key($context->deviceId, $context->portId))->lockForUpdate()->first() ?? new Incident(['incident_key' => Incident::key($context->deviceId, $context->portId), 'first_seen_at' => now(), 'notification_count' => 0]);
                    if (($incident->context_json['last_event_fingerprint'] ?? null) === $fingerprint) { $counts['ignored']++; return; }
                    $reopening = $incident->exists && $incident->state === IncidentState::Recovered;
                    $contextData = (array) $context; $contextData['last_event_fingerprint'] = $fingerprint; $contextData['observation_count'] = 1;
                    $incident->fill(['source_alert_id' => $data['alert_id'] ?? null, 'source_alert_uid' => $data['alert_uid'] ?? null, 'source_rule_id' => $data['rule_id'] ?? null, 'device_id' => $context->deviceId, 'port_id' => $context->portId, 'policy_id' => null, 'state' => IncidentState::Suppressed, 'severity' => Severity::tryFrom($data['severity'] ?? '') ?? Severity::Critical, 'first_seen_at' => $reopening ? now() : $incident->first_seen_at, 'last_seen_at' => now(), 'recovered_at' => null, 'acknowledged_at' => null, 'acknowledged_by' => null, 'notification_count' => $reopening ? 0 : $incident->notification_count, 'last_notification_at' => $reopening ? null : $incident->last_notification_at, 'suppression_reason' => 'no_policy', 'context_json' => $contextData])->save();
                    if ($reopening) $incident->events()->create(['event_type' => 'reopened', 'event_message' => 'A new failure reopened the recovered interface incident without an effective policy.']);
                    $incident->events()->create(['event_type' => 'received', 'event_message' => 'Alert observation received.']); $incident->events()->create(['event_type' => 'suppressed', 'event_message' => 'No effective policy matched the interface.']); $counts['processed']++; $counts['suppressed']++;
                });
                continue;
            }
            $this->runWithUniqueRetry(function () use ($data, $state, $context, $resolution, $suppression, $dependencies, $device, $fingerprint, &$counts): void {
                $incident = Incident::where('incident_key', Incident::key($context->deviceId, $context->portId))->lockForUpdate()->first();
                if ($incident && ($incident->context_json['last_event_fingerprint'] ?? null) === $fingerprint) { $counts['ignored']++; return; }
                if ($state === 'recovered') { if ($incident && $incident->state !== IncidentState::Recovered) $this->requestRecovery($incident, $resolution->policy->recovery_after_seconds, 'Recovery received from LibreNMS.', $counts); return; }
                // An upstream LibreNMS acknowledgement acknowledges the incident — it must
                // not be re-processed as a fresh active observation (which would re-notify).
                if ($state === 'acknowledged') { if ($incident && ! in_array($incident->state, [IncidentState::Recovered, IncidentState::Acknowledged], true)) { $incident->update(['state' => IncidentState::Acknowledged, 'acknowledged_at' => now(), 'last_seen_at' => now()]); $incident->events()->create(['event_type' => 'acknowledged', 'event_message' => 'Acknowledged upstream in LibreNMS.']); $counts['processed']++; } else { $counts['ignored']++; } return; }
                $reason = $suppression->reason($resolution->policy, $context, ! (bool) $device->status, SuppressionService::maintenanceSuppresses($device), SuppressionService::anyParentDown($device->parents), $dependencies->uplinkDown($device, $context->portId));
                $target = $reason ? IncidentState::Suppressed : ($resolution->policy->trigger_after_seconds === 0 && $resolution->policy->failed_poll_count <= 1 ? IncidentState::Active : IncidentState::Pending);
                $incident ??= new Incident(['incident_key' => Incident::key($context->deviceId, $context->portId), 'first_seen_at' => now(), 'notification_count' => 0]);
                $reopening = $incident->exists && $incident->state === IncidentState::Recovered;
                $portReceivers = collect($resolution->candidates)->first(fn ($candidate) => $candidate->assignment_type === \LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\AssignmentType::Port)?->metadata_json['receivers'] ?? [];
                $groupReceivers = collect($resolution->candidates)->where('assignment_type', \LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\AssignmentType::DeviceGroup)->flatMap(fn ($candidate) => $candidate->metadata_json['receivers'] ?? [])->unique()->values()->all();
                $contextData = (array) $context; $contextData['observation_count'] = $reopening ? 1 : (int) (($incident->context_json['observation_count'] ?? 0) + 1); $contextData['last_event_fingerprint'] = $fingerprint; $contextData['assignment_receivers'] = $portReceivers; $contextData['device_group_receivers'] = $groupReceivers; $contextData['assignment_source'] = $resolution->winner?->assignment_type->value ?? 'configured_default';
                if (! $reason && $resolution->policy->failed_poll_count <= $contextData['observation_count'] && $resolution->policy->trigger_after_seconds === 0) $target = IncidentState::Active;
                $incident->fill(['source_alert_id' => $data['alert_id'] ?? null, 'source_alert_uid' => $data['alert_uid'] ?? null, 'source_rule_id' => $data['rule_id'] ?? null, 'device_id' => $context->deviceId, 'port_id' => $context->portId, 'policy_id' => $resolution->policy->id, 'state' => $target, 'severity' => Severity::tryFrom($data['severity'] ?? '') ?? $resolution->policy->severity, 'first_seen_at' => $reopening ? now() : $incident->first_seen_at, 'last_seen_at' => now(), 'triggered_at' => $target === IncidentState::Active ? ($reopening ? now() : ($incident->triggered_at ?? now())) : null, 'recovered_at' => null, 'acknowledged_at' => null, 'acknowledged_by' => null, 'muted_until' => $reopening ? null : $incident->muted_until, 'notification_count' => $reopening ? 0 : $incident->notification_count, 'last_notification_at' => $reopening ? null : $incident->last_notification_at, 'suppression_reason' => $reason, 'context_json' => $contextData])->save();
                if ($reopening) $incident->events()->create(['event_type' => 'reopened', 'event_message' => 'A new failure reopened the recovered interface incident.']);
                $incident->events()->create(['event_type' => 'received', 'event_message' => 'Alert observation received.']); $incident->events()->create(['event_type' => $target->value, 'event_message' => 'Alert observation processed.', 'event_data' => ['assignment_id' => $resolution->winner?->id]]); $counts[$target->value]++; $counts['processed']++;
            });
        }
        if (isset($data['alert_id']) || isset($data['alert_uid']) || isset($data['rule_id'])) {
            $query = Incident::where('device_id', $device->device_id)->where('state', '!=', IncidentState::Recovered)->where(function ($query) use ($data): void { if (isset($data['alert_id'])) $query->orWhere('source_alert_id', $data['alert_id']); if (isset($data['alert_uid'])) $query->orWhere('source_alert_uid', (string) $data['alert_uid']); if (! isset($data['alert_id']) && ! isset($data['alert_uid']) && isset($data['rule_id'])) $query->orWhere('source_rule_id', $data['rule_id']); });
            if ($state === 'recovered') $seen = [];
            $query->with('policy')->when($seen !== [], fn ($q) => $q->whereNotIn('port_id', $seen))->each(function (Incident $incident) use (&$counts): void { $this->requestRecovery($incident, (int) ($incident->policy?->recovery_after_seconds ?? 0), 'Interface disappeared from current fault set.', $counts); });
        }
        \Illuminate\Support\Facades\Log::channel('iapm')->info('Alert ingestion completed.', ['device_id' => $device->device_id, 'alert_id' => $data['alert_id'] ?? null, 'alert_uid' => $data['alert_uid'] ?? null, 'state' => $state, 'counts' => $counts]);
        return response()->json(['status' => 'accepted', 'counts' => $counts]);
    }

    /**
     * Run an incident upsert inside a transaction, retrying once if a concurrent
     * first-time webhook for the same port wins the race to INSERT. On the retry
     * the locked read finds the now-existing row and takes the update path, so
     * the constraint violation never surfaces to the caller as a 500.
     */
    private function runWithUniqueRetry(callable $callback): void
    {
        try {
            DB::transaction($callback);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            DB::transaction($callback);
        }
    }

    private function requestRecovery(Incident $incident, int $holdDown, string $message, array &$counts): void
    {
        if ($holdDown <= 0) { $incident->update(['state' => IncidentState::Recovered, 'recovered_at' => now(), 'last_seen_at' => now(), 'suppression_reason' => null]); $incident->events()->create(['event_type' => 'recovered', 'event_message' => $message]); app(\LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\OutageRecorder::class)->record($incident); $counts['recovered']++; return; }
        $context = $incident->context_json; $context['up_seen_at'] ??= now()->toIso8601String(); $incident->update(['context_json' => $context, 'last_seen_at' => now()]);
        if (! $incident->events()->where('event_type', 'recovery_pending')->where('created_at', '>=', now()->subSeconds($holdDown))->exists()) $incident->events()->create(['event_type' => 'recovery_pending', 'event_message' => "$message Recovery hold-down is {$holdDown} seconds."]);
        $counts['pending']++;
    }
}
