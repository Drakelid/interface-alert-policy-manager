<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\SendNotificationJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
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
        self::assertTrue($dispatcher->dispatch($incident->fresh(), $action->destination, $action, 'trigger', 'noc', 'down')->successful);
        self::assertSame(1, NotificationOutbox::count());
        self::assertSame('sent', NotificationOutbox::first()->status);
        self::assertSame(2, $incident->deliveries()->count());
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
}
