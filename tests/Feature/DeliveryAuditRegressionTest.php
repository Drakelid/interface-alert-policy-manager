<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\Plugin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\IapmServiceProvider;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\SendNotificationJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\NotificationDispatcher;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class DeliveryAuditRegressionTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->put('aggregate_window_seconds', 3600);
    }

    public function test_queued_work_pauses_when_dry_run_is_enabled_and_resumes_afterwards(): void
    {
        [$incident, $action, $outbox] = $this->queuedNotification();
        $this->settings->put('dry_run', true);

        $this->runJob($outbox);

        Http::assertNothingSent();
        self::assertSame('pending', $outbox->fresh()->status);
        $this->artisan('iapm:process-actions')->assertExitCode(0);
        self::assertSame('pending', $outbox->fresh()->status);
        $this->settings->put('dry_run', false);
        $this->travel(61)->seconds();
        $this->runJob($outbox);
        self::assertSame('sent', $outbox->fresh()->status, json_encode($outbox->fresh()->only(['status', 'last_error_redacted', 'available_at', 'claimed_at'])));
        Http::assertSentCount(1);
        self::assertSame('sent', $outbox->fresh()->status);
    }

    public function test_disabling_the_plugin_also_pauses_existing_queue_jobs(): void
    {
        [, , $outbox] = $this->queuedNotification();
        self::assertTrue(app(PluginManagerInterface::class)->pluginEnabled(IapmServiceProvider::PLUGIN_NAME));
        Plugin::versionTwo()->where('plugin_name', IapmServiceProvider::PLUGIN_NAME)->update(['plugin_active' => false]);

        $this->runJob($outbox);

        Http::assertNothingSent();
        self::assertSame('pending', $outbox->fresh()->status);
    }

    public function test_acknowledgement_cancels_an_already_queued_escalation(): void
    {
        [$incident, , $outbox] = $this->queuedNotification(['phase' => 'escalation']);
        $incident->update(['state' => IncidentState::Acknowledged, 'acknowledged_at' => now()]);

        $this->runJob($outbox);

        Http::assertNothingSent();
        self::assertSame('cancelled', $outbox->fresh()->status);
    }

    public function test_mute_pauses_an_already_queued_notification(): void
    {
        [$incident, , $outbox] = $this->queuedNotification();
        $incident->update(['muted_until' => now()->addHour()]);

        $this->runJob($outbox);

        Http::assertNothingSent();
        self::assertSame('pending', $outbox->fresh()->status);
    }

    public function test_a_deleted_action_cannot_send_its_queued_notification(): void
    {
        [, $action, $outbox] = $this->queuedNotification();
        $action->delete();

        $this->runJob($outbox);

        Http::assertNothingSent();
        self::assertSame('cancelled', $outbox->fresh()->status);
    }

    public function test_queued_work_from_an_older_episode_is_cancelled_without_http(): void
    {
        [$incident, , $outbox] = $this->queuedNotification();
        $incident->update(['episode_uuid' => (string) Str::uuid()]);

        $this->runJob($outbox);

        Http::assertNothingSent();
        self::assertSame('cancelled', $outbox->fresh()->status);
        self::assertSame(0, (int) $incident->fresh()->notification_count);
    }

    public function test_the_drainer_recovers_an_abandoned_publication(): void
    {
        [, , $outbox] = $this->queuedNotification();
        $outbox->update(['queued_at' => now()->subHour(), 'updated_at' => now()->subHour()]);
        Queue::fake();

        $this->artisan('iapm:drain-outbox')->assertExitCode(0);

        Queue::assertPushed(SendNotificationJob::class, 1);
        $this->runJob($outbox);
        Http::assertSentCount(1);
    }

    public function test_an_early_no_op_retry_does_not_strand_a_processing_claim(): void
    {
        [, , $outbox] = $this->queuedNotification();
        $outbox->update(['status' => 'processing', 'claimed_at' => now(), 'claim_token' => (string) Str::uuid()]);
        $this->runJob($outbox);
        Http::assertNothingSent();
        $this->travel(91)->seconds();
        Queue::fake();

        $this->artisan('iapm:drain-outbox')->assertExitCode(0);

        Queue::assertPushed(SendNotificationJob::class, 1);
        $this->runJob($outbox);
        Http::assertSentCount(1);
        self::assertSame('sent', $outbox->fresh()->status);
    }

    public function test_a_dispatch_after_worker_completion_cannot_allocate_a_second_send_once_occurrence(): void
    {
        [$incident, $action, $outbox] = $this->queuedNotification();
        $this->runJob($outbox);

        app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'down');

        self::assertSame(1, NotificationOutbox::count());
        Http::assertSentCount(1);
    }

    public function test_every_receiver_gets_the_configured_number_of_repeats(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination(), ['phase' => 'reminder', 'repeat_seconds' => 60, 'maximum_sends' => 2, 'receivers_json' => ['first', 'second']]);
        $this->incident($policy, $this->downPort($this->device()));
        $this->artisan('iapm:process-actions')->assertExitCode(0);
        $this->travel(61)->seconds();
        $this->artisan('iapm:process-actions')->assertExitCode(0);
        $this->travel(61)->seconds();
        $this->artisan('iapm:process-actions')->assertExitCode(0);

        Http::assertSentCount(4);
        foreach (['first', 'second'] as $receiver) {
            self::assertSame(2, NotificationOutbox::where('receiver_hash', hash('sha256', $receiver))->where('status', 'sent')->count());
        }
    }

    public function test_a_device_digest_obeys_the_trigger_action_delay(): void
    {
        Http::fake();
        $this->settings->put('aggregate_threshold', 2);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination(), ['delay_seconds' => 3600]);
        $device = $this->device();
        foreach (range(1, 2) as $_) {
            $this->incident($policy, $this->downPort($device));
        }

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        Http::assertNothingSent();
        self::assertSame(0, NotificationOutbox::count());
    }

    public function test_a_new_incident_does_not_expand_an_already_queued_digest(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('dispatch_mode', 'queue');
        $this->settings->put('aggregate_threshold', 2);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        foreach (range(1, 2) as $_) {
            $this->incident($policy, $this->downPort($device));
        }
        $this->artisan('iapm:process-actions')->assertExitCode(0);
        $this->incident($policy, $this->downPort($device));
        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(1, NotificationOutbox::where('phase', 'digest')->count());
        self::assertSame(1, NotificationOutbox::where('phase', 'trigger')->count());
        foreach (NotificationOutbox::all() as $outbox) {
            $this->runJob($outbox);
        }
        Http::assertSentCount(2);
    }

    public function test_a_failed_digest_is_cancelled_before_successful_individual_fallbacks(): void
    {
        Http::fakeSequence()->push('failed', 500)->push('ok', 200)->push('ok', 200);
        $this->settings->put('aggregate_threshold', 2);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination(['retry_count' => 0]));
        $device = $this->device();
        foreach (range(1, 2) as $_) {
            $this->incident($policy, $this->downPort($device));
        }
        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame('cancelled', NotificationOutbox::where('phase', 'digest')->sole()->status);
        self::assertSame(2, NotificationOutbox::where('phase', 'trigger')->where('status', 'sent')->count());
        $this->travel(120)->seconds();
        Queue::fake();
        $this->artisan('iapm:drain-outbox')->assertExitCode(0);
        Queue::assertNothingPushed();
        Http::assertSentCount(3);
    }

    public function test_disabling_a_non_leader_action_cancels_the_queued_digest(): void
    {
        Queue::fake();
        Http::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $this->settings->put('aggregate_threshold', 2);
        $destination = $this->smsDestination();
        $device = $this->device();
        $firstPolicy = $this->policy();
        $this->triggerAction($firstPolicy, $destination);
        $this->incident($firstPolicy, $this->downPort($device));
        $secondPolicy = $this->policy();
        $secondAction = $this->triggerAction($secondPolicy, $destination);
        $this->incident($secondPolicy, $this->downPort($device));
        $this->artisan('iapm:process-actions')->assertExitCode(0);
        $outbox = NotificationOutbox::where('phase', 'digest')->sole();
        $secondAction->update(['enabled' => false]);

        $this->runJob($outbox);

        Http::assertNothingSent();
        self::assertSame('cancelled', $outbox->fresh()->status);
    }

    private function queuedNotification(array $actionAttributes = []): array
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('dispatch_mode', 'queue');
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination(), $actionAttributes);
        $incident = $this->incident($policy, $this->downPort($this->device()));
        app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, $action->phase->value, 'noc', 'down');

        return [$incident, $action, NotificationOutbox::sole()];
    }

    private function runJob(NotificationOutbox $outbox): void
    {
        (new SendNotificationJob($outbox->id))->handle(app(NotificationDispatcher::class), app(SettingStore::class));
    }
}
