<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Command;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\SendNotificationJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\NotificationDispatcher;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * Storm control: when many interfaces on the same device go down together, a
 * single "device down" digest is sent instead of one SMS per interface.
 */
class DeviceDigestTest extends IntegrationTestCase
{
    public function test_a_hundred_interface_device_storm_queues_one_durable_digest(): void
    {
        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $this->settings->put('aggregate_threshold', 20);
        $this->settings->put('aggregate_window_seconds', 3600);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        collect(range(1, 100))->each(fn () => $this->incident($policy, $this->downPort($device)));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        $outbox = NotificationOutbox::sole();
        self::assertSame('digest', $outbox->phase);
        self::assertSame('queued', $outbox->status);
        self::assertCount(100, $outbox->incident_ids_encrypted);
        self::assertSame(100, DB::table('iapm_notification_outbox_incidents')->where('notification_outbox_id', $outbox->id)->count());
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_many_interfaces_on_one_device_send_a_single_digest(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('aggregate_threshold', 3);
        $this->settings->put('aggregate_window_seconds', 3600);

        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        $incidents = collect(range(1, 5))->map(fn () => $this->incident($policy, $this->downPort($device)));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(1, DeliveryLog::where('phase', 'digest')->count());
        self::assertSame(0, DeliveryLog::where('phase', 'trigger')->count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['message'], '5 interfaces down'));

        foreach ($incidents as $incident) {
            $fresh = $incident->fresh();
            self::assertNotEmpty($fresh->context_json['trigger_notified_via_digest'] ?? null);
            self::assertTrue($fresh->events()->where('event_type', 'digested')->exists());
        }
    }

    public function test_a_device_below_the_threshold_notifies_per_interface(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('aggregate_threshold', 3);
        $this->settings->put('aggregate_window_seconds', 3600);

        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        $this->incident($policy, $this->downPort($device));
        $this->incident($policy, $this->downPort($device));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(0, DeliveryLog::where('phase', 'digest')->count());
        self::assertSame(2, DeliveryLog::where('phase', 'trigger')->count());
    }

    public function test_aggregation_disabled_notifies_per_interface(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        // aggregate_threshold defaults to 0 (disabled) — do not set it.

        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        collect(range(1, 4))->each(fn () => $this->incident($policy, $this->downPort($device)));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(0, DeliveryLog::where('phase', 'digest')->count());
        self::assertSame(4, DeliveryLog::where('phase', 'trigger')->count());
    }

    public function test_only_devices_over_the_threshold_are_grouped(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('aggregate_threshold', 3);
        $this->settings->put('aggregate_window_seconds', 3600);

        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());

        // Device A trips four interfaces → grouped into one digest.
        $deviceA = $this->device();
        collect(range(1, 4))->each(fn () => $this->incident($policy, $this->downPort($deviceA)));

        // Device B trips a single interface → still notified individually.
        $deviceB = $this->device();
        $this->incident($policy, $this->downPort($deviceB));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(1, DeliveryLog::where('phase', 'digest')->count());
        self::assertSame(1, DeliveryLog::where('phase', 'trigger')->count());
        Http::assertSentCount(2);
    }

    public function test_a_prior_episodes_trigger_does_not_block_regrouping(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('aggregate_threshold', 3);
        $this->settings->put('aggregate_window_seconds', 3600);

        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();

        collect(range(1, 3))->each(function () use ($policy, $device, $action): void {
            // Reopened this episode (triggered_at = now), but with a trigger delivery
            // from a *previous* outage. The digest must still group it.
            $incident = $this->incident($policy, $this->downPort($device), ['triggered_at' => now()]);
            $incident->deliveries()->create([
                'destination_id' => $action->destination_id,
                'policy_action_id' => $action->id,
                'phase' => 'trigger',
                'status' => 'sent',
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ]);
        });

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(1, DeliveryLog::where('phase', 'digest')->count());
    }

    public function test_a_pending_device_outage_is_activated_then_grouped(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('aggregate_threshold', 3);
        $this->settings->put('aggregate_window_seconds', 3600);

        // Trigger delay already elapsed, so the pre-pass activates them this run.
        $policy = $this->policy(['trigger_after_seconds' => 300]);
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        collect(range(1, 4))->each(fn () => $this->incident($policy, $this->downPort($device), [
            'state' => IncidentState::Pending,
            'triggered_at' => null,
            'first_seen_at' => now()->subMinutes(10),
        ]));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(1, DeliveryLog::where('phase', 'digest')->count());
        self::assertSame(0, DeliveryLog::where('phase', 'trigger')->count());
    }

    public function test_failed_digest_never_sets_a_permanent_flag_or_last_notification_time(): void
    {
        Http::fake(['*' => Http::response('failed', 500)]);
        $this->settings->put('aggregate_threshold', 2);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination(['retry_count' => 0]));
        $device = $this->device();
        $incidents = collect(range(1, 2))->map(fn () => $this->incident($policy, $this->downPort($device)));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        foreach ($incidents as $incident) {
            self::assertArrayNotHasKey('trigger_notified_via_digest', $incident->fresh()->context_json);
            self::assertNull($incident->fresh()->last_notification_at);
        }
    }

    public function test_disabled_destination_leaves_incidents_eligible(): void
    {
        $this->settings->put('aggregate_threshold', 2);
        $policy = $this->policy(['default_receiver' => null]);
        $destination = $this->smsDestination(['default_receiver' => null, 'receivers' => []]);
        $destination->update(['enabled' => false]);
        $this->triggerAction($policy, $destination, ['receivers_json' => []]);
        $device = $this->device();
        $incidents = collect(range(1, 2))->map(fn () => $this->incident($policy, $this->downPort($device)));

        $this->artisan('iapm:process-actions')->assertExitCode(0);
        foreach ($incidents as $incident) {
            self::assertArrayNotHasKey('trigger_notified_via_digest', $incident->fresh()->context_json);
        }
    }

    public function test_no_receiver_leaves_incidents_eligible_for_individual_retry(): void
    {
        $this->settings->put('aggregate_threshold', 2);
        $this->settings->put('sms_default_receiver', null);
        $policy = $this->policy(['default_receiver' => null]);
        $destination = $this->smsDestination(['default_receiver' => null, 'receivers' => []]);
        $this->triggerAction($policy, $destination, ['receivers_json' => []]);
        $device = $this->device();
        $incidents = collect(range(1, 2))->map(fn () => $this->incident($policy, $this->downPort($device)));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(0, NotificationOutbox::count());
        foreach ($incidents as $incident) {
            self::assertArrayNotHasKey('trigger_notified_via_digest', $incident->fresh()->context_json);
            self::assertNull($incident->fresh()->last_notification_at);
        }
    }

    public function test_failed_digest_retries_same_logical_outbox_and_then_finalizes(): void
    {
        // First run: digest plus both eligible individual fallbacks fail. The
        // next scheduler run retries the same digest outbox and succeeds.
        Http::fakeSequence()->push('failed', 500)->push('failed', 500)->push('failed', 500)->push('ok', 200);
        $this->settings->put('aggregate_threshold', 2);
        $this->settings->put('aggregate_window_seconds', 3600);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination(['retry_count' => 0]));
        $device = $this->device();
        $incidents = collect(range(1, 2))->map(fn () => $this->incident($policy, $this->downPort($device)));

        $this->artisan('iapm:process-actions');
        $this->artisan('iapm:process-actions');

        self::assertSame(1, NotificationOutbox::where('phase', 'digest')->count());
        self::assertSame('sent', NotificationOutbox::where('phase', 'digest')->sole()->status);
        foreach ($incidents as $incident) {
            self::assertNotEmpty($incident->fresh()->context_json['trigger_notified_via_digest'] ?? null);
        }
    }

    public function test_dry_run_digest_is_intentionally_finalized_without_live_count(): void
    {
        Http::fake();
        $this->settings->put('dry_run', true);
        $this->settings->put('aggregate_threshold', 2);
        $this->settings->put('aggregate_window_seconds', 3600);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        $incidents = collect(range(1, 2))->map(fn () => $this->incident($policy, $this->downPort($device)));

        $this->artisan('iapm:process-actions');

        self::assertSame('dry_run', NotificationOutbox::where('phase', 'digest')->sole()->status);
        Http::assertNothingSent();
        foreach ($incidents as $incident) {
            self::assertNotEmpty($incident->fresh()->context_json['trigger_notified_via_digest'] ?? null);
            self::assertNotNull($incident->fresh()->last_notification_at);
            self::assertSame(0, $incident->fresh()->notification_count);
        }
    }

    public function test_queued_digest_is_only_confirmed_by_the_worker(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('dispatch_mode', 'queue');
        $this->settings->put('aggregate_threshold', 2);
        $this->settings->put('aggregate_window_seconds', 3600);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        $incidents = collect(range(1, 2))->map(fn () => $this->incident($policy, $this->downPort($device)));

        $this->artisan('iapm:process-actions');
        foreach ($incidents as $incident) {
            self::assertArrayNotHasKey('trigger_notified_via_digest', $incident->fresh()->context_json);
        }
        $outbox = NotificationOutbox::where('phase', 'digest')->firstOrFail();
        (new SendNotificationJob($outbox->id))->handle(app(NotificationDispatcher::class), app(SettingStore::class));
        foreach ($incidents as $incident) {
            self::assertNotEmpty($incident->fresh()->context_json['trigger_notified_via_digest'] ?? null);
        }
    }

    public function test_invalid_digest_template_falls_back_without_marking(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('aggregate_threshold', 2);
        $this->settings->put('aggregate_window_seconds', 3600);
        $this->settings->put('template_digest', '{{ unknown_digest_value }}');
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        $incidents = collect(range(1, 2))->map(fn () => $this->incident($policy, $this->downPort($device)));
        $this->artisan('iapm:process-actions');
        foreach ($incidents as $incident) {
            self::assertArrayNotHasKey('trigger_notified_via_digest', $incident->fresh()->context_json);
        }
        self::assertSame(2, DeliveryLog::where('phase', 'trigger')->where('status', 'sent')->count());
    }

    public function test_shared_destination_keeps_receiver_overrides_from_all_policies(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('aggregate_threshold', 2);
        $this->settings->put('aggregate_window_seconds', 3600);
        $destination = $this->smsDestination();
        $firstPolicy = $this->policy();
        $secondPolicy = $this->policy();
        $this->triggerAction($firstPolicy, $destination, ['receivers_json' => ['receiver-a']]);
        $this->triggerAction($secondPolicy, $destination, ['receivers_json' => ['receiver-b']]);
        $device = $this->device();
        $this->incident($firstPolicy, $this->downPort($device));
        $this->incident($secondPolicy, $this->downPort($device));

        $this->artisan('iapm:process-actions');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => in_array($request['receiver'], ['receiver-a', 'receiver-b'], true));
        self::assertSame(2, NotificationOutbox::where('phase', 'digest')->where('status', 'sent')->count());
    }
}
