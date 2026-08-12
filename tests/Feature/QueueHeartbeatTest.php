<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\QueueHeartbeatJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\SendNotificationJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\HealthService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\NotificationDispatcher;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\QueueHeartbeat;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * `iapm:health` inferred queue-worker liveness from `last_queue_worker_at`,
 * which only SendNotificationJob wrote. On a production install with six systemd
 * workers, an empty queue and no failed jobs, ten quiet minutes were enough for
 * the check to report that no worker was draining the queue.
 *
 * Liveness is now proven end-to-end by a heartbeat job a worker must execute.
 */
class QueueHeartbeatTest extends IntegrationTestCase
{
    private function heartbeat(): QueueHeartbeat
    {
        return app(QueueHeartbeat::class);
    }

    /** @return array{ok:bool,label:string,detail:string} */
    private function queueCheck(): array
    {
        foreach (app(HealthService::class)->checks() as $check) {
            if ($check['key'] === 'queue_worker') {
                return $check;
            }
        }

        self::fail('The queue worker check was not present.');
    }

    private function queueCheckExists(): bool
    {
        return collect(app(HealthService::class)->checks())->contains(fn ($c) => $c['key'] === 'queue_worker');
    }

    /** 1. Queued mode with a recently consumed heartbeat is healthy. */
    public function test_a_recently_consumed_heartbeat_is_healthy(): void
    {
        $this->settings->put('dispatch_mode', 'queue');
        $this->heartbeat()->recordConsumed();

        $check = $this->queueCheck();
        self::assertTrue($check['ok']);
        self::assertStringContainsString('Last worker heartbeat', $check['detail']);
    }

    /**
     * 2. The regression itself: hours of silence must stay green as long as
     * heartbeats keep being consumed.
     */
    public function test_a_quiet_network_stays_healthy_indefinitely(): void
    {
        $this->settings->put('dispatch_mode', 'queue');
        Queue::fake();

        // Six hours of scheduler ticks with a live worker and no notifications.
        for ($minute = 0; $minute < 360; $minute++) {
            $this->travel(1)->minutes();
            $this->artisan('iapm:queue-heartbeat')->assertExitCode(0);
            $this->heartbeat()->recordConsumed();
        }

        self::assertTrue($this->queueCheck()['ok'], 'A healthy worker went red purely because no notification was sent.');
        self::assertNull($this->settings->get(QueueHeartbeat::DELIVERY_KEY), 'No notification was sent, so no delivery should be recorded.');
    }

    /**
     * The exact production report, reproduced: six live workers, one notification
     * delivered at some point, then an hour of silence. The old check read the
     * delivery timestamp as proof of liveness, so it went red at ten minutes;
     * this must stay green for as long as heartbeats keep being consumed.
     */
    public function test_a_delivery_followed_by_an_hour_of_silence_stays_healthy(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));

        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');

        // A real notification goes out, exactly as it would in production.
        app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'Interface down');
        (new SendNotificationJob(NotificationOutbox::firstOrFail()->id))->handle(app(NotificationDispatcher::class), app(SettingStore::class));
        self::assertNotNull($this->settings->get(QueueHeartbeat::DELIVERY_KEY));

        // An hour passes. Nothing alerts; the workers stay up and keep eating
        // heartbeats, which is the only thing that should decide the verdict.
        for ($minute = 0; $minute < 60; $minute++) {
            $this->travel(1)->minutes();
            $this->artisan('iapm:queue-heartbeat');
            $this->heartbeat()->recordConsumed();
        }

        $check = $this->queueCheck();
        self::assertTrue($check['ok'], 'Six live workers were reported dead purely because the network was quiet: '.$check['detail']);
        self::assertStringContainsString('Last worker heartbeat', $check['detail']);
        // Delivery traffic is still visible, just no longer mistaken for liveness.
        self::assertStringContainsString('Last notification delivered', $check['detail']);
    }

    /** 3. A heartbeat older than the threshold fails. */
    public function test_a_stale_heartbeat_fails(): void
    {
        $this->settings->put('dispatch_mode', 'queue');
        Queue::fake();
        $this->artisan('iapm:queue-heartbeat');
        $this->heartbeat()->recordConsumed();

        $this->travel(config('iapm.queue.heartbeat_stale_seconds') + 60)->seconds();
        // The scheduler keeps running; the worker does not consume.
        $this->artisan('iapm:queue-heartbeat');

        $check = $this->queueCheck();
        self::assertFalse($check['ok']);
        self::assertStringContainsString('No IAPM queue heartbeat has been consumed', $check['detail']);
        // Names the queue and connection a worker must be listening on, because
        // the usual cause is a worker started against the wrong one.
        self::assertStringContainsString('queue "iapm"', $check['detail']);
        self::assertStringContainsString('external supervisor', $check['detail']);
        // Must not tell an operator to hand-start a worker when systemd owns them.
        self::assertStringNotContainsString('queue:work', $check['detail']);
        self::assertStringNotContainsString('php artisan', $check['detail']);
    }

    /** The named connection follows configuration, so the advice is actionable. */
    public function test_the_failure_names_the_configured_connection(): void
    {
        config(['iapm.queue.connection' => 'redis']);
        $this->settings->put('dispatch_mode', 'queue');
        Queue::fake();
        $this->artisan('iapm:queue-heartbeat');
        $this->travel(config('iapm.queue.heartbeat_stale_seconds') + 60)->seconds();

        self::assertStringContainsString('"redis" connection', $this->queueCheck()['detail']);
    }

    /** A deliberately disabled scheduler worker pool needs a specific remedy. */
    public function test_the_failure_explains_when_scheduler_managed_workers_are_disabled(): void
    {
        config(['iapm.queue.workers' => 0]);
        $this->settings->put('dispatch_mode', 'queue');
        Queue::fake();
        $this->artisan('iapm:queue-heartbeat');
        $this->travel(config('iapm.queue.heartbeat_stale_seconds') + 60)->seconds();

        $detail = $this->queueCheck()['detail'];
        self::assertStringContainsString('IAPM_QUEUE_WORKERS=0', $detail);
        self::assertStringContainsString('Scheduler-managed workers are disabled', $detail);
        self::assertStringContainsString('set IAPM_QUEUE_WORKERS to a positive count', $detail);
    }

    /** 4. An empty notification queue is not proof of a living worker. */
    public function test_an_empty_queue_with_a_stale_heartbeat_still_fails(): void
    {
        $this->settings->put('dispatch_mode', 'queue');
        Queue::fake();
        $this->artisan('iapm:queue-heartbeat');

        $this->travel(config('iapm.queue.heartbeat_stale_seconds') + 60)->seconds();

        self::assertSame(0, NotificationOutbox::count(), 'The fixture must have an empty queue.');
        self::assertFalse($this->queueCheck()['ok'], 'An empty queue was treated as proof a worker exists.');
    }

    /**
     * 5. Worker liveness and notification backlog are separate signals. A live
     * worker with a stuck outbox must report the backlog, not a dead worker.
     */
    public function test_a_live_worker_with_a_stuck_outbox_reports_the_backlog_not_the_worker(): void
    {
        $this->settings->put('dispatch_mode', 'queue');
        $this->heartbeat()->recordConsumed();

        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));
        NotificationOutbox::create([
            'idempotency_key' => hash('sha256', 'heartbeat-backlog-fixture'),
            'incident_id' => $incident->id, 'episode_uuid' => $incident->episode_uuid,
            'policy_action_id' => $action->id, 'destination_id' => $action->destination_id,
            'phase' => 'trigger', 'status' => 'pending', 'attempt_count' => 0,
            'receiver_hash' => hash('sha256', 'noc'),
            'receiver_encrypted' => 'noc', 'message_encrypted' => 'down', 'incident_ids_encrypted' => '[]',
            'available_at' => now()->subHour(), 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ]);

        $checks = collect(app(HealthService::class)->checks())->keyBy('key');
        self::assertTrue($checks['queue_worker']['ok'], 'A backlog must not be reported as a dead worker.');
        self::assertFalse($checks['action_backlog']['ok'], 'The backlog itself must still be reported.');
        self::assertFalse(app(HealthService::class)->healthy(), 'Overall health must still be unhealthy.');
    }

    /** 6. Synchronous delivery needs no worker, so the check does not apply. */
    public function test_synchronous_mode_omits_the_queue_worker_check(): void
    {
        $this->settings->put('dispatch_mode', 'sync');

        self::assertFalse($this->queueCheckExists());
    }

    public function test_synchronous_mode_enqueues_no_heartbeat_and_clears_any_wait(): void
    {
        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $this->artisan('iapm:queue-heartbeat');
        self::assertNotNull($this->heartbeat()->pendingSince());

        $this->settings->put('dispatch_mode', 'sync');
        $this->artisan('iapm:queue-heartbeat')->expectsOutputToContain('Synchronous delivery')->assertExitCode(0);

        Queue::assertPushed(QueueHeartbeatJob::class, 1);
        self::assertNull($this->heartbeat()->pendingSince(), 'Switching back to queued must not start red.');
    }

    /** 7. Externally supervised workers: IAPM_QUEUE_WORKERS=0 must still heartbeat. */
    public function test_the_heartbeat_is_enqueued_when_workers_are_externally_supervised(): void
    {
        Queue::fake();
        config(['iapm.queue.workers' => 0]);
        $this->settings->put('dispatch_mode', 'queue');

        $this->artisan('iapm:queue-heartbeat')->assertExitCode(0);

        Queue::assertPushed(QueueHeartbeatJob::class, 1);
    }

    /** 8. Scheduler-managed workers must behave identically. */
    public function test_the_heartbeat_is_enqueued_when_workers_are_scheduler_managed(): void
    {
        Queue::fake();
        config(['iapm.queue.workers' => 3]);
        $this->settings->put('dispatch_mode', 'queue');

        $this->artisan('iapm:queue-heartbeat')->assertExitCode(0);

        Queue::assertPushed(QueueHeartbeatJob::class, 1);
    }

    /** 9/10. The heartbeat follows the configured connection and queue. */
    public function test_the_heartbeat_uses_the_configured_queue_and_default_connection(): void
    {
        config(['iapm.queue.connection' => null, 'iapm.queue.name' => 'iapm']);

        $job = new QueueHeartbeatJob;

        self::assertSame('iapm', $job->queue);
        self::assertNull($job->connection, 'A null IAPM connection must inherit the Laravel default.');
    }

    public function test_the_heartbeat_honours_an_explicit_connection_such_as_redis(): void
    {
        config(['iapm.queue.connection' => 'redis', 'iapm.queue.name' => 'iapm-alt']);

        $job = new QueueHeartbeatJob;

        self::assertSame('redis', $job->connection);
        self::assertSame('iapm-alt', $job->queue);
    }

    /** The same routing real notifications use — a heartbeat on another path proves nothing. */
    public function test_the_heartbeat_is_routed_like_a_real_notification(): void
    {
        config(['iapm.queue.connection' => 'database', 'iapm.queue.name' => 'iapm']);

        $heartbeat = new QueueHeartbeatJob;
        $notification = new SendNotificationJob(1);

        self::assertSame($notification->queue, $heartbeat->queue);
        self::assertSame($notification->connection, $heartbeat->connection);
    }

    /**
     * 11. Repeated ticks without a worker must not accumulate jobs. Inside the
     * self-heal window exactly one heartbeat is outstanding, however many times
     * the scheduler ticks.
     */
    public function test_repeated_ticks_without_a_worker_do_not_accumulate_heartbeats(): void
    {
        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');

        for ($minute = 0; $minute < 9; $minute++) {
            $this->artisan('iapm:queue-heartbeat');
            $this->travel(1)->minutes();
        }

        Queue::assertPushed(QueueHeartbeatJob::class, 1);
    }

    /**
     * Over a long outage the self-heal adds a replacement occasionally, but the
     * count must stay proportional to that window rather than to scheduler ticks:
     * a day of downtime is ~144 heartbeats, not 1,440.
     */
    public function test_a_long_outage_enqueues_heartbeats_at_the_self_heal_rate_not_every_tick(): void
    {
        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');

        // Two hours of once-a-minute scheduler ticks, every worker stopped.
        for ($minute = 0; $minute < 120; $minute++) {
            $this->artisan('iapm:queue-heartbeat');
            $this->travel(1)->minutes();
        }

        // Self-heal window is 600s, so twelve at most across two hours.
        Queue::assertPushed(QueueHeartbeatJob::class, 12);
    }

    /**
     * ...but a heartbeat lost for good must not pin health red forever, so one
     * replacement is enqueued after the self-heal window.
     */
    public function test_a_lost_heartbeat_is_eventually_replaced(): void
    {
        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $this->artisan('iapm:queue-heartbeat');

        $this->travel(config('iapm.queue.heartbeat_stale_seconds') * 2 + 60)->seconds();
        $this->artisan('iapm:queue-heartbeat')->expectsOutputToContain('never consumed');

        Queue::assertPushed(QueueHeartbeatJob::class, 2);
        // The wait is still measured from the original dispatch, so health does
        // not reset to green just because a replacement was enqueued.
        self::assertFalse($this->queueCheck()['ok']);
    }

    /** 12. Configuration default and override. */
    public function test_the_stale_threshold_defaults_to_five_minutes_and_is_configurable(): void
    {
        config(['iapm.queue.heartbeat_stale_seconds' => null]);
        self::assertSame(300, $this->heartbeat()->staleAfterSeconds(), 'Unset configuration must fall back to the documented default.');

        config(['iapm.queue.heartbeat_stale_seconds' => 900]);
        self::assertSame(900, $this->heartbeat()->staleAfterSeconds());

        // A too-aggressive value would alarm on a single missed scheduler tick.
        config(['iapm.queue.heartbeat_stale_seconds' => 5]);
        self::assertSame(60, $this->heartbeat()->staleAfterSeconds());
    }

    /** 13. A malformed timestamp must not read as recent. */
    public function test_a_malformed_heartbeat_timestamp_is_treated_as_absent(): void
    {
        $this->settings->put('dispatch_mode', 'queue');
        $this->settings->put(QueueHeartbeat::CONSUMED_KEY, 'not-a-timestamp');
        $this->settings->put(QueueHeartbeat::PENDING_KEY, 'also-not-a-timestamp');

        self::assertNull($this->heartbeat()->consumedAt());
        self::assertNull($this->heartbeat()->pendingSince());
        // Nothing is known and nothing is queued: the scheduler will enqueue one
        // within a minute, so this is the first-install state, not a failure.
        self::assertTrue($this->queueCheck()['ok']);
    }

    /** 14. First install: green briefly, then red if no worker ever consumes. */
    public function test_a_fresh_install_is_green_before_the_first_heartbeat_is_enqueued(): void
    {
        $this->settings->put('dispatch_mode', 'queue');

        $check = $this->queueCheck();
        self::assertTrue($check['ok']);
        self::assertStringContainsString('first heartbeat has not been enqueued', $check['detail']);
    }

    public function test_a_fresh_install_goes_red_if_no_worker_ever_consumes_a_heartbeat(): void
    {
        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $this->artisan('iapm:queue-heartbeat');

        // Inside the grace window a queued-but-unconsumed heartbeat is fine.
        self::assertTrue($this->queueCheck()['ok']);

        $this->travel(config('iapm.queue.heartbeat_stale_seconds') + 60)->seconds();

        self::assertFalse($this->queueCheck()['ok'], 'Health must not stay green without ever proving a worker consumed a heartbeat.');
    }

    /** A consumed heartbeat with nothing queued since means the scheduler stopped. */
    public function test_a_stopped_scheduler_is_reported_distinctly(): void
    {
        $this->settings->put('dispatch_mode', 'queue');
        $this->heartbeat()->recordConsumed();
        $this->travel(config('iapm.queue.heartbeat_stale_seconds') + 60)->seconds();

        $check = $this->queueCheck();
        self::assertFalse($check['ok']);
        self::assertStringContainsString('confirm the LibreNMS scheduler is running', $check['detail']);
    }

    /** The job itself is what moves the timestamp — running it must be enough. */
    public function test_executing_the_job_records_the_heartbeat_and_clears_the_wait(): void
    {
        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        $this->artisan('iapm:queue-heartbeat');
        self::assertNotNull($this->heartbeat()->pendingSince());

        (new QueueHeartbeatJob)->handle($this->heartbeat());

        self::assertNotNull($this->heartbeat()->consumedAt());
        self::assertNull($this->heartbeat()->pendingSince());
        self::assertTrue($this->queueCheck()['ok']);
    }

    /** End to end on the real queue backend, with no faking. */
    public function test_the_heartbeat_survives_a_real_round_trip_through_the_queue(): void
    {
        $this->settings->put('dispatch_mode', 'queue');

        // QUEUE_CONNECTION is sync in tests, so dispatch runs the job inline —
        // which still exercises dispatch -> routing -> handle -> persisted setting.
        $this->artisan('iapm:queue-heartbeat')->assertExitCode(0);

        self::assertNotNull($this->heartbeat()->consumedAt());
        self::assertNull($this->heartbeat()->pendingSince());
        self::assertTrue($this->queueCheck()['ok']);
    }

    /** The heartbeat must never touch a gateway. */
    public function test_the_heartbeat_performs_no_delivery_work(): void
    {
        Http::preventStrayRequests();
        $this->settings->put('dispatch_mode', 'queue');

        $this->artisan('iapm:queue-heartbeat')->assertExitCode(0);

        self::assertSame(0, NotificationOutbox::count());
        self::assertNull($this->settings->get(QueueHeartbeat::DELIVERY_KEY));
    }

    /** Delivery traffic is recorded separately from liveness. */
    public function test_a_real_notification_records_delivery_not_liveness(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));

        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'Interface down');
        (new SendNotificationJob(NotificationOutbox::firstOrFail()->id))->handle(app(NotificationDispatcher::class), app(SettingStore::class));

        self::assertNotNull($this->settings->get(QueueHeartbeat::DELIVERY_KEY), 'Delivery traffic should be recorded.');
        self::assertNull($this->heartbeat()->consumedAt(), 'A notification must not be mistaken for a liveness heartbeat.');
    }

    /** The old ambiguous key is gone, not merely shadowed. */
    public function test_the_ambiguous_legacy_timestamp_is_no_longer_written(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));

        Queue::fake();
        $this->settings->put('dispatch_mode', 'queue');
        app(NotificationDispatcher::class)->dispatch($incident, $action->destination, $action, 'trigger', 'noc', 'Interface down');
        (new SendNotificationJob(NotificationOutbox::firstOrFail()->id))->handle(app(NotificationDispatcher::class), app(SettingStore::class));

        self::assertNull($this->settings->get('last_queue_worker_at'));
    }

    /** A queue backend that cannot accept the job must not fake a healthy wait. */
    public function test_a_broken_queue_backend_is_reported_rather_than_silently_swallowed(): void
    {
        config(['iapm.queue.connection' => 'this-connection-does-not-exist']);
        $this->settings->put('dispatch_mode', 'queue');

        $this->artisan('iapm:queue-heartbeat')->expectsOutputToContain('could not be enqueued')->assertExitCode(0);

        self::assertNull($this->heartbeat()->pendingSince(), 'A failed dispatch must not leave a phantom wait.');
    }

    /** The scheduler must actually register the heartbeat. */
    public function test_the_heartbeat_is_registered_with_the_scheduler(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => (string) ($event->command ?? ''));

        self::assertTrue($commands->contains(fn (string $c) => str_contains($c, 'iapm:queue-heartbeat')));
    }
}
