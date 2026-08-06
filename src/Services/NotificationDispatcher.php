<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Transports\TransportResult;

class NotificationDispatcher
{
    public function __construct(private readonly TransportManager $transports, private readonly Redactor $redactor, private readonly SettingStore $settings) {}

    public function configurationFailure(Incident $incident, Destination $destination, ?PolicyAction $action, string $phase, string $error): TransportResult
    {
        $result = new TransportResult(false, null, null, $error);
        $this->record($incident, $destination, $action, $phase, '', $result, 'failed_configuration');
        $incident->events()->create(['event_type' => 'notification_failed', 'event_message' => ucfirst($phase).' notification configuration failed.', 'event_data' => ['destination_id' => $destination->id, 'error' => $error]]);

        return $result;
    }

    /**
     * Route a single notification: dry-run and disabled-destination handling, then
     * either enqueue it (dispatch_mode=queue) for a worker to deliver, or deliver it
     * synchronously here. The queue path records a "queued" marker so the caller's
     * dedup guard won't re-enqueue while the job is in flight.
     */
    public function dispatch(Incident $incident, Destination $destination, ?PolicyAction $action, string $phase, string $receiver, string $message): TransportResult
    {
        if ($this->settings->get('dry_run', true)) {
            $this->record($incident, $destination, $action, $phase, $receiver, new TransportResult(true, null, 'dry-run'), 'dry_run');
            $incident->events()->create(['event_type' => 'notification_suppressed', 'event_message' => "Dry-run: $phase notification would be sent.", 'event_data' => ['destination_id' => $destination->id]]);

            return new TransportResult(true, null, 'dry-run');
        }

        if (! $destination->enabled) {
            return $this->configurationFailure($incident, $destination, $action, $phase, 'Destination is disabled.');
        }

        if ($this->settings->get('dispatch_mode', 'sync') === 'queue') {
            $marker = null;
            try {
                $marker = $this->record($incident, $destination, $action, $phase, $receiver, new TransportResult(false, null, null, 'Queued for delivery.'), 'queued');
                \LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\SendNotificationJob::dispatch($incident->id, $destination->id, $action?->id, $phase, $receiver, $message, $marker->id);
                $incident->events()->create(['event_type' => 'notification_queued', 'event_message' => ucfirst($phase).' notification queued for delivery.', 'event_data' => ['destination_id' => $destination->id]]);

                return new TransportResult(true, null, 'queued');
            } catch (\Throwable $e) {
                // Queue backend unavailable (e.g. no jobs table, unreachable redis): never
                // drop a notification — clear the marker and deliver synchronously instead.
                if ($marker) { try { $marker->delete(); } catch (\Throwable) {} }
                \Illuminate\Support\Facades\Log::channel('iapm')->error('Queued dispatch failed; delivering synchronously. Check the queue connection/worker.', ['incident_id' => $incident->id, 'error' => $e->getMessage()]);
            }
        }

        return $this->performSync($incident, $destination, $action, $phase, $receiver, $message);
    }

    /**
     * Perform the actual synchronous transport send with retry/backoff and record
     * the outcome. Called directly in sync mode, and by SendNotificationJob when a
     * worker delivers a queued notification.
     */
    public function performSync(Incident $incident, Destination $destination, ?PolicyAction $action, string $phase, string $receiver, string $message): TransportResult
    {
        $configuration = (array) $destination->configuration_encrypted;
        $attempts = 1 + max(0, min(10, (int) ($configuration['retry_count'] ?? config('iapm.http.retries', 2))));
        $result = new TransportResult(false, null, null, 'No delivery attempt was made.');

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $result = $this->transports->for($destination->type)->send($configuration, $receiver, $message);
            $this->record($incident, $destination, $action, $phase, $receiver, $result, $result->successful ? 'sent' : 'failed', $attempt);
            if ($result->successful) {
                break;
            }
            if ($attempt < $attempts) {
                usleep(max(0, min(60000, (int) ($configuration['retry_delay_ms'] ?? config('iapm.http.retry_delay_ms', 500)))) * 1000);
            }
        }

        \Illuminate\Support\Facades\Log::channel('iapm')->log($result->successful ? 'info' : 'error', 'Notification delivery completed.', ['incident_id' => $incident->id, 'destination_id' => $destination->id, 'phase' => $phase, 'successful' => $result->successful, 'attempts' => $attempt, 'status' => $result->status, 'error' => $result->error]);
        $this->settings->put($result->successful ? 'last_gateway_success_at' : 'last_gateway_failure_at', now()->toIso8601String());

        if ($result->successful) {
            $incident->increment('notification_count');
            $incident->update(['last_notification_at' => now()]);
            $incident->events()->create(['event_type' => $phase === 'reminder' ? 'reminder_sent' : 'notification_sent', 'event_message' => ucfirst($phase).' notification sent.', 'event_data' => ['destination_id' => $destination->id]]);
        } else {
            $incident->events()->create(['event_type' => 'notification_failed', 'event_message' => ucfirst($phase).' notification failed after '.$attempts.' attempt(s).', 'event_data' => ['destination_id' => $destination->id, 'error' => $result->error]]);
        }

        return $result;
    }

    /**
     * A controlled, administrator-initiated test send. It ignores dry-run
     * because the operator explicitly asked for a real delivery, and it is
     * recorded in the delivery log like any other attempt.
     */
    public function test(Destination $destination, string $receiver, string $message): TransportResult
    {
        $result = $this->transports->for($destination->type)->send((array) $destination->configuration_encrypted, $receiver, $message);
        $this->record(null, $destination, null, 'test', $receiver, $result, $result->successful ? 'sent' : 'failed');

        \Illuminate\Support\Facades\Log::channel('iapm')->log($result->successful ? 'info' : 'error', 'Destination test completed.', ['destination_id' => $destination->id, 'successful' => $result->successful, 'status' => $result->status, 'error' => $result->error]);

        return $result;
    }

    private function record(?Incident $incident, Destination $destination, ?PolicyAction $action, string $phase, string $receiver, TransportResult $result, string $status, int $attempt = 1): DeliveryLog
    {
        return DeliveryLog::create([
            'incident_id' => $incident?->id,
            'destination_id' => $destination->id,
            'policy_action_id' => $action?->id,
            'phase' => $phase,
            'attempt' => $attempt,
            'status' => $status,
            'request_url' => $this->redactor->text((string) ($destination->configuration_encrypted['url'] ?? '')),
            'request_payload_redacted' => json_encode(['receiver' => $receiver, 'message' => '[MESSAGE REDACTED]']),
            'response_status' => $result->status,
            'response_body_redacted' => $result->response,
            'error_message' => $result->error,
            'sent_at' => $result->successful ? now() : null,
        ]);
    }
}
