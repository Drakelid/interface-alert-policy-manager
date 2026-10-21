<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\IapmServiceProvider;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\SendNotificationJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Transports\TransportResult;

class NotificationDispatcher
{
    public function __construct(private readonly TransportManager $transports, private readonly Redactor $redactor, private readonly SettingStore $settings, private readonly SmsContentFilter $smsFilters, private readonly NotificationHistory $history) {}

    public function configurationFailure(Incident $incident, Destination $destination, ?PolicyAction $action, string $phase, string $error): TransportResult
    {
        $result = new TransportResult(false, null, null, $this->redactor->text($error));
        $this->record($incident, $destination, $action, $phase, null, $result, 'failed_configuration');
        $incident->events()->create(['event_type' => 'notification_failed', 'event_message' => ucfirst($phase).' notification configuration failed.', 'event_data' => ['destination_id' => $destination->id, 'error' => $result->error]]);

        return $result;
    }

    /**
     * Persist the sensitive payload before dispatch. Repeated calls for the same
     * episode/action/phase/destination/receiver return the same durable row.
     *
     * @param  list<int>|null  $incidentIds  all incidents represented by a digest
     */
    public function dispatch(Incident $incident, Destination $destination, ?PolicyAction $action, string $phase, string $receiver, string $message, ?array $incidentIds = null, bool $forceSync = false, bool $force = false): TransportResult
    {
        if (! $destination->enabled) {
            return $this->configurationFailure($incident, $destination, $action, $phase, 'Destination is disabled.');
        }
        if ($destination->type === 'sms_gateway') {
            $message = $this->smsFilters->filter($message);
        }

        if (! filled($incident->episode_uuid)) {
            return $this->configurationFailure($incident, $destination, $action, $phase, 'Incident has no outage episode identity; run migrations.');
        }
        $receiverHash = hash('sha256', $receiver);
        // Eligibility and occurrence allocation share the incident lock. A worker
        // completing after the scheduler's snapshot cannot invent another send.
        $outbox = DB::transaction(function () use ($incident, $destination, $action, $phase, $receiver, $receiverHash, $message, $incidentIds, $force): ?NotificationOutbox {
            $incidents = Incident::whereIn('id', $incidentIds ?: [$incident->id])->orderBy('id')->lockForUpdate()->get();
            $current = $incidents->firstWhere('id', $incident->id);
            if (! $current || (string) $current->episode_uuid !== (string) $incident->episode_uuid) {
                return null;
            }
            $current->load('policy');
            $currentAction = $action?->fresh();
            if ($this->phaseInvalid($current, $currentAction, $phase, $action !== null)) {
                return null;
            }
            $scope = NotificationOutbox::where('incident_id', $incident->id)
                ->where('episode_uuid', $incident->episode_uuid)->where('destination_id', $destination->id)
                ->where('policy_action_id', $action?->id)->where('phase', $phase)->where('receiver_hash', $receiverHash);
            $previous = (clone $scope)->latest('id')->first();
            if ($previous && in_array($previous->status, ['pending', 'queued', 'processing', 'failed'], true)) {
                return $previous;
            }
            $history = $this->history->forOccurrence($current, $destination->id, $action?->id, $phase, $receiverHash);
            $completed = $history['completed'];
            $lastCompleted = (clone $scope)->whereIn('status', ['sent', 'dry_run'])->latest('delivered_at')->first();
            if (! $force && ! $this->occurrenceDue($current, $currentAction, $phase, $completed, $history['last_completed'])) {
                return $lastCompleted;
            }
            if ($phase === 'trigger') {
                // A failed digest is replaced by individual fallback. Serialize
                // that choice with both occurrence allocation and other schedulers.
                NotificationOutbox::where('phase', 'digest')->where('status', 'failed')
                    ->where('destination_id', $destination->id)->where('receiver_hash', $receiverHash)
                    ->whereIn('id', DB::table('iapm_notification_outbox_incidents')->where('incident_id', $current->id)->where('episode_uuid', $current->episode_uuid)->select('notification_outbox_id'))
                    ->update(['status' => 'cancelled', 'available_at' => null, 'claim_token' => null, 'claimed_at' => null, 'queued_at' => null, 'last_error_redacted' => 'Digest superseded by individual trigger fallback.']);
            }
            if ($phase === 'trigger' && $this->triggerCovered($current, $destination->id, $receiverHash)) {
                return null;
            }
            if ($phase === 'digest') {
                foreach ($incidents as $member) {
                    if ($this->digestMemberCovered($member, $destination->id, $receiverHash)) {
                        return null;
                    }
                }
            }
            $members = $incidents->map(fn (Incident $item): string => $item->id.':'.$item->episode_uuid)->implode('|');
            $base = implode('|', [$phase, (string) $destination->id, (string) ($action?->id ?? 0), $receiverHash, hash('sha256', $members)]);
            $key = hash('sha256', $base.'|'.((clone $scope)->count() + 1));
            try {
                $outbox = NotificationOutbox::create([
                    'idempotency_key' => $key,
                    'episode_uuid' => $incident->episode_uuid,
                    'incident_id' => $incident->id,
                    'destination_id' => $destination->id,
                    'policy_action_id' => $action?->id,
                    'action_required' => $action !== null,
                    'forced' => $force,
                    'phase' => $phase,
                    'receiver_hash' => $receiverHash,
                    'receiver_encrypted' => $receiver,
                    'message_encrypted' => $message,
                    // Canonical membership lives in the indexed pivot. Never place a
                    // multi-thousand-ID digest array in one encrypted database value.
                    'incident_ids_encrypted' => [],
                    'status' => 'pending',
                    'queued_at' => null,
                    'available_at' => now(),
                ]);
                DB::table('iapm_notification_outbox_incidents')->insert($incidents->map(fn (Incident $item) => ['notification_outbox_id' => $outbox->id, 'incident_id' => $item->id, 'episode_uuid' => $item->episode_uuid])->all());

                return $outbox;
            } catch (UniqueConstraintViolationException) {
                return NotificationOutbox::where('idempotency_key', $key)->firstOrFail();
            }
        }, 3);
        if (! $outbox) {
            return new TransportResult(true, null, 'not due or no longer applicable');
        }

        if (in_array($outbox->status, ['sent', 'dry_run'], true)) {
            if ($outbox->finalized_at === null) {
                $this->finalizeCompleted($outbox->id);
            }

            return new TransportResult(true, null, $outbox->status);
        }
        if ($outbox->status === 'processing' || ($outbox->status === 'queued' && ! $forceSync && $this->settings->get('dispatch_mode', 'queue') === 'queue')) {
            return new TransportResult(true, null, $outbox->status);
        }
        if ($outbox->available_at?->isFuture()) {
            return new TransportResult(false, null, 'retry_scheduled', 'Notification is durably retained for a later retry.');
        }

        if ((bool) $this->freshSetting('dry_run', true)) {
            if ($outbox->wasRecentlyCreated) {
                return $this->completeDryRun($outbox);
            }

            return new TransportResult(false, null, 'paused', 'Previously accepted live work is paused by dry-run.');
        }

        if (! $forceSync && $this->settings->get('dispatch_mode', 'queue') === 'queue') {
            $claimedForQueue = NotificationOutbox::whereKey($outbox->id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'queued', 'queued_at' => now()]);
            if ($claimedForQueue !== 1) {
                return new TransportResult(true, null, 'queued');
            }
            try {
                SendNotificationJob::dispatch($outbox->id);
                $incident->events()->create(['event_type' => 'notification_queued', 'event_message' => ucfirst($phase).' notification queued for delivery.', 'event_data' => ['destination_id' => $destination->id, 'outbox_id' => $outbox->id]]);

                return new TransportResult(true, null, 'queued');
            } catch (\Throwable $exception) {
                NotificationOutbox::whereKey($outbox->id)->where('status', 'queued')->update([
                    'status' => 'pending',
                    'queued_at' => null,
                    'available_at' => now()->addSeconds(5),
                    'last_error_redacted' => 'Queue dispatch unavailable; durable outbox remains pending.',
                ]);
                Log::channel('iapm')->error('Queued dispatch failed; durable outbox remains pending.', ['incident_id' => $incident->id, 'outbox_id' => $outbox->id, 'error' => $this->redactor->text($exception->getMessage())]);

                return new TransportResult(false, null, null, 'Queue dispatch unavailable; notification retained for retry.');
            }
        }

        return $this->deliverOutbox($outbox->id);
    }

    public function deliverOutbox(int $outboxId): TransportResult
    {
        $outbox = DB::transaction(function () use ($outboxId): ?NotificationOutbox {
            $row = NotificationOutbox::whereKey($outboxId)->lockForUpdate()->first();
            if (! $row || $row->status === 'cancelled' || (in_array($row->status, ['sent', 'dry_run'], true) && $row->finalized_at !== null)) {
                return null;
            }
            if (in_array($row->status, ['sent', 'dry_run'], true)) {
                return $row->fresh(['incident', 'destination', 'action']);
            }
            if (in_array($row->status, ['pending', 'failed'], true) && $row->available_at?->isFuture()) {
                return null;
            }
            if ($row->status === 'processing' && $row->claimed_at?->isAfter(now()->subSeconds(max(30, (int) config('iapm.queue.timeout', 60) + 30)))) {
                return null;
            }
            $row->update(['status' => 'processing', 'claimed_at' => now(), 'claim_token' => (string) Str::uuid()]);

            return $row->fresh(['incident', 'destination', 'action']);
        });
        if (! $outbox) {
            return new TransportResult(true, null, 'already claimed or complete');
        }
        if (in_array($outbox->status, ['sent', 'dry_run'], true)) {
            $this->finalizeCompleted($outbox->id);

            return new TransportResult(true, null, $outbox->status);
        }
        if ($blocked = $this->deliveryBlocked($outbox)) {
            return $blocked;
        }
        if (! $outbox->incident || ! $outbox->destination) {
            NotificationOutbox::whereKey($outboxId)->update(['status' => 'failed', 'last_error_redacted' => 'Referenced incident or destination no longer exists.']);

            return new TransportResult(false, null, null, 'Referenced incident or destination no longer exists.');
        }
        if (! $outbox->destination->enabled) {
            $this->releaseClaim($outbox, 'Destination is disabled.', 300);

            return new TransportResult(false, null, null, 'Destination is disabled.');
        }

        $configuration = (array) $outbox->destination->configuration_encrypted;
        $configuration['_iapm_idempotency_key'] = $outbox->idempotency_key;
        $configuration['timeout'] ??= (int) $this->settings->get('notification_timeout', config('iapm.http.timeout', 15));
        $attempts = 1 + max(0, min(10, (int) ($configuration['retry_count'] ?? $this->settings->get('notification_retry_count', config('iapm.http.retries', 2)))));
        // Destination validation bounds this for configurations entered through
        // the UI, but the effective timeout/retry count can also come from global
        // settings or a row written before that rule existed. Enforce the budget
        // where it is actually spent: a job that outlives the worker timeout is
        // killed mid-delivery, stale-reclaimed, and retried, which turns one
        // logical notification into repeated gateway calls.
        [$attempts, $configuration['timeout']] = $this->clampToWorkerBudget($attempts, (int) $configuration['timeout'], (int) ($configuration['retry_delay_ms'] ?? config('iapm.http.retry_delay_ms', 500)), $outbox->id);
        $result = new TransportResult(false, null, null, 'No delivery attempt was made.');
        $attemptsMade = 0;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($blocked = $this->deliveryBlocked($outbox)) {
                return $blocked;
            }
            $result = $this->transports->for($outbox->destination->type)->send($configuration, (string) $outbox->receiver_encrypted, (string) $outbox->message_encrypted);
            $attemptsMade++;
            $outbox->increment('attempt_count');
            // Log the outbox's cumulative attempt number, not this pass's loop
            // counter. A row that failed here, was requeued, and later succeeded
            // would otherwise record "attempt 1" against its final delivery,
            // making the delivery log read as a first-try success and hiding the
            // earlier failures from anyone reconstructing a retry history.
            $this->record($outbox->incident, $outbox->destination, $outbox->action, $outbox->phase, $outbox, $result, $result->successful ? 'sent' : 'failed', (int) $outbox->attempt_count);
            if ($result->successful) {
                break;
            }
            if ($result->retryAfterSeconds !== null) {
                break;
            }
            if ($attempt < $attempts) {
                usleep(max(0, min(60000, (int) ($configuration['retry_delay_ms'] ?? config('iapm.http.retry_delay_ms', 500)))) * 1000);
            }
        }

        $safeError = $this->redactPayloadEcho((string) $result->error, $outbox);
        $retryAt = $result->successful ? null : now()->addSeconds($this->retryDelay($outbox, $result));
        $updated = NotificationOutbox::whereKey($outbox->id)->where('claim_token', $outbox->claim_token)->update(['status' => $result->successful ? 'sent' : 'failed', 'available_at' => $retryAt, 'delivered_at' => $result->successful ? now() : null, 'last_error_redacted' => $result->successful ? null : $safeError, 'claim_token' => null, 'claimed_at' => null, 'queued_at' => null]);
        if ($updated !== 1) {
            return $result;
        }
        $outbox->refresh();
        $this->settings->putThrottled($result->successful ? 'last_gateway_success_at' : 'last_gateway_failure_at', now()->toIso8601String(), 30);
        if ($result->successful) {
            $this->finalizeCompleted($outbox->id);
        } else {
            $outbox->incident->events()->create(['event_type' => 'notification_failed', 'event_message' => ucfirst($outbox->phase).' notification failed after '.$attemptsMade.' attempt(s).', 'event_data' => ['destination_id' => $outbox->destination_id, 'outbox_id' => $outbox->id, 'error' => $safeError]]);
        }

        Log::channel('iapm')->log($result->successful ? 'info' : 'error', 'Notification delivery completed.', ['incident_id' => $outbox->incident_id, 'outbox_id' => $outbox->id, 'destination_id' => $outbox->destination_id, 'phase' => $outbox->phase, 'successful' => $result->successful, 'attempts' => (int) $outbox->attempt_count]);

        return $result;
    }

    /**
     * Re-enqueue due durable work independently of incident/action discovery.
     * This is what makes a queue outage recoverable even after an incident ages
     * out of the recently-recovered action scan.
     */
    public function enqueueDue(int $limit): int
    {
        $queued = 0;
        NotificationOutbox::query()->whereIn('status', ['sent', 'dry_run'])->whereNull('finalized_at')->orderBy('id')->limit(max(1, $limit))->pluck('id')->each(fn (int $id) => $this->finalizeCompleted($id));
        if (! $this->pluginEnabled() || (bool) $this->freshSetting('dry_run', true)) {
            return 0;
        }
        $synchronous = $this->freshSetting('dispatch_mode', 'queue') !== 'queue';
        $deadline = $synchronous ? microtime(true) + max(1, (int) config('iapm.processing.max_seconds', 50)) : PHP_FLOAT_MAX;
        $claimCutoff = now()->subSeconds(max(30, (int) config('iapm.queue.timeout', 60) + 30));
        $publicationCutoff = now()->subSeconds(max(60, (int) config('iapm.queue.publication_lease_seconds', 300)));
        // Publication and HTTP cannot participate in the same DB transaction.
        // Recover their expired leases independently of a surviving queue job.
        NotificationOutbox::where('status', 'processing')
            ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<=', $claimCutoff))
            ->update(['status' => 'pending', 'available_at' => now(), 'claimed_at' => null, 'claim_token' => null]);
        NotificationOutbox::where('status', 'queued')
            ->where(fn ($q) => $q->where('queued_at', '<=', $publicationCutoff)->orWhere(fn ($legacy) => $legacy->whereNull('queued_at')->where('updated_at', '<=', $publicationCutoff)))
            ->update(['status' => 'pending', 'available_at' => now(), 'queued_at' => null]);
        NotificationOutbox::query()
            ->whereIn('status', $synchronous ? ['pending', 'failed', 'queued'] : ['pending', 'failed'])
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->each(function (int $id) use (&$queued, $synchronous, $deadline): bool {
                if (microtime(true) >= $deadline) {
                    return false;
                }
                if ($synchronous) {
                    // The same atomic delivery claim is shared with any surviving
                    // queue job, so changing mode cannot create two HTTP owners.
                    $this->deliverOutbox($id);
                    $queued++;

                    return true;
                }
                $claimed = NotificationOutbox::whereKey($id)
                    ->whereIn('status', ['pending', 'failed'])
                    ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                    ->update(['status' => 'queued', 'queued_at' => now(), 'claimed_at' => null, 'claim_token' => null]);
                if ($claimed !== 1) {
                    return true;
                }
                try {
                    SendNotificationJob::dispatch($id);
                    $queued++;
                } catch (\Throwable $exception) {
                    NotificationOutbox::whereKey($id)->where('status', 'queued')->update([
                        'status' => 'pending',
                        'queued_at' => null,
                        'available_at' => now()->addSeconds(5),
                        'last_error_redacted' => 'Queue dispatch unavailable; durable outbox remains pending.',
                    ]);
                    Log::channel('iapm')->error('Outbox requeue failed; durable row remains pending.', ['outbox_id' => $id, 'error' => $this->redactor->text($exception->getMessage())]);
                }

                return true;
            });

        return $queued;
    }

    private function pluginEnabled(): bool
    {
        try {
            // The plugin manager caches its collection for the life of a worker.
            return DB::table('plugins')->where('version', 2)->where('plugin_name', IapmServiceProvider::PLUGIN_NAME)->where('plugin_active', true)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function freshSetting(string $key, mixed $default): mixed
    {
        $this->settings->forget($key);

        return $this->settings->get($key, $default);
    }

    private function phaseInvalid(Incident $incident, ?PolicyAction $action, string $phase, bool $actionRequired): bool
    {
        if (! $incident->policy?->enabled || ! $incident->policy->notifications_enabled) {
            return true;
        }
        if ($actionRequired && (! $action || ! $action->enabled || (int) $action->policy_id !== (int) $incident->policy_id)) {
            return true;
        }
        if ($action && $action->phase->value !== (in_array($phase, ['digest', 'flapping'], true) ? 'trigger' : $phase)) {
            return true;
        }

        return match ($phase) {
            'recovery' => $incident->state !== IncidentState::Recovered || ! $incident->policy->notify_recovery,
            'acknowledged' => $incident->state !== IncidentState::Acknowledged,
            default => $incident->state !== IncidentState::Active || $incident->recovered_at !== null,
        };
    }

    private function occurrenceDue(Incident $incident, ?PolicyAction $action, string $phase, int $completed, ?CarbonInterface $lastCompleted): bool
    {
        $start = match ($phase) {
            'recovery' => $incident->recovered_at,
            'acknowledged' => $incident->acknowledged_at,
            default => $incident->triggered_at ?? $incident->first_seen_at,
        };
        if (! $start || $start->copy()->addSeconds((int) ($action->delay_seconds ?? 0))->isFuture()) {
            return false;
        }
        if ($completed === 0) {
            return true;
        }
        if (in_array($phase, ['digest', 'flapping'], true)) {
            return false;
        }
        $repeat = $action->repeat_seconds ?? (in_array($phase, ['trigger', 'reminder'], true) ? $incident->policy?->repeat_seconds : null);
        $maximum = $action->maximum_sends ?? ($incident->policy?->maximum_repeats === null ? null : 1 + $incident->policy->maximum_repeats);

        return $repeat !== null && ($maximum === null || $completed < $maximum)
            && ($lastCompleted === null || ! $lastCompleted->copy()->addSeconds((int) $repeat)->isFuture());
    }

    private function triggerCovered(Incident $incident, int $destinationId, string $receiverHash): bool
    {
        return DB::table('iapm_notification_outbox_incidents as membership')
            ->join('iapm_notification_outbox as outbox', 'outbox.id', '=', 'membership.notification_outbox_id')
            ->where('membership.incident_id', $incident->id)->where('membership.episode_uuid', $incident->episode_uuid)
            ->where('outbox.destination_id', $destinationId)->where('outbox.receiver_hash', $receiverHash)
            ->where('outbox.phase', 'digest')->whereIn('outbox.status', ['pending', 'queued', 'processing', 'sent', 'dry_run'])->exists();
    }

    private function digestMemberCovered(Incident $incident, int $destinationId, string $receiverHash): bool
    {
        return DB::table('iapm_notification_outbox_incidents as membership')
            ->join('iapm_notification_outbox as outbox', 'outbox.id', '=', 'membership.notification_outbox_id')
            ->where('membership.incident_id', $incident->id)->where('membership.episode_uuid', $incident->episode_uuid)
            ->where('outbox.destination_id', $destinationId)->where('outbox.receiver_hash', $receiverHash)
            ->whereIn('outbox.phase', ['trigger', 'digest'])
            ->whereIn('outbox.status', ['pending', 'queued', 'processing', 'failed', 'sent', 'dry_run'])->exists();
    }

    /** Recheck mutable stop controls before every HTTP attempt, including retries. */
    private function deliveryBlocked(NotificationOutbox $outbox): ?TransportResult
    {
        return DB::transaction(function () use ($outbox): ?TransportResult {
            $ids = DB::table('iapm_notification_outbox_incidents')->where('notification_outbox_id', $outbox->id)->pluck('incident_id');
            Incident::whereIn('id', $ids->push($outbox->incident_id))->orderBy('id')->lockForUpdate()->get();

            return $this->checkDeliveryControls($outbox);
        }, 3);
    }

    private function checkDeliveryControls(NotificationOutbox $outbox): ?TransportResult
    {
        $current = $outbox->fresh(['incident.policy', 'destination', 'action']);
        if (! $current || $current->status !== 'processing' || $current->claim_token !== $outbox->claim_token) {
            return new TransportResult(true, null, 'claim no longer owned');
        }
        $outbox->setRawAttributes($current->getAttributes(), true);
        $outbox->setRelations($current->getRelations());
        if (! $this->pluginEnabled() || (bool) $this->freshSetting('dry_run', true)) {
            return $this->releaseClaim($outbox, 'Delivery paused by plugin enablement or dry-run.');
        }
        $incident = $outbox->incident;
        if (! $incident || (string) $incident->episode_uuid !== (string) $outbox->episode_uuid) {
            return $this->cancelClaim($outbox, 'The outage episode has ended or been replaced.');
        }
        if ($this->phaseInvalid($incident, $outbox->action, $outbox->phase, (bool) $outbox->action_required)
            || ($outbox->action && (int) $outbox->action->destination_id !== (int) $outbox->destination_id)) {
            return $this->cancelClaim($outbox, 'The notification phase or action no longer applies.');
        }
        if (! $outbox->destination?->enabled) {
            return $this->releaseClaim($outbox, 'Destination is disabled.', 300);
        }
        if ($incident->muted_until?->isFuture()) {
            return $this->releaseClaim($outbox, 'Notifications are muted.');
        }
        if (! $outbox->forced && ! $this->occurrenceDue($incident, $outbox->action, $outbox->phase, 0, null)) {
            return $this->releaseClaim($outbox, 'The action delay has not elapsed.');
        }
        if ($outbox->phase === 'trigger' && $this->triggerCovered($incident, $outbox->destination_id, $outbox->receiver_hash)) {
            return $this->cancelClaim($outbox, 'Trigger is represented by a device digest.');
        }
        if ($outbox->phase === 'digest') {
            $links = DB::table('iapm_notification_outbox_incidents')->where('notification_outbox_id', $outbox->id)->pluck('episode_uuid', 'incident_id');
            $members = Incident::with('policy')->whereIn('id', $links->keys())->get();
            if ($members->count() !== $links->count()) {
                return $this->cancelClaim($outbox, 'Digest membership no longer exists.');
            }
            $actions = PolicyAction::whereIn('policy_id', $members->pluck('policy_id')->unique())
                ->where('enabled', true)->where('phase', 'trigger')->where('destination_id', $outbox->destination_id)->get()->groupBy('policy_id');
            $individuals = NotificationOutbox::whereIn('incident_id', $members->modelKeys())
                ->where('destination_id', $outbox->destination_id)->where('receiver_hash', $outbox->receiver_hash)
                ->where('phase', 'trigger')->whereIn('status', ['pending', 'queued', 'processing', 'sent', 'dry_run'])
                ->get(['incident_id', 'episode_uuid'])->keyBy(fn (NotificationOutbox $item): string => $item->incident_id.'|'.$item->episode_uuid);
            foreach ($members as $member) {
                if ((string) $member->episode_uuid !== (string) $links[$member->id] || $this->phaseInvalid($member, null, 'digest', false)) {
                    return $this->cancelClaim($outbox, 'A represented incident no longer requires a trigger.');
                }
                $applicable = $actions->get($member->policy_id, collect())
                    ->contains(fn (PolicyAction $action): bool => $this->occurrenceDue($member, $action, 'digest', 0, null));
                if (! $applicable) {
                    return $this->cancelClaim($outbox, 'A represented trigger action no longer applies.');
                }
                if ($member->muted_until?->isFuture()) {
                    return $this->releaseClaim($outbox, 'A represented incident is muted.');
                }
                if ($individuals->has($member->id.'|'.$member->episode_uuid)) {
                    return $this->cancelClaim($outbox, 'Individual fallback already represents this digest.');
                }
            }
        }

        return null;
    }

    private function releaseClaim(NotificationOutbox $outbox, string $reason, int $seconds = 60): TransportResult
    {
        NotificationOutbox::whereKey($outbox->id)->where('claim_token', $outbox->claim_token)->update([
            'status' => 'pending', 'available_at' => now()->addSeconds($seconds),
            'claimed_at' => null, 'claim_token' => null, 'queued_at' => null, 'last_error_redacted' => $reason,
        ]);

        return new TransportResult(false, null, 'paused', $reason);
    }

    private function cancelClaim(NotificationOutbox $outbox, string $reason): TransportResult
    {
        NotificationOutbox::whereKey($outbox->id)->where('claim_token', $outbox->claim_token)->update([
            'status' => 'cancelled', 'available_at' => null, 'claimed_at' => null,
            'claim_token' => null, 'queued_at' => null, 'last_error_redacted' => $reason,
        ]);

        return new TransportResult(true, null, 'cancelled');
    }

    /** Backward-compatible synchronous entry point; still uses the durable outbox. */
    public function performSync(Incident $incident, Destination $destination, ?PolicyAction $action, string $phase, string $receiver, string $message): TransportResult
    {
        return $this->dispatch($incident, $destination, $action, $phase, $receiver, $message, forceSync: true);
    }

    public function test(Destination $destination, string $receiver, string $message): TransportResult
    {
        if ($destination->type === 'sms_gateway') {
            $message = $this->smsFilters->filter($message);
        }
        $configuration = (array) $destination->configuration_encrypted;
        $result = $this->transports->for($destination->type)->send($configuration, $receiver, $message);
        // Test gateways sometimes echo their request body. The administrator may
        // inspect the live result, but durable logs must not retain the receiver or
        // message plaintext even when there is no outbox row to redact against.
        $sensitive = array_filter([$receiver, $message], fn (string $value): bool => $value !== '');
        $replace = fn (?string $value): ?string => $value === null ? null : str_replace($sensitive, '[REDACTED]', $value);
        $logged = new TransportResult($result->successful, $result->status, $replace($result->response), $replace($result->error), $result->retryAfterSeconds);
        $this->record(null, $destination, null, 'test', null, $logged, $result->successful ? 'sent' : 'failed');
        Log::channel('iapm')->log($result->successful ? 'info' : 'error', 'Destination test completed.', ['destination_id' => $destination->id, 'successful' => $result->successful, 'status' => $result->status]);

        return $result;
    }

    private function completeDryRun(NotificationOutbox $outbox): TransportResult
    {
        return DB::transaction(function () use ($outbox): TransportResult {
            $locked = NotificationOutbox::whereKey($outbox->id)->lockForUpdate()->firstOrFail();
            $result = new TransportResult(true, null, 'dry-run');
            if (in_array($locked->status, ['sent', 'dry_run', 'cancelled'], true)) {
                return $result;
            }
            $locked->update(['status' => 'dry_run', 'delivered_at' => now()]);
            $this->record($locked->incident, $locked->destination, $locked->action, $locked->phase, $locked, $result, 'dry_run');
            $this->finalizeCompleted($locked->id);

            return $result;
        });
    }

    private function finalizeCompleted(int $outboxId): void
    {
        DB::transaction(function () use ($outboxId): void {
            $outbox = NotificationOutbox::whereKey($outboxId)->lockForUpdate()->first();
            if (! $outbox || $outbox->finalized_at !== null || ! in_array($outbox->status, ['sent', 'dry_run'], true)) {
                return;
            }
            $this->finalize($outbox, $outbox->status === 'dry_run');
            $outbox->update(['finalized_at' => now()]);
            if ($outbox->phase === 'recovery' && $outbox->incident) {
                app(RecoveryActionScheduler::class)->scheduleNext($outbox->incident);
            }
        });
    }

    private function finalize(NotificationOutbox $outbox, bool $dryRun): void
    {
        DB::table('iapm_notification_outbox_incidents')->where('notification_outbox_id', $outbox->id)->orderBy('incident_id')->chunkById(500, function ($links) use ($outbox, $dryRun): void {
            $episodes = $links->pluck('episode_uuid', 'incident_id');
            $now = now();
            $events = [];
            // Lock incidents in primary-key order before checking episode UUID.
            // Otherwise a recovery/reopen could occur between the check and the
            // bookkeeping update, letting an old delivery mutate the new episode.
            $incidents = Incident::whereIn('id', $links->pluck('incident_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($incidents as $incident) {
                // Never let a delayed job mutate a later outage on the reused row.
                if ((string) $incident->episode_uuid !== (string) $episodes->get($incident->id)) {
                    continue;
                }
                $attributes = ['last_notification_at' => $now];
                if (! $dryRun) {
                    $attributes['notification_count'] = DB::raw('notification_count + 1');
                }
                if ($outbox->phase === 'digest') {
                    $context = (array) $incident->context_json;
                    $context['trigger_notified_via_digest'] = $now->toIso8601String();
                    $attributes['context_json'] = $context;
                }
                $incident->update($attributes);
                if (! $dryRun) {
                    DB::table('iapm_outages')->where('incident_id', $incident->id)->where('episode_uuid', $incident->episode_uuid)->increment('notification_count');
                }
                $events[] = ['incident_id' => $incident->id, 'event_type' => $dryRun ? 'notification_suppressed' : ($outbox->phase === 'reminder' ? 'reminder_sent' : 'notification_sent'), 'event_message' => $dryRun ? 'Dry-run: '.$outbox->phase.' notification recorded.' : ucfirst($outbox->phase).' notification sent.', 'event_data' => json_encode(['destination_id' => $outbox->destination_id, 'outbox_id' => $outbox->id]), 'actor_user_id' => null, 'created_at' => $now, 'updated_at' => $now];
                if ($outbox->phase === 'digest') {
                    $events[] = ['incident_id' => $incident->id, 'event_type' => 'digested', 'event_message' => 'Trigger delivered as part of a device digest.', 'event_data' => json_encode(['outbox_id' => $outbox->id]), 'actor_user_id' => null, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            if ($events !== []) {
                DB::table('iapm_incident_events')->insert($events);
            }
        }, 'incident_id');
    }

    private function record(?Incident $incident, Destination $destination, ?PolicyAction $action, string $phase, ?NotificationOutbox $outbox, TransportResult $result, string $status, int $attempt = 1): DeliveryLog
    {
        return DeliveryLog::create([
            'notification_outbox_id' => $outbox?->id,
            'incident_id' => $incident?->id,
            // The incident row is reused across outage episodes. A delayed queue
            // job can therefore load an Incident whose current episode differs
            // from the durable outbox being delivered. Keep the delivery attached
            // to the logical episode captured when the outbox row was created.
            'episode_uuid' => $outbox instanceof NotificationOutbox ? $outbox->episode_uuid : $incident?->episode_uuid,
            'destination_id' => $destination->id,
            'policy_action_id' => $action?->id,
            'phase' => $phase,
            'logical_notification_key' => $outbox?->idempotency_key,
            'receiver_hash' => $outbox?->receiver_hash,
            'attempt' => $attempt,
            'status' => $status,
            'request_url' => $this->redactor->text((string) ($destination->configuration_encrypted['url'] ?? '')),
            'request_payload_redacted' => json_encode(['receiver' => '[REDACTED]', 'message' => '[REDACTED]']),
            'response_status' => $result->status,
            'response_body_redacted' => $this->redactPayloadEcho((string) $result->response, $outbox),
            'error_message' => $this->redactPayloadEcho((string) $result->error, $outbox),
            'sent_at' => $result->successful ? now() : null,
        ]);
    }

    private function redactPayloadEcho(string $value, ?NotificationOutbox $outbox): string
    {
        return $this->redactor->text($value, $outbox ? [(string) $outbox->receiver_encrypted, (string) $outbox->message_encrypted] : []);
    }

    /**
     * Fit the worst-case attempt sequence inside the worker's delivery budget.
     * Attempts are dropped first (the durable outbox retries later anyway with
     * proper backoff); only when one attempt alone cannot fit is the per-request
     * timeout reduced.
     *
     * @return array{int, int} clamped attempts and timeout
     */
    private function clampToWorkerBudget(int $attempts, int $timeout, int $retryDelayMs, int $outboxId): array
    {
        $budget = max(1, (int) floor(max(1, (int) config('iapm.queue.timeout', 60)) * (float) config('iapm.queue.delivery_budget_ratio', 0.8)));
        $worstCase = fn (int $count): float => $count * $timeout + ($count - 1) * ($retryDelayMs / 1000);
        if ($worstCase($attempts) <= $budget) {
            return [$attempts, $timeout];
        }

        $allowed = $attempts;
        while ($allowed > 1 && $worstCase($allowed) > $budget) {
            $allowed--;
        }
        $clampedTimeout = $worstCase($allowed) > $budget ? max(1, $budget) : $timeout;
        Log::channel('iapm')->warning('Delivery configuration exceeds the worker budget; clamped for this attempt.', ['outbox_id' => $outboxId, 'configured_attempts' => $attempts, 'allowed_attempts' => $allowed, 'configured_timeout' => $timeout, 'allowed_timeout' => $clampedTimeout, 'budget_seconds' => $budget]);

        return [$allowed, $clampedTimeout];
    }

    private function retryDelay(NotificationOutbox $outbox, TransportResult $result): int
    {
        $base = max(1, (int) config('iapm.queue.retry_base_seconds', 15));
        $maximum = max($base, (int) config('iapm.queue.retry_max_seconds', 3600));
        if ($result->retryAfterSeconds !== null) {
            // A gateway's cooldown is a lower bound, independent of our cap on
            // exponential backoff. Shortening it can prolong a rate-limit outage.
            return max(1, $result->retryAfterSeconds);
        }
        $exponent = min(10, max(0, (int) $outbox->attempt_count - 1));
        $delay = min($maximum, $base * (2 ** $exponent));

        return min($maximum, $delay + random_int(0, max(1, intdiv($delay, 4))));
    }
}
