<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\SendNotificationJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\NotificationDispatcher;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\QueueHeartbeat;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class QueueDispatchTest extends IntegrationTestCase
{
    public function test_queued_is_the_default_dispatch_mode(): void
    {
        Queue::fake();
        Http::fake();
        // Remove the test harness's sync override so the production default applies.
        DB::table('iapm_settings')->where('setting_key', 'dispatch_mode')->delete();
        $this->settings->forget('dispatch_mode');
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
        self::assertSame(1, NotificationOutbox::where('status', 'queued')->count());
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
        self::assertSame(1, NotificationOutbox::where('status', 'queued')->count());
    }

    public function test_the_worker_job_delivers_and_clears_the_marker(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));

        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'Interface down');
        $outbox = NotificationOutbox::firstOrFail();
        (new SendNotificationJob($outbox->id))->handle(app(NotificationDispatcher::class), app(SettingStore::class));

        Http::assertSentCount(1);
        self::assertSame('sent', $outbox->fresh()->status);
        self::assertSame(1, DeliveryLog::where('status', 'sent')->count());
        self::assertSame(1, (int) $incident->fresh()->notification_count);
        // Renamed from last_queue_worker_at: this records notification traffic,
        // not worker liveness. iapm:health proves liveness with QueueHeartbeat.
        self::assertNotNull($this->settings->get(QueueHeartbeat::DELIVERY_KEY));
    }

    public function test_a_broken_queue_backend_leaves_durable_work_pending_without_synchronous_fallback(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        // Point at a queue connection that isn't configured, so enqueue throws.
        config(['iapm.queue.connection' => 'this-connection-does-not-exist']);
        $this->settings->put('dispatch_mode', 'queue');
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        Http::assertNothingSent();
        self::assertSame(0, DeliveryLog::where('status', 'sent')->count());
        $outbox = NotificationOutbox::sole();
        self::assertSame('pending', $outbox->status);
        self::assertTrue($outbox->available_at->isFuture());
        self::assertSame('Queue dispatch unavailable; durable outbox remains pending.', $outbox->last_error_redacted);
    }

    public function test_a_deleted_incident_makes_the_job_a_safe_no_op(): void
    {
        Http::fake();
        (new SendNotificationJob(999999))->handle(app(NotificationDispatcher::class), app(SettingStore::class));

        Http::assertNothingSent();
        self::assertSame(0, NotificationOutbox::count());
    }
}
