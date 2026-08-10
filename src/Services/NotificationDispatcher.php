<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\SendNotificationJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Transports\TransportResult;

class NotificationDispatcher
{
    public function __construct(private readonly TransportManager $transports, private readonly Redactor $redactor, private readonly SettingStore $settings) {}

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
    public function dispatch(Incident $incident, Destination $destination, ?PolicyAction $action, string $phase, string $receiver, string $message, ?array $incidentIds = null, bool $forceSync = false): TransportResult
    {
        if (! $destination->enabled) {
            return $this->configurationFailure($incident, $destination, $action, $phase, 'Destination is disabled.');
        }

        $incidents = Incident::whereIn('id', $incidentIds ?: [$incident->id])->get()->filter(fn (Incident $item) => filled($item->episode_uuid));
        if ($incidents->isEmpty()) {
            return $this->configurationFailure($incident, $destination, $action, $phase, 'Incident has no outage episode identity; run migrations.');
        }
        $members = $incidents->sortBy('id')->map(fn (Incident $item): string => $item->id.':'.$item->episode_uuid)->values();
        $receiverHash = hash('sha256', $receiver);
        $base = implode('|', [$phase, (string) $destination->id, (string) ($action?->id ?? 0), $receiverHash, hash('sha256', $members->implode('|'))]);
        $successfulCount = NotificationOutbox::where('incident_id', $incident->id)->where('episode_uuid', $incident->episode_uuid)->where('policy_action_id', $action?->id)->where('phase', $phase)->where('receiver_hash', $receiverHash)->whereIn('status', ['sent', 'dry_run'])->count();
        $key = hash('sha256', $base.'|'.($successfulCount + 1));

        try {
            $outbox = DB::transaction(function () use ($key, $incident, $destination, $action, $phase, $receiver, $receiverHash, $message, $incidents): NotificationOutbox {
                $existing = NotificationOutbox::where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->status === 'failed' && ($existing->available_at === null || $existing->available_at->isPast())) {
                        $existing->update(['status' => 'pending', 'available_at' => now(), 'last_error_redacted' => null]);
                    }

                    return $existing;
                }
                $outbox = NotificationOutbox::create([
                    'idempotency_key' => $key,
                    'episode_uuid' => $incident->episode_uuid,
                    'incident_id' => $incident->id,
                    'destination_id' => $destination->id,
                    'policy_action_id' => $action?->id,
                    'phase' => $phase,
                    'receiver_hash' => $receiverHash,
                    'receiver_encrypted' => $receiver,
                    'message_encrypted' => $message,
                    // Canonical membership lives in the indexed pivot. Never place a
                    // multi-thousand-ID digest array in one encrypted database value.
                    'incident_ids_encrypted' => [],
                    'status' => 'pending',
                    'available_at' => now(),
                ]);
                DB::table('iapm_notification_outbox_incidents')->insert($incidents->map(fn (Incident $item) => ['notification_outbox_id' => $outbox->id, 'incident_id' => $item->id, 'episode_uuid' => $item->episode_uuid])->all());

                return $outbox;
            });
        } catch (UniqueConstraintViolationException) {
            $outbox = NotificationOutbox::where('idempotency_key', $key)->firstOrFail();
        }

        if (in_array($outbox->status, ['sent', 'dry_run'], true)) {
            if ($outbox->finalized_at === null) {
                $this->finalizeCompleted($outbox->id);
            }

            return new TransportResult(true, null, $outbox->status);
        }
        if (in_array($outbox->status, ['queued', 'processing'], true)) {
            return new TransportResult(true, null, $outbox->status);
        }
        if ($outbox->available_at?->isFuture()) {
            return new TransportResult(false, null, 'retry_scheduled', 'Notification is durably retained for a later retry.');
        }

        if ((bool) $this->settings->get('dry_run', true)) {
            return $this->completeDryRun($outbox);
        }

        if (! $forceSync && $this->settings->get('dispatch_mode', 'queue') === 'queue') {
            $claimedForQueue = NotificationOutbox::whereKey($outbox->id)->where('status', 'pending')->update(['status' => 'queued']);
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
            if (! $row || (in_array($row->status, ['sent', 'dry_run'], true) && $row->finalized_at !== null)) {
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
            $row->update(['status' => 'processing', 'claimed_at' => now()]);

            return $row->fresh(['incident', 'destination', 'action']);
        });
        if (! $outbox) {
            return new TransportResult(true, null, 'already claimed or complete');
        }
        if (in_array($outbox->status, ['sent', 'dry_run'], true)) {
            $this->finalizeCompleted($outbox->id);

            return new TransportResult(true, null, $outbox->status);
        }
        if (! $outbox->incident || ! $outbox->destination) {
            NotificationOutbox::whereKey($outboxId)->update(['status' => 'failed', 'last_error_redacted' => 'Referenced incident or destination no longer exists.']);

            return new TransportResult(false, null, null, 'Referenced incident or destination no longer exists.');
        }
        if (! $outbox->destination->enabled) {
            NotificationOutbox::whereKey($outboxId)->update(['status' => 'failed', 'available_at' => now()->addMinutes(5), 'last_error_redacted' => 'Destination is disabled.']);

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
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $result = $this->transports->for($outbox->destination->type)->send($configuration, (string) $outbox->receiver_encrypted, (string) $outbox->message_encrypted);
            $outbox->increment('attempt_count');
            $this->record($outbox->incident, $outbox->destination, $outbox->action, $outbox->phase, $outbox, $result, $result->successful ? 'sent' : 'failed', $attempt);
            if ($result->successful) {
                break;
            }
            if ($result->status === 429 && $result->retryAfterSeconds !== null) {
                break;
            }
            if ($attempt < $attempts) {
                usleep(max(0, min(60000, (int) ($configuration['retry_delay_ms'] ?? config('iapm.http.retry_delay_ms', 500)))) * 1000);
            }
        }

        $safeError = $this->redactPayloadEcho((string) $result->error, $outbox);
        $retryAt = $result->successful ? null : now()->addSeconds($this->retryDelay($outbox, $result));
        $outbox->update(['status' => $result->successful ? 'sent' : 'failed', 'available_at' => $retryAt, 'delivered_at' => $result->successful ? now() : null, 'last_error_redacted' => $result->successful ? null : $safeError]);
        $this->settings->putThrottled($result->successful ? 'last_gateway_success_at' : 'last_gateway_failure_at', now()->toIso8601String(), 30);
        if ($result->successful) {
            $this->finalizeCompleted($outbox->id);
        } else {
            $outbox->incident->events()->create(['event_type' => 'notification_failed', 'event_message' => ucfirst($outbox->phase).' notification failed after '.$attempts.' attempt(s).', 'event_data' => ['destination_id' => $outbox->destination_id, 'outbox_id' => $outbox->id, 'error' => $safeError]]);
        }

        Log::channel('iapm')->log($result->successful ? 'info' : 'error', 'Notification delivery completed.', ['incident_id' => $outbox->incident_id, 'outbox_id' => $outbox->id, 'destination_id' => $outbox->destination_id, 'phase' => $outbox->phase, 'successful' => $result->successful, 'attempts' => $attempt]);

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
        NotificationOutbox::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->each(function (int $id) use (&$queued): void {
                $claimed = NotificationOutbox::whereKey($id)
                    ->whereIn('status', ['pending', 'failed'])
                    ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                    ->update(['status' => 'queued', 'claimed_at' => null]);
                if ($claimed !== 1) {
                    return;
                }
                try {
                    SendNotificationJob::dispatch($id);
                    $queued++;
                } catch (\Throwable $exception) {
                    NotificationOutbox::whereKey($id)->where('status', 'queued')->update([
                        'status' => 'pending',
                        'available_at' => now()->addSeconds(5),
                        'last_error_redacted' => 'Queue dispatch unavailable; durable outbox remains pending.',
                    ]);
                    Log::channel('iapm')->error('Outbox requeue failed; durable row remains pending.', ['outbox_id' => $id, 'error' => $this->redactor->text($exception->getMessage())]);
                }
            });

        return $queued;
    }

    /** Backward-compatible synchronous entry point; still uses the durable outbox. */
    public function performSync(Incident $incident, Destination $destination, ?PolicyAction $action, string $phase, string $receiver, string $message): TransportResult
    {
        return $this->dispatch($incident, $destination, $action, $phase, $receiver, $message, forceSync: true);
    }

    public function test(Destination $destination, string $receiver, string $message): TransportResult
    {
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
        $result = new TransportResult(true, null, 'dry-run');
        $outbox->update(['status' => 'dry_run', 'delivered_at' => now()]);
        $this->record($outbox->incident, $outbox->destination, $outbox->action, $outbox->phase, $outbox, $result, 'dry_run');
        $this->finalizeCompleted($outbox->id);

        return $result;
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
        $value = $this->redactor->text($value);
        if (! $outbox) {
            return $value;
        }

        $sensitive = array_filter([(string) $outbox->receiver_encrypted, (string) $outbox->message_encrypted], fn (string $item) => $item !== '');

        return str_replace($sensitive, '[REDACTED]', $value);
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
            return min($maximum, max(1, $result->retryAfterSeconds));
        }
        $exponent = min(10, max(0, (int) $outbox->attempt_count - 1));
        $delay = min($maximum, $base * (2 ** $exponent));

        return min($maximum, $delay + random_int(0, max(1, intdiv($delay, 4))));
    }
}
