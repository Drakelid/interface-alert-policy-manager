<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use App\Models\Device;
use App\Models\Port;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\Severity;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Requests\IngestAlertRequest;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\IngestionInbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\DependencyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\IncidentLifecycleService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ReceiverResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\StateNormalizer;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SuppressionService;

class IngestionController extends Controller
{
    public function __invoke(IngestAlertRequest $request, StateNormalizer $states, InterfaceContextService $contexts, PolicyResolver $policies, SuppressionService $suppression, DependencyResolver $dependencies, ReceiverResolver $receivers, IncidentLifecycleService $lifecycle): JsonResponse
    {
        $data = $request->validated();
        $state = $states->normalize($data['state']);
        $sourceEventAt = isset($data['timestamp']) ? CarbonImmutable::parse($data['timestamp'])->utc()->toIso8601String() : null;
        if ($state === 'recovered' && ! isset($data['alert_id']) && ! isset($data['alert_uid']) && ! isset($data['rule_id'])) {
            return response()->json(['error' => ['code' => 'correlation_required', 'message' => 'Recovery payload requires alert_id, alert_uid, or rule_id.']], 422);
        }
        $device = Device::find($data['device_id']);
        if (! $device) {
            return response()->json(['error' => ['code' => 'device_not_found', 'message' => 'Referenced device does not exist.']], 422);
        }
        $asyncThreshold = (int) config('iapm.ingestion.async_threshold', 1000);
        $durableRecovery = $state === 'recovered' && (bool) config('iapm.ingestion.async_recovery', true);
        if (! $request->attributes->getBoolean('_iapm_durable_replay') && ($durableRecovery || ($asyncThreshold > 0 && count($data['faults']) >= $asyncThreshold))) {
            $key = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $inbox = IngestionInbox::where('idempotency_key', $key)->first();
            if ($inbox) {
                return response()->json(['status' => 'accepted', 'processing' => 'durable_inbox', 'inbox_id' => $inbox->id], 202);
            }
            $maxPending = max(1, (int) config('iapm.ingestion.inbox_max_pending', 10000));
            if (IngestionInbox::whereIn('status', ['pending', 'processing', 'failed'])->count() >= $maxPending) {
                return response()->json(['error' => ['code' => 'ingestion_backlog_full', 'message' => 'Durable ingestion backlog is full; retry later.']], 503, ['Retry-After' => '60']);
            }
            try {
                $inbox = IngestionInbox::create([
                    'idempotency_key' => $key,
                    'device_id' => $device->device_id,
                    'fault_count' => count($data['faults']),
                    'payload_encrypted' => $data,
                    'status' => 'pending',
                    'available_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $inbox = IngestionInbox::where('idempotency_key', $key)->firstOrFail();
            }

            return response()->json(['status' => 'accepted', 'processing' => 'durable_inbox', 'inbox_id' => $inbox->id], 202);
        }
        $device->loadMissing('parents');
        $counts = array_fill_keys(['processed', 'activated', 'pending', 'suppressed', 'recovered', 'ignored', 'failed'], 0);
        // Collapse repeated port entries before relationships and policies are
        // loaded. Duplicate faults must not multiply work during a storm.
        $faults = collect($data['faults'])->keyBy(fn (array $fault): int => (int) $fault['port_id']);
        $counts['ignored'] += count($data['faults']) - $faults->count();
        $ports = Port::query()
            ->where('device_id', $device->device_id)
            ->whereIn('port_id', $faults->keys())
            ->with(['device.location', 'device.groups', 'groups'])
            ->get()->keyBy(fn (Port $port): int => (int) $port->port_id);
        $seen = $ports->keys()->map(fn ($id) => (int) $id)->all();
        // When false, alerts for interfaces with no effective policy are ignored rather
        // than persisted as suppressed no_policy incidents — the safety valve for large
        // fleets that scope IAPM to specific interfaces instead of a catch-all default.
        $recordUnpoliced = (bool) app(SettingStore::class)->get('record_unpoliced', true);
        foreach ($faults as $fault) {
            $port = $ports->get((int) $fault['port_id']);
            if (! $port) {
                $counts['failed']++;

                continue;
            }
            $context = $contexts->forPort($port);
            $resolution = $policies->resolve($context, writeCache: false);
            $fingerprint = hash('sha256', implode('|', [(string) ($data['alert_uid'] ?? ''), (string) ($data['alert_id'] ?? ''), (string) ($data['timestamp'] ?? ''), $state, (string) $context->portId]));
            if (! $resolution->policy) {
                if (! $recordUnpoliced) {
                    $counts['ignored']++;

                    continue;
                }
                $this->runWithTransientRetry(function () use ($data, $state, $context, $fingerprint, $sourceEventAt, &$counts, $lifecycle): void {
                    $incident = Incident::where('incident_key', Incident::key($context->deviceId, $context->portId))->lockForUpdate()->first() ?? new Incident(['incident_key' => Incident::key($context->deviceId, $context->portId), 'first_seen_at' => now(), 'notification_count' => 0]);
                    if ($incident->exists && $this->isStale($incident, $sourceEventAt)) {
                        $counts['ignored']++;

                        return;
                    }
                    if (($incident->context_json['last_event_fingerprint'] ?? null) === $fingerprint) {
                        $counts['ignored']++;

                        return;
                    }
                    if ($state === 'recovered') {
                        if ($incident->exists) {
                            $incident->update(['policy_id' => null, 'suppression_reason' => 'no_policy']);
                        }
                        if ($incident->exists && $lifecycle->recover($incident, 'Recovery received from LibreNMS without an effective policy.', ['context_json' => $this->sourceContext((array) $incident->context_json, $sourceEventAt, $fingerprint)])) {
                            $counts['recovered']++;
                        } else {
                            $counts['ignored']++;
                        }

                        return;
                    }
                    if ($state === 'acknowledged' && $incident->exists) {
                        if ($incident->state === IncidentState::Recovered) {
                            $counts['ignored']++;

                            return;
                        }
                        $ackContext = (array) $incident->context_json;
                        $ackContext['last_event_fingerprint'] = $fingerprint;
                        $ackContext = $this->sourceContext($ackContext, $sourceEventAt, $fingerprint);
                        $ackContext['observation_count'] = (int) ($ackContext['observation_count'] ?? 0) + 1;
                        $ackContext['assignment_receivers'] = [];
                        $incident->update(['policy_id' => null, 'suppression_reason' => 'no_policy', 'last_seen_at' => now(), 'context_json' => $ackContext]);
                        if ($incident->state !== IncidentState::Acknowledged) {
                            $lifecycle->acknowledge($incident);
                            $incident->update(['pre_acknowledgement_state' => IncidentState::Suppressed->value]);
                            $counts['processed']++;
                        } else {
                            $counts['ignored']++;
                        }

                        return;
                    }
                    if ($incident->exists && $incident->state === IncidentState::Acknowledged) {
                        $ackContext = (array) $incident->context_json;
                        $ackContext['last_event_fingerprint'] = $fingerprint;
                        $ackContext = $this->sourceContext($ackContext, $sourceEventAt, $fingerprint);
                        $ackContext['observation_count'] = (int) ($ackContext['observation_count'] ?? 0) + 1;
                        $ackContext['assignment_receivers'] = [];
                        $incident->update(['policy_id' => null, 'suppression_reason' => 'no_policy', 'last_seen_at' => now(), 'context_json' => $ackContext, 'pre_acknowledgement_state' => IncidentState::Suppressed->value]);
                        $counts['ignored']++;

                        return;
                    }
                    $reopening = $incident->exists && $incident->state === IncidentState::Recovered;
                    $priorState = $incident->exists ? $incident->state : null;
                    $contextData = (array) $context;
                    $contextData['last_event_fingerprint'] = $fingerprint;
                    $contextData = $this->sourceContext($contextData, $sourceEventAt, $fingerprint);
                    $contextData['observation_count'] = $reopening ? 1 : (int) (($incident->context_json['observation_count'] ?? 0) + 1);
                    $episode = $reopening ? $lifecycle->beginEpisode($incident, $contextData) : [];
                    $incident->fill(array_merge($episode, ['source_alert_id' => $data['alert_id'] ?? null, 'source_alert_uid' => $data['alert_uid'] ?? null, 'source_rule_id' => $data['rule_id'] ?? null, 'device_id' => $context->deviceId, 'port_id' => $context->portId, 'policy_id' => null, 'state' => IncidentState::Suppressed, 'severity' => Severity::tryFrom($data['severity'] ?? '') ?? Severity::Critical, 'last_seen_at' => now(), 'recovered_at' => null, 'suppression_reason' => 'no_policy', 'context_json' => $contextData]))->save();
                    if ($reopening) {
                        $incident->events()->create(['event_type' => 'reopened', 'event_message' => 'A new failure reopened the recovered interface incident without an effective policy.']);
                    }
                    if ($priorState === null || $reopening || $priorState !== IncidentState::Suppressed) {
                        $incident->events()->create(['event_type' => 'received', 'event_message' => 'Alert observation received.']);
                        $incident->events()->create(['event_type' => 'suppressed', 'event_message' => 'No effective policy matched the interface.']);
                    }
                    $counts['processed']++;
                    $counts['suppressed']++;
                }, $counts);

                continue;
            }
            $this->runWithTransientRetry(function () use ($data, $state, $context, $resolution, $suppression, $dependencies, $device, $fingerprint, $sourceEventAt, &$counts, $receivers, $lifecycle): void {
                $incident = Incident::where('incident_key', Incident::key($context->deviceId, $context->portId))->lockForUpdate()->first();
                if ($incident && $this->isStale($incident, $sourceEventAt)) {
                    $counts['ignored']++;

                    return;
                }
                if ($incident && ($incident->context_json['last_event_fingerprint'] ?? null) === $fingerprint) {
                    $counts['ignored']++;

                    return;
                }
                if ($state === 'recovered') {
                    if ($incident && $incident->state !== IncidentState::Recovered) {
                        $this->requestRecovery($incident, $resolution->policy->recovery_after_seconds, 'Recovery received from LibreNMS.', $counts, $sourceEventAt, $fingerprint);
                    }

                    return;
                }
                // An upstream LibreNMS acknowledgement acknowledges the incident — it must
                // not be re-processed as a fresh active observation (which would re-notify).
                if ($state === 'acknowledged') {
                    if ($incident && ! in_array($incident->state, [IncidentState::Recovered, IncidentState::Acknowledged], true)) {
                        $incident->update(['context_json' => $this->sourceContext((array) $incident->context_json, $sourceEventAt, $fingerprint)]);
                        $lifecycle->acknowledge($incident);
                        $incident->update(['last_seen_at' => now()]);
                        $counts['processed']++;
                    } else {
                        $counts['ignored']++;
                    }

                    return;
                }
                // A continued active observation on an already-acknowledged incident must not
                // revert it to Active (that resurrects it and re-notifies). Refresh liveness
                // and keep the acknowledgement — only a recovery clears it.
                if ($incident && $incident->state === IncidentState::Acknowledged) {
                    $ackCtx = $incident->context_json;
                    $ackCtx['observation_count'] = (int) ($ackCtx['observation_count'] ?? 0) + 1;
                    $ackCtx['last_event_fingerprint'] = $fingerprint;
                    $ackCtx = $this->sourceContext($ackCtx, $sourceEventAt, $fingerprint);
                    $incident->update(['last_seen_at' => now(), 'context_json' => $ackCtx]);
                    $counts['ignored']++;

                    return;
                }
                $reason = $suppression->reason($resolution->policy, $context, ! (bool) $device->status, SuppressionService::maintenanceSuppresses($device), SuppressionService::anyParentDown($device->parents), $dependencies->uplinkDown($device, $context->portId));
                $target = $reason ? IncidentState::Suppressed : ($resolution->policy->trigger_after_seconds === 0 && $resolution->policy->failed_poll_count <= 1 ? IncidentState::Active : IncidentState::Pending);
                $incident ??= new Incident(['incident_key' => Incident::key($context->deviceId, $context->portId), 'first_seen_at' => now(), 'notification_count' => 0]);
                $priorState = $incident->exists ? $incident->state : null;
                $reopening = $incident->exists && $incident->state === IncidentState::Recovered;
                $contextData = (array) $context;
                $contextData['observation_count'] = $reopening ? 1 : (int) (($incident->context_json['observation_count'] ?? 0) + 1);
                $contextData['last_event_fingerprint'] = $fingerprint;
                $contextData = $this->sourceContext($contextData, $sourceEventAt, $fingerprint);
                $contextData['assignment_receivers'] = $receivers->assignmentReceivers($resolution);
                $contextData['assignment_source'] = $resolution->winner?->assignment_type->value ?? 'configured_default';
                if (! $reason && $resolution->policy->failed_poll_count <= $contextData['observation_count'] && $resolution->policy->trigger_after_seconds === 0) {
                    $target = IncidentState::Active;
                }
                $episode = $reopening ? $lifecycle->beginEpisode($incident, $contextData) : [];
                $incident->fill(array_merge($episode, ['source_alert_id' => $data['alert_id'] ?? null, 'source_alert_uid' => $data['alert_uid'] ?? null, 'source_rule_id' => $data['rule_id'] ?? null, 'device_id' => $context->deviceId, 'port_id' => $context->portId, 'policy_id' => $resolution->policy->id, 'state' => $target, 'severity' => Severity::tryFrom($data['severity'] ?? '') ?? $resolution->policy->severity, 'last_seen_at' => now(), 'triggered_at' => $target === IncidentState::Active ? ($reopening ? now() : ($incident->triggered_at ?? now())) : null, 'recovered_at' => null, 'suppression_reason' => $reason, 'context_json' => $contextData]))->save();
                if ($reopening) {
                    $incident->events()->create(['event_type' => 'reopened', 'event_message' => 'A new failure reopened the recovered interface incident.']);
                }
                // Only write the observation/state events on a meaningful change (new incident,
                // reopen, or state transition). A continued same-state re-alert just refreshes
                // liveness above — writing 'received'+state events every interval would bloat the
                // events table unboundedly for persistently-down interfaces at fleet scale.
                if ($priorState === null || $reopening || $priorState !== $target) {
                    $incident->events()->create(['event_type' => 'received', 'event_message' => 'Alert observation received.']);
                    $incident->events()->create(['event_type' => $target->value, 'event_message' => 'Alert observation processed.', 'event_data' => ['assignment_id' => $resolution->winner?->id]]);
                }
                $counts[$target === IncidentState::Active ? 'activated' : $target->value]++;
                $counts['processed']++;
            }, $counts);
        }
        if (isset($data['alert_id']) || isset($data['alert_uid']) || isset($data['rule_id'])) {
            $query = Incident::where('device_id', $device->device_id)->where('state', '!=', IncidentState::Recovered)->where(function ($query) use ($data): void {
                // Supplied identifiers describe one source alert. Requiring all of
                // them avoids recovering unrelated incidents after identifier reuse.
                if (isset($data['alert_id'])) {
                    $query->where('source_alert_id', $data['alert_id']);
                }
                if (isset($data['alert_uid'])) {
                    $query->where('source_alert_uid', (string) $data['alert_uid']);
                }
                if (isset($data['rule_id'])) {
                    $query->where('source_rule_id', $data['rule_id']);
                }
            });
            if ($state === 'recovered') {
                $seen = [];
            }
            $seenLookup = array_fill_keys($seen, true);
            // A whole-chassis recovery can touch thousands of incidents; process them
            // in bounded chunks, each in one transaction, so we don't run thousands of
            // autocommitted writes (and open a giant lock) inside a single web request.
            $query->with('policy')->chunkById((int) config('iapm.processing.batch_size', 500), function ($incidents) use (&$counts, $sourceEventAt, $seenLookup): void {
                DB::transaction(function () use ($incidents, &$counts, $sourceEventAt, $seenLookup): void {
                    $immediate = $incidents->filter(fn (Incident $incident): bool => ! isset($seenLookup[(int) $incident->port_id]) && ! $this->isStale($incident, $sourceEventAt) && (int) ($incident->policy?->recovery_after_seconds ?? 0) <= 0);
                    $immediateIds = array_fill_keys($immediate->modelKeys(), true);
                    if ($immediate->isNotEmpty()) {
                        $recovered = app(IncidentLifecycleService::class)->recoverMany($immediate, 'Interface disappeared from current fault set.', $sourceEventAt);
                        $counts['recovered'] += $recovered;
                        $counts['ignored'] += $immediate->count() - $recovered;
                    }
                    foreach ($incidents as $incident) {
                        if (isset($seenLookup[(int) $incident->port_id])) {
                            continue;
                        }
                        if (isset($immediateIds[$incident->id])) {
                            continue;
                        }
                        if ($this->isStale($incident, $sourceEventAt)) {
                            $counts['ignored']++;

                            continue;
                        }
                        $this->requestRecovery($incident, (int) ($incident->policy?->recovery_after_seconds ?? 0), 'Interface disappeared from current fault set.', $counts, $sourceEventAt);
                    }
                });
            });
        }
        app(SettingStore::class)->putThrottled('last_ingestion_at', now()->toIso8601String(), (int) config('iapm.ingestion.heartbeat_write_seconds', 30));
        $sampleRate = min(1.0, max(0.0, (float) config('iapm.ingestion.success_log_sample_rate', 0.01)));
        if ($counts['failed'] > 0 || random_int(1, 10000) <= (int) round($sampleRate * 10000)) {
            Log::channel('iapm')->log($counts['failed'] > 0 ? 'warning' : 'info', 'Alert ingestion completed.', ['device_id' => $device->device_id, 'alert_id' => $data['alert_id'] ?? null, 'alert_uid' => $data['alert_uid'] ?? null, 'state' => $state, 'counts' => $counts]);
        }

        return response()->json(['status' => 'accepted', 'counts' => $counts]);
    }

    /**
     * Run an incident upsert inside a transaction, retrying once if a concurrent
     * first-time webhook for the same port wins the race to INSERT. On the retry
     * the locked read finds the now-existing row and takes the update path, so
     * the constraint violation never surfaces to the caller as a 500.
     */
    private function runWithTransientRetry(callable $callback, array &$counts): void
    {
        $attempts = 3;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $before = $counts;
            try {
                DB::transaction($callback);

                return;
            } catch (QueryException $exception) {
                $counts = $before;
                if ($attempt === $attempts || ! $this->retryableDatabaseException($exception)) {
                    throw $exception;
                }
                usleep(random_int(10_000, 50_000) * $attempt);
            }
        }
    }

    private function retryableDatabaseException(QueryException $exception): bool
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return true;
        }
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($sqlState, ['40001', 'HY000'], true) && in_array($driverCode, [1205, 1213], true);
    }

    private function requestRecovery(Incident $incident, int $holdDown, string $message, array &$counts, ?string $sourceEventAt = null, ?string $fingerprint = null): void
    {
        if ($holdDown <= 0) {
            if (app(IncidentLifecycleService::class)->recover($incident, $message, ['context_json' => $this->sourceContext((array) $incident->context_json, $sourceEventAt, $fingerprint)])) {
                $counts['recovered']++;
            } else {
                $counts['ignored']++;
            }

            return;
        }
        $context = $incident->context_json;
        $context = $this->sourceContext((array) $context, $sourceEventAt, $fingerprint);
        $context['up_seen_at'] ??= now()->toIso8601String();
        $incident->update(['context_json' => $context, 'last_seen_at' => now()]);
        if (! $incident->events()->where('event_type', 'recovery_pending')->where('created_at', '>=', now()->subSeconds($holdDown))->exists()) {
            $incident->events()->create(['event_type' => 'recovery_pending', 'event_message' => "$message Recovery hold-down is {$holdDown} seconds."]);
        }
        $counts['pending']++;
    }

    private function isStale(Incident $incident, ?string $sourceEventAt): bool
    {
        $last = $incident->context_json['last_source_event_at'] ?? null;

        return $sourceEventAt !== null && is_string($last) && CarbonImmutable::parse($sourceEventAt)->lessThan(CarbonImmutable::parse($last));
    }

    private function sourceContext(array $context, ?string $sourceEventAt, ?string $fingerprint = null): array
    {
        if ($sourceEventAt !== null) {
            $context['last_source_event_at'] = $sourceEventAt;
        }
        if ($fingerprint !== null) {
            $context['last_event_fingerprint'] = $fingerprint;
        }

        return $context;
    }
}
