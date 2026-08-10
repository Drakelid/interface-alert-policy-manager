<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\SendNotificationJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\NotificationDispatcher;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class OutboxSafetyTest extends IntegrationTestCase
{
    public function test_scheduler_runs_do_not_duplicate_an_outbox_older_than_fifteen_minutes(): void
    {
        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions');
        NotificationOutbox::firstOrFail()->update(['created_at' => now()->subHours(4)]);
        $this->artisan('iapm:process-actions');
        $this->artisan('iapm:process-actions');

        self::assertSame(1, NotificationOutbox::count());
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_serialized_job_and_redacted_log_contain_no_receiver_or_message(): void
    {
        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));
        $receiver = '+47 99998888';
        $message = 'secret incident detail';

        app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, 'trigger', $receiver, $message);
        $outbox = NotificationOutbox::firstOrFail();
        $serialized = serialize(new SendNotificationJob($outbox->id));
        self::assertStringNotContainsString($receiver, $serialized);
        self::assertStringNotContainsString($message, $serialized);
        self::assertStringNotContainsString($receiver, (string) $outbox->getRawOriginal('receiver_encrypted'));
        self::assertStringNotContainsString($message, (string) $outbox->getRawOriginal('message_encrypted'));
        Http::fake(['*' => Http::response('ok', 200)]);
        (new SendNotificationJob($outbox->id))->handle(app(NotificationDispatcher::class), app(SettingStore::class));
        $storedLog = json_encode(DeliveryLog::firstOrFail()->toArray());
        self::assertStringNotContainsString($receiver, $storedLog);
        self::assertStringNotContainsString($message, $storedLog);
    }

    public function test_failed_delivery_reuses_the_same_logical_outbox_then_succeeds(): void
    {
        Http::fakeSequence()->push('failed', 500)->push('ok', 200);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination(['retry_count' => 0]));
        $incident = $this->incident($policy, $this->downPort($this->device()));
        $dispatcher = app(NotificationDispatcher::class);

        self::assertFalse($dispatcher->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'down')->successful);
        NotificationOutbox::sole()->update(['available_at' => now()->subSecond()]);
        self::assertTrue($dispatcher->dispatch($incident->fresh(), $action->destination, $action, 'trigger', 'noc', 'down')->successful);
        self::assertSame(1, NotificationOutbox::count());
        self::assertSame('sent', NotificationOutbox::first()->status);
        self::assertSame(2, $incident->deliveries()->count());
    }

    public function test_rate_limit_retry_after_is_persisted_without_blocking_worker_retries(): void
    {
        Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '120'])]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination(['retry_count' => 5]));
        $incident = $this->incident($policy, $this->downPort($this->device()));

        $result = app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'down');

        self::assertFalse($result->successful);
        self::assertSame(429, $result->status);
        self::assertSame(120, $result->retryAfterSeconds);
        Http::assertSentCount(1);
        $outbox = NotificationOutbox::sole();
        self::assertSame('failed', $outbox->status);
        self::assertSame(1, $outbox->attempt_count);
        self::assertTrue($outbox->available_at->between(now()->addSeconds(115), now()->addSeconds(125)));
    }

    public function test_drain_command_requeues_due_failed_work(): void
    {
        Queue::fake();
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));
        $outbox = $this->processingOutbox($incident, $action, 'due-retry', now()->subMinutes(10));
        $outbox->update(['status' => 'failed', 'available_at' => now()->subSecond()]);

        $this->artisan('iapm:drain-outbox')->assertExitCode(0);

        self::assertSame('queued', $outbox->fresh()->status);
        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->outboxId === $outbox->id);
    }

    public function test_gateway_echo_of_payload_is_removed_from_logs_and_errors(): void
    {
        $receiver = '+47 99998888';
        $message = 'sensitive interface description';
        Http::fake(['*' => Http::response("failed {$receiver} {$message}", 500)]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination(['retry_count' => 0]));
        $incident = $this->incident($policy, $this->downPort($this->device()));

        app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, 'trigger', $receiver, $message);

        $stored = json_encode(DeliveryLog::sole()->toArray());
        self::assertStringNotContainsString($receiver, $stored);
        self::assertStringNotContainsString($message, $stored);
        self::assertStringNotContainsString($receiver, (string) NotificationOutbox::sole()->last_error_redacted);
        self::assertStringNotContainsString($message, (string) NotificationOutbox::sole()->last_error_redacted);
    }

    public function test_destination_test_echo_is_removed_from_durable_logs(): void
    {
        $receiver = '+47 91112222';
        $message = 'sensitive destination test';
        Http::fake(['*' => Http::response("ok {$receiver} {$message}", 200)]);
        $destination = $this->smsDestination();

        $result = app(NotificationDispatcher::class)->test($destination, $receiver, $message);

        self::assertTrue($result->successful);
        self::assertStringContainsString($message, (string) $result->response);
        $stored = json_encode(DeliveryLog::sole()->toArray());
        self::assertStringNotContainsString($receiver, $stored);
        self::assertStringNotContainsString($message, $stored);
    }

    public function test_terminal_queue_failure_returns_queued_row_to_retryable_failed_state(): void
    {
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));
        $outbox = NotificationOutbox::create(['idempotency_key' => hash('sha256', 'job-failure'), 'episode_uuid' => $incident->episode_uuid, 'incident_id' => $incident->id, 'destination_id' => $action->destination_id, 'policy_action_id' => $action->id, 'phase' => 'trigger', 'receiver_hash' => hash('sha256', 'noc'), 'receiver_encrypted' => 'noc', 'message_encrypted' => 'down', 'incident_ids_encrypted' => [$incident->id], 'status' => 'queued']);

        (new SendNotificationJob($outbox->id))->failed(new \RuntimeException('secret receiver and message'));

        self::assertSame('failed', $outbox->fresh()->status);
        self::assertStringNotContainsString('secret receiver and message', (string) $outbox->fresh()->last_error_redacted);
    }

    public function test_a_delayed_job_from_an_old_episode_cannot_suppress_the_new_episode(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('dispatch_mode', 'queue');
        $policy = $this->defaultPolicy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        $port = $this->downPort($device);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]));
        $this->artisan('iapm:process-actions');
        $oldOutbox = NotificationOutbox::sole();
        $oldEpisode = $oldOutbox->episode_uuid;

        $this->ingest($this->alertPayload($device, [], 0, ['timestamp' => now()->addMinute()->toIso8601String()]));
        $this->ingest($this->alertPayload($device, [$this->fault($port)], 1, ['timestamp' => now()->addMinutes(2)->toIso8601String()]));
        $incident = $oldOutbox->incident()->firstOrFail();
        self::assertSame(IncidentState::Active, $incident->state);
        self::assertNotSame($oldEpisode, $incident->episode_uuid);

        (new SendNotificationJob($oldOutbox->id))->handle(app(NotificationDispatcher::class), app(SettingStore::class));

        $delivery = DeliveryLog::sole();
        self::assertSame($oldEpisode, $delivery->episode_uuid);
        self::assertSame(0, (int) $incident->fresh()->notification_count);
        self::assertNull($incident->fresh()->last_notification_at);

        // The old success must not satisfy the new episode's trigger. A new logical
        // outbox is queued when action processing sees the current active episode.
        $this->artisan('iapm:process-actions');
        self::assertSame(2, NotificationOutbox::count());
        self::assertTrue(NotificationOutbox::where('episode_uuid', $incident->episode_uuid)->where('status', 'queued')->exists());
    }

    public function test_a_stale_processing_claim_is_reclaimed_but_a_live_claim_is_not(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));
        $dispatcher = app(NotificationDispatcher::class);

        $stale = $this->processingOutbox($incident, $action, 'stale', now()->subMinutes(10));
        self::assertTrue($dispatcher->deliverOutbox($stale->id)->successful);
        self::assertSame('sent', $stale->fresh()->status);

        $live = $this->processingOutbox($incident, $action, 'live', now());
        self::assertTrue($dispatcher->deliverOutbox($live->id)->successful);
        self::assertSame('processing', $live->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_committed_transport_success_with_incomplete_finalization_is_repaired_without_resending(): void
    {
        Queue::fake();
        Http::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));
        app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'down');
        $outbox = NotificationOutbox::sole();

        // Simulate a worker crash after recording gateway success but before the
        // represented incident bookkeeping transaction starts.
        $outbox->update(['status' => 'sent', 'delivered_at' => now(), 'finalized_at' => null]);
        (new SendNotificationJob($outbox->id))->handle(app(NotificationDispatcher::class), app(SettingStore::class));
        (new SendNotificationJob($outbox->id))->handle(app(NotificationDispatcher::class), app(SettingStore::class));

        Http::assertNothingSent();
        self::assertNotNull($outbox->fresh()->finalized_at);
        self::assertSame(1, (int) $incident->fresh()->notification_count);
        self::assertSame(1, $incident->events()->where('event_type', 'notification_sent')->count());
    }

    private function processingOutbox(Incident $incident, PolicyAction $action, string $suffix, CarbonInterface $claimedAt): NotificationOutbox
    {
        $outbox = NotificationOutbox::create([
            'idempotency_key' => hash('sha256', 'processing-'.$suffix),
            'episode_uuid' => $incident->episode_uuid,
            'incident_id' => $incident->id,
            'destination_id' => $action->destination_id,
            'policy_action_id' => $action->id,
            'phase' => 'trigger',
            'receiver_hash' => hash('sha256', 'noc-'.$suffix),
            'receiver_encrypted' => 'noc-'.$suffix,
            'message_encrypted' => 'down-'.$suffix,
            'incident_ids_encrypted' => [$incident->id],
            'status' => 'processing',
            'claimed_at' => $claimedAt,
        ]);
        DB::table('iapm_notification_outbox_incidents')->insert([
            'notification_outbox_id' => $outbox->id,
            'incident_id' => $incident->id,
            'episode_uuid' => $incident->episode_uuid,
        ]);

        return $outbox;
    }
}
