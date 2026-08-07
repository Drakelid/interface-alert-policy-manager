<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\SendNotificationJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\NotificationDispatcher;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class QueueDispatchTest extends IntegrationTestCase
{
    public function test_queued_is_the_default_dispatch_mode(): void
    {
        Queue::fake();
        Http::fake();
        // Remove the test harness's sync override so the production default applies.
        \Illuminate\Support\Facades\DB::table('iapm_settings')->where('setting_key', 'dispatch_mode')->delete();
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        Queue::assertPushed(SendNotificationJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_queue_mode_enqueues_a_job_instead_of_sending(): void
    {
        Queue::fake();
        Http::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        Queue::assertPushed(SendNotificationJob::class, 1);
        Http::assertNothingSent();
        self::assertSame(1, DeliveryLog::where('status', 'queued')->count());
        self::assertSame(0, DeliveryLog::where('status', 'sent')->count());
    }

    public function test_a_queued_delivery_is_not_re_enqueued_while_in_flight(): void
    {
        Queue::fake();
        Http::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $this->incident($policy, $this->downPort($this->device()));

        // The faked queue never runs the job, so the in-flight "queued" marker persists.
        $this->artisan('iapm:process-actions');
        $this->artisan('iapm:process-actions');
        $this->artisan('iapm:process-actions');

        Queue::assertPushed(SendNotificationJob::class, 1);
        self::assertSame(1, DeliveryLog::where('status', 'queued')->count());
    }

    public function test_the_worker_job_delivers_and_clears_the_marker(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));

        // A marker as dispatch() would have created before enqueuing.
        $marker = DeliveryLog::create([
            'incident_id' => $incident->id,
            'destination_id' => $action->destination_id,
            'policy_action_id' => $action->id,
            'phase' => 'trigger',
            'attempt' => 1,
            'status' => 'queued',
        ]);

        (new SendNotificationJob($incident->id, $action->destination_id, $action->id, 'trigger', 'noc', 'Interface down', $marker->id))
            ->handle(app(NotificationDispatcher::class), app(SettingStore::class));

        Http::assertSentCount(1);
        self::assertSame(0, DeliveryLog::where('status', 'queued')->count(), 'the in-flight marker is cleared once the send is recorded');
        self::assertSame(1, DeliveryLog::where('status', 'sent')->count());
        self::assertSame(1, (int) $incident->fresh()->notification_count);
        self::assertNotNull($this->settings->get('last_queue_worker_at'));
    }

    public function test_a_broken_queue_backend_falls_back_to_synchronous_delivery(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        // Point at a queue connection that isn't configured, so enqueue throws.
        config(['iapm.queue.connection' => 'this-connection-does-not-exist']);
        $this->settings->put('dispatch_mode', 'queue');
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        // The alert is delivered synchronously rather than dropped, and no stray
        // in-flight marker is left behind.
        Http::assertSentCount(1);
        self::assertSame(1, DeliveryLog::where('status', 'sent')->count());
        self::assertSame(0, DeliveryLog::where('status', 'queued')->count());
    }

    public function test_a_deleted_incident_makes_the_job_a_safe_no_op(): void
    {
        Http::fake();
        $action = $this->triggerAction($this->policy(), $this->smsDestination());
        $marker = DeliveryLog::create(['destination_id' => $action->destination_id, 'policy_action_id' => $action->id, 'phase' => 'trigger', 'attempt' => 1, 'status' => 'queued']);

        // incidentId points at a row that no longer exists.
        (new SendNotificationJob(999999, $action->destination_id, $action->id, 'trigger', 'noc', 'msg', $marker->id))
            ->handle(app(NotificationDispatcher::class), app(SettingStore::class));

        Http::assertNothingSent();
        self::assertSame(0, DeliveryLog::where('status', 'queued')->count(), 'the marker is cleared even when the incident is gone');
    }
}
