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
        $episodes = $incidents->pluck('episode_uuid')->map(fn ($value) => (string) $value)->sort()->values()->all();
        $receiverHash = hash('sha256', $receiver);
        $base = implode('|', [$phase, (string) $destination->id, (string) ($action?->id ?? 0), $receiverHash, implode(',', $episodes)]);
        $successfulCount = NotificationOutbox::where('incident_id', $incident->id)->where('episode_uuid', $incident->episode_uuid)->where('policy_action_id', $action?->id)->where('phase', $phase)->where('receiver_hash', $receiverHash)->whereIn('status', ['sent', 'dry_run'])->count();
        $key = hash('sha256', $base.'|'.($successfulCount + 1));

        try {
            $outbox = DB::transaction(function () use ($key, $incident, $destination, $action, $phase, $receiver, $receiverHash, $message, $incidents): NotificationOutbox {
                $existing = NotificationOutbox::where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->status === 'failed') {
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
                    'incident_ids_encrypted' => $incidents->pluck('id')->map(fn ($id) => (int) $id)->all(),
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
            return new TransportResult(true, null, $outbox->status);
        }
        if (in_array($outbox->status, ['queued', 'processing'], true)) {
            return new TransportResult(true, null, $outbox->status);
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
                NotificationOutbox::whereKey($outbox->id)->where('status', 'queued')->update(['status' => 'pending']);
                Log::channel('iapm')->error('Queued dispatch failed; delivering synchronously.', ['incident_id' => $incident->id, 'outbox_id' => $outbox->id, 'error' => $this->redactor->text($exception->getMessage())]);
            }
        }

        return $this->deliverOutbox($outbox->id);
    }

    public function deliverOutbox(int $outboxId): TransportResult
    {
        $outbox = DB::transaction(function () use ($outboxId): ?NotificationOutbox {
            $row = NotificationOutbox::whereKey($outboxId)->lockForUpdate()->first();
            if (! $row || in_array($row->status, ['sent', 'dry_run'], true)) {
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
        if (! $outbox->incident || ! $outbox->destination) {
            NotificationOutbox::whereKey($outboxId)->update(['status' => 'failed', 'last_error_redacted' => 'Referenced incident or destination no longer exists.']);

            return new TransportResult(false, null, null, 'Referenced incident or destination no longer exists.');
        }

        $configuration = (array) $outbox->destination->configuration_encrypted;
        $configuration['_iapm_idempotency_key'] = $outbox->idempotency_key;
        $configuration['timeout'] ??= (int) $this->settings->get('notification_timeout', config('iapm.http.timeout', 15));
        $attempts = 1 + max(0, min(10, (int) ($configuration['retry_count'] ?? $this->settings->get('notification_retry_count', config('iapm.http.retries', 2)))));
        $result = new TransportResult(false, null, null, 'No delivery attempt was made.');
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $result = $this->transports->for($outbox->destination->type)->send($configuration, (string) $outbox->receiver_encrypted, (string) $outbox->message_encrypted);
            $outbox->increment('attempt_count');
            $this->record($outbox->incident, $outbox->destination, $outbox->action, $outbox->phase, $outbox, $result, $result->successful ? 'sent' : 'failed', $attempt);
            if ($result->successful) {
                break;
            }
            if ($attempt < $attempts) {
                usleep(max(0, min(60000, (int) ($configuration['retry_delay_ms'] ?? config('iapm.http.retry_delay_ms', 500)))) * 1000);
            }
        }

        $safeError = $this->redactPayloadEcho((string) $result->error, $outbox);
        $outbox->update(['status' => $result->successful ? 'sent' : 'failed', 'delivered_at' => $result->successful ? now() : null, 'last_error_redacted' => $result->successful ? null : $safeError]);
        $this->settings->put($result->successful ? 'last_gateway_success_at' : 'last_gateway_failure_at', now()->toIso8601String());
        if ($result->successful) {
            $this->finalize($outbox->fresh(), false);
        } else {
            $outbox->incident->events()->create(['event_type' => 'notification_failed', 'event_message' => ucfirst($outbox->phase).' notification failed after '.$attempts.' attempt(s).', 'event_data' => ['destination_id' => $outbox->destination_id, 'outbox_id' => $outbox->id, 'error' => $safeError]]);
        }

        Log::channel('iapm')->log($result->successful ? 'info' : 'error', 'Notification delivery completed.', ['incident_id' => $outbox->incident_id, 'outbox_id' => $outbox->id, 'destination_id' => $outbox->destination_id, 'phase' => $outbox->phase, 'successful' => $result->successful, 'attempts' => $attempt]);

        return $result;
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
        $this->record(null, $destination, null, 'test', null, $result, $result->successful ? 'sent' : 'failed');
        Log::channel('iapm')->log($result->successful ? 'info' : 'error', 'Destination test completed.', ['destination_id' => $destination->id, 'successful' => $result->successful, 'status' => $result->status]);

        return $result;
    }

    private function completeDryRun(NotificationOutbox $outbox): TransportResult
    {
        $result = new TransportResult(true, null, 'dry-run');
        $outbox->update(['status' => 'dry_run', 'delivered_at' => now()]);
        $this->record($outbox->incident, $outbox->destination, $outbox->action, $outbox->phase, $outbox, $result, 'dry_run');
        $this->finalize($outbox->fresh(), true);

        return $result;
    }

    private function finalize(NotificationOutbox $outbox, bool $dryRun): void
    {
        $ids = array_map('intval', (array) $outbox->incident_ids_encrypted);
        foreach (Incident::whereIn('id', $ids)->get() as $incident) {
            // Never let a delayed job mutate a later outage on the reused row.
            $linkEpisode = DB::table('iapm_notification_outbox_incidents')->where('notification_outbox_id', $outbox->id)->where('incident_id', $incident->id)->value('episode_uuid');
            if ((string) $incident->episode_uuid !== (string) $linkEpisode) {
                continue;
            }
            $attributes = ['last_notification_at' => now()];
            if (! $dryRun) {
                $attributes['notification_count'] = DB::raw('notification_count + 1');
            }
            if ($outbox->phase === 'digest') {
                $context = (array) $incident->context_json;
                $context['trigger_notified_via_digest'] = now()->toIso8601String();
                $attributes['context_json'] = $context;
            }
            $incident->update($attributes);
            $incident->events()->create(['event_type' => $dryRun ? 'notification_suppressed' : ($outbox->phase === 'reminder' ? 'reminder_sent' : 'notification_sent'), 'event_message' => $dryRun ? 'Dry-run: '.$outbox->phase.' notification recorded.' : ucfirst($outbox->phase).' notification sent.', 'event_data' => ['destination_id' => $outbox->destination_id, 'outbox_id' => $outbox->id]]);
            if ($outbox->phase === 'digest') {
                $incident->events()->create(['event_type' => 'digested', 'event_message' => 'Trigger delivered as part of a device digest.', 'event_data' => ['outbox_id' => $outbox->id]]);
            }
        }
    }

    private function record(?Incident $incident, Destination $destination, ?PolicyAction $action, string $phase, ?NotificationOutbox $outbox, TransportResult $result, string $status, int $attempt = 1): DeliveryLog
    {
        return DeliveryLog::create([
            'notification_outbox_id' => $outbox?->id,
            'incident_id' => $incident?->id,
            'episode_uuid' => $incident?->episode_uuid,
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
}
