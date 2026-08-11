<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\QueueHeartbeat;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * What the operator actually reads, and what an external monitor actually acts
 * on: `iapm:health`'s printed lines and its exit code. The reported production
 * fault was a wrong line and a wrong exit code from an otherwise healthy install.
 */
class HealthCommandOutputTest extends IntegrationTestCase
{
    /** Simulates a healthy host: scheduler ticking, workers consuming heartbeats. */
    private function healthyHost(): void
    {
        $this->settings->put('dispatch_mode', 'queue');
        foreach (['last_reconcile_at', 'last_process_actions_at', 'last_ingestion_drain_at'] as $key) {
            $this->settings->put($key, now()->toIso8601String());
        }
        app(QueueHeartbeat::class)->recordConsumed();
    }

    /**
     * The end state the report asked for: six systemd workers, an empty queue,
     * no notifications for an hour, and a clean exit.
     */
    public function test_a_quiet_healthy_host_exits_zero_and_reports_the_heartbeat(): void
    {
        $this->healthyHost();

        $this->artisan('iapm:health')
            ->expectsOutputToContain('[OK] Queue worker delivering — Last worker heartbeat')
            ->assertExitCode(0);
    }

    /** Stopping every worker must turn it red within the stale threshold. */
    public function test_stopping_every_worker_turns_the_check_red(): void
    {
        Queue::fake();
        $this->healthyHost();
        $this->artisan('iapm:queue-heartbeat');

        // Workers are gone; the scheduler keeps ticking.
        $this->travel(config('iapm.queue.heartbeat_stale_seconds') + 60)->seconds();
        foreach (['last_reconcile_at', 'last_process_actions_at'] as $key) {
            $this->settings->put($key, now()->toIso8601String());
        }

        // Only the prefix and the exit code are asserted here: Symfony wraps long
        // console lines, so a longer substring can straddle a newline. The wording
        // itself is pinned in QueueHeartbeatTest against the unwrapped detail.
        $this->artisan('iapm:health')
            ->expectsOutputToContain('[FAIL] Queue worker delivering')
            ->assertExitCode(1);
    }

    public function test_synchronous_delivery_reports_no_queue_worker_line_at_all(): void
    {
        $this->healthyHost();
        $this->settings->put('dispatch_mode', 'sync');

        $this->artisan('iapm:health')
            ->doesntExpectOutputToContain('Queue worker delivering')
            ->assertExitCode(0);
    }
}
