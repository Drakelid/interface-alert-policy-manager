<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\SendNotificationJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\NotificationDispatcher;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class DurableDeliveryRecoveryTest extends IntegrationTestCase
{
    public function test_switching_to_sync_drains_existing_queue_publications_without_a_worker(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        [$incident, $action, $outbox] = $this->queuedNotification();
        $this->settings->put('dispatch_mode', 'sync');
        Queue::fake();

        $this->artisan('iapm:drain-outbox')->assertExitCode(0);
        $this->runJob($outbox);
        app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'down');

        Queue::assertNothingPushed();
        Http::assertSentCount(1);
        self::assertSame('sent', $outbox->fresh()->status);
        self::assertSame(1, NotificationOutbox::count());
    }

    public function test_sync_drainer_recovers_due_failed_rows_and_expired_publications(): void
    {
        [, , $outbox] = $this->queuedNotification();
        $outbox->update(['queued_at' => now()->subHour()]);
        $this->settings->put('dispatch_mode', 'sync');
        Queue::fake();
        Http::fakeSequence()->push('unavailable', 500)->push('ok', 200);

        $this->artisan('iapm:drain-outbox')->assertExitCode(0);
        self::assertSame('failed', $outbox->fresh()->status);
        $outbox->update(['available_at' => now()->subSecond()]);
        $this->artisan('iapm:drain-outbox')->assertExitCode(0);

        Queue::assertNothingPushed();
        Http::assertSentCount(2);
        self::assertSame('sent', $outbox->fresh()->status);
    }

    public function test_forced_sync_claims_queued_work_once_and_respects_another_live_owner(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        [$incident, $action, $outbox] = $this->queuedNotification();
        $outbox->update(['status' => 'processing', 'claimed_at' => now(), 'claim_token' => (string) Str::uuid()]);
        $dispatcher = app(NotificationDispatcher::class);
        $dispatcher->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'down', forceSync: true);
        Http::assertNothingSent();
        $outbox->update(['status' => 'queued', 'claimed_at' => null, 'claim_token' => null]);

        $dispatcher->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'down', forceSync: true);
        $this->runJob($outbox);

        Http::assertSentCount(1);
        self::assertSame('sent', $outbox->fresh()->status);
    }

    public function test_retry_after_exceeding_exponential_cap_is_preserved(): void
    {
        config(['iapm.queue.retry_max_seconds' => 3600]);
        Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '7200'])]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination(['retry_count' => 3, 'timeout' => 1]));
        $incident = $this->incident($policy, $this->downPort($this->device()));

        app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'down');

        Http::assertSentCount(1);
        self::assertTrue(NotificationOutbox::sole()->available_at->between(now()->addSeconds(7195), now()->addSeconds(7205)));
    }

    public function test_service_unavailable_retry_after_stops_immediate_retries(): void
    {
        Http::fake(['*' => Http::response('maintenance', 503, ['Retry-After' => '120'])]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination(['retry_count' => 3, 'timeout' => 1]));
        $incident = $this->incident($policy, $this->downPort($this->device()));

        app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'down');

        Http::assertSentCount(1);
        self::assertTrue(NotificationOutbox::sole()->available_at->between(now()->addSeconds(115), now()->addSeconds(125)));
        self::assertSame('Trigger notification failed after 1 attempt(s).', $incident->events()->where('event_type', 'notification_failed')->sole()->event_message);
    }

    public function test_a_three_day_recovery_delay_is_discovered_without_scanning_terminal_history(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination(), ['phase' => 'recovery', 'delay_seconds' => 3 * 86400]);
        $incident = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Recovered, 'recovered_at' => now()]);
        $terminal = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Recovered, 'first_seen_at' => now()->subYear(), 'recovered_at' => now()->subDays(20)]);
        Incident::whereKey($terminal->id)->update(['recovery_action_due_at' => null]);

        $this->artisan('iapm:process-actions')->assertExitCode(0);
        Http::assertNothingSent();
        self::assertTrue($incident->fresh()->recovery_action_due_at->equalTo($incident->recovered_at->copy()->addDays(3)));
        $this->travel(3 * 86400 + 1)->seconds();
        $this->artisan('iapm:process-actions')->assertExitCode(0);
        $this->artisan('iapm:process-actions')->assertExitCode(0);

        Http::assertSentCount(1);
        self::assertSame($incident->id, NotificationOutbox::sole()->incident_id);
        self::assertNull($incident->fresh()->recovery_action_due_at);
        self::assertNull($terminal->fresh()->recovery_action_due_at);
    }

    public function test_recovery_repeats_continue_beyond_two_days_and_stop_at_the_limit(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination(), ['phase' => 'recovery', 'repeat_seconds' => 3 * 86400, 'maximum_sends' => 2]);
        $incident = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Recovered, 'recovered_at' => now()]);

        $this->artisan('iapm:process-actions')->assertExitCode(0);
        self::assertNotNull($incident->fresh()->recovery_action_due_at);
        $this->travel(3 * 86400 + 1)->seconds();
        $this->artisan('iapm:process-actions')->assertExitCode(0);
        $this->travel(3 * 86400 + 1)->seconds();
        $this->artisan('iapm:process-actions')->assertExitCode(0);

        Http::assertSentCount(2);
        self::assertNull($incident->fresh()->recovery_action_due_at);
    }

    public function test_editing_a_recovery_action_rearms_its_persisted_schedule(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination(), ['phase' => 'recovery', 'delay_seconds' => 7 * 86400]);
        $incident = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Recovered, 'recovered_at' => now()]);
        $this->artisan('iapm:process-actions');
        $this->travel(3 * 86400)->seconds();
        $action->update(['delay_seconds' => 2 * 86400]);

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        Http::assertSentCount(1);
        self::assertNull($incident->fresh()->recovery_action_due_at);
    }

    public function test_more_than_one_thousand_pending_ports_are_aggregated_before_individual_delivery(): void
    {
        Queue::fake();
        Http::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $this->settings->put('aggregate_threshold', 20);
        $policy = $this->policy(['trigger_after_seconds' => 300]);
        $this->triggerAction($policy, $this->smsDestination());
        $prototype = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Pending, 'triggered_at' => null]);
        $attributes = $prototype->getAttributes();
        unset($attributes['id']);
        $rows = [];
        foreach (range(1, 1000) as $offset) {
            $rows[] = array_merge($attributes, ['port_id' => $prototype->port_id + 1000000 + $offset, 'incident_key' => 'pending-storm-'.$offset, 'episode_uuid' => (string) Str::uuid()]);
        }
        Incident::insert($rows);

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(1001, Incident::where('state', IncidentState::Active)->count());
        self::assertSame('digest', NotificationOutbox::sole()->phase);
        self::assertSame(1001, DB::table('iapm_notification_outbox_incidents')->count());
        Queue::assertPushed(SendNotificationJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_resumed_activation_cohort_does_not_age_out_before_digest_discovery(): void
    {
        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $this->settings->put('aggregate_threshold', 3);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        $first = $this->incident($policy, $this->downPort($device));
        $this->incident($policy, $this->downPort($device), ['state' => IncidentState::Pending, 'triggered_at' => null]);
        $this->incident($policy, $this->downPort($device), ['state' => IncidentState::Pending, 'triggered_at' => null]);
        $this->settings->put('pending_activation_started_at', now()->subMinutes(11)->toIso8601String());
        $this->settings->put('pending_activation_cursor_id', $first->id);

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame('digest', NotificationOutbox::sole()->phase);
        self::assertSame(3, DB::table('iapm_notification_outbox_incidents')->count());
        self::assertNull($this->settings->get('pending_activation_started_at'));
    }

    public function test_arrivals_outside_a_frozen_cohort_wait_without_extending_or_bypassing_it(): void
    {
        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $this->settings->put('aggregate_threshold', 2);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        $this->incident($policy, $this->downPort($device), ['state' => IncidentState::Pending, 'triggered_at' => null]);
        $last = $this->incident($policy, $this->downPort($device), ['state' => IncidentState::Pending, 'triggered_at' => null]);
        $this->settings->put('pending_activation_started_at', now()->toIso8601String());
        $this->settings->put('pending_activation_max_id', $last->id);
        $this->settings->put('digest_cohort_max_device_id', $device->device_id);
        $arrival = $this->incident($policy, $this->downPort($device), ['state' => IncidentState::Pending, 'triggered_at' => null]);
        $newDevice = $this->device();
        foreach (range(1, 2) as $ignored) {
            $this->incident($policy, $this->downPort($newDevice), ['triggered_at' => now()]);
        }

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame('digest', NotificationOutbox::sole()->phase);
        self::assertSame(2, DB::table('iapm_notification_outbox_incidents')->count());
        self::assertSame(IncidentState::Pending, $arrival->fresh()->state);
        self::assertNull($this->settings->get('pending_activation_max_id'));
        self::assertNull($this->settings->get('digest_cohort_max_device_id'));
        Queue::assertPushed(SendNotificationJob::class, 1);

        $this->artisan('iapm:process-actions')->assertExitCode(0);
        self::assertSame(IncidentState::Active, $arrival->fresh()->state);
        self::assertSame(2, NotificationOutbox::where('phase', 'digest')->count());
        self::assertSame(1, NotificationOutbox::where('phase', 'trigger')->count());
    }

    public function test_due_recovery_work_progresses_while_digest_discovery_spans_batches(): void
    {
        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $this->settings->put('aggregate_threshold', 2);
        $originalLimit = config('iapm.processing.digest_devices_per_run');
        config(['iapm.processing.digest_devices_per_run' => 1]);
        try {
            $policy = $this->policy();
            $destination = $this->smsDestination();
            $this->triggerAction($policy, $destination);
            $this->triggerAction($policy, $destination, ['phase' => 'recovery']);
            foreach (range(1, 2) as $ignored) {
                $device = $this->device();
                foreach (range(1, 2) as $portNumber) {
                    $this->incident($policy, $this->downPort($device), ['triggered_at' => now()]);
                }
            }
            $recovered = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Recovered,
                'first_seen_at' => now()->subDays(4), 'recovered_at' => now()->subDays(3)]);

            $this->artisan('iapm:process-actions')->assertExitCode(0);

            self::assertSame(1, NotificationOutbox::where('phase', 'digest')->count());
            self::assertSame(0, NotificationOutbox::where('phase', 'trigger')->count());
            self::assertTrue(NotificationOutbox::where('phase', 'recovery')->where('incident_id', $recovered->id)->exists());
            self::assertNotNull($this->settings->get('pending_activation_started_at'));
        } finally {
            config(['iapm.processing.digest_devices_per_run' => $originalLimit]);
        }
    }

    public function test_legacy_successes_preserve_send_once_and_recovery_budgets_after_upgrade(): void
    {
        Http::fake();
        $policy = $this->policy();
        $destination = $this->smsDestination();
        $trigger = $this->triggerAction($policy, $destination);
        $recovery = $this->triggerAction($policy, $destination, ['phase' => 'recovery']);
        $active = $this->incident($policy, $this->downPort($this->device()));
        $recovered = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Recovered, 'recovered_at' => now()]);
        $this->legacyLog($active, $trigger->id, $destination->id, 'trigger');
        $this->legacyLog($recovered, $recovery->id, $destination->id, 'recovery', ['created_at' => now(), 'sent_at' => now()]);
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_21_000001_adopt_legacy_delivery_history.php';
        $migration->up();
        $migration->up();

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        Http::assertNothingSent();
        self::assertSame(0, NotificationOutbox::count());
        self::assertSame(2, DeliveryLog::whereNotNull('episode_uuid')->count());
        self::assertNull($recovered->fresh()->recovery_action_due_at);
    }

    public function test_legacy_digest_members_keep_their_completed_trigger_budget_without_outbox_links(): void
    {
        Http::fake();
        $policy = $this->policy();
        $firstAction = $this->triggerAction($policy, $this->smsDestination());
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        $incidents = collect(range(1, 2))->map(fn () => $this->incident($policy, $this->downPort($device)));
        foreach ($incidents as $incident) {
            $context = $incident->context_json;
            $context['trigger_notified_via_digest'] = now()->subMinutes(5)->toIso8601String();
            $incident->update(['context_json' => $context]);
        }
        // The old digest logged only its leader; remaining members retained
        // their episode completion flag and no recoverable recipient identity.
        $this->legacyLog($incidents->first(), $firstAction->id, $firstAction->destination_id, 'digest');

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        Http::assertNothingSent();
        self::assertSame(0, NotificationOutbox::count());
    }

    public function test_a_digest_flag_older_than_the_current_episode_does_not_suppress_a_new_trigger(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));
        $context = $incident->context_json;
        $context['trigger_notified_via_digest'] = $incident->first_seen_at->copy()->subHour()->toIso8601String();
        $incident->update(['context_json' => $context]);

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        Http::assertSentCount(1);
        self::assertSame('trigger', NotificationOutbox::sole()->phase);
    }

    public function test_legacy_repeat_history_preserves_last_send_time_and_remaining_limit(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination(), ['repeat_seconds' => 3600, 'maximum_sends' => 2]);
        $incident = $this->incident($policy, $this->downPort($this->device()));
        $this->legacyLog($incident, $action->id, $action->destination_id, 'trigger');

        $this->artisan('iapm:process-actions');
        Http::assertNothingSent();
        $this->travel(3601)->seconds();
        $this->artisan('iapm:process-actions');
        $this->travel(3601)->seconds();
        $this->artisan('iapm:process-actions');

        Http::assertSentCount(1);
        self::assertSame(1, NotificationOutbox::count());
    }

    public function test_legacy_failures_and_previous_episode_successes_do_not_satisfy_the_current_trigger(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));
        $this->legacyLog($incident, $action->id, $action->destination_id, 'trigger', ['status' => 'failed']);
        $old = $this->legacyLog($incident, $action->id, $action->destination_id, 'trigger', ['created_at' => $incident->first_seen_at->copy()->subHour(), 'sent_at' => $incident->first_seen_at->copy()->subHour()]);
        (require dirname(__DIR__, 2).'/database/migrations/2026_10_21_000001_adopt_legacy_delivery_history.php')->up();

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        Http::assertSentCount(1);
        self::assertNull($old->fresh()->episode_uuid);
        self::assertSame(1, NotificationOutbox::count());
    }

    private function queuedNotification(): array
    {
        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));
        app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'down');

        return [$incident, $action, NotificationOutbox::sole()];
    }

    private function runJob(NotificationOutbox $outbox): void
    {
        (new SendNotificationJob($outbox->id))->handle(app(NotificationDispatcher::class), app(SettingStore::class));
    }

    private function legacyLog(Incident $incident, int $actionId, int $destinationId, string $phase, array $attributes = []): DeliveryLog
    {
        return DeliveryLog::create(array_merge(['incident_id' => $incident->id, 'destination_id' => $destinationId, 'policy_action_id' => $actionId,
            'phase' => $phase, 'status' => 'sent', 'request_payload_redacted' => '{"receiver":"[REDACTED]","message":"[REDACTED]"}',
            'created_at' => now()->subMinutes(5), 'sent_at' => now()->subMinutes(5)], $attributes));
    }
}
