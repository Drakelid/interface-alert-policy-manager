<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\QueueHeartbeat;

/**
 * Proves a queue worker is alive by being executed by one.
 *
 * Carries no payload and does no notification or gateway work: its only effect
 * is moving a timestamp, so it is safe to run at any time, in any order, and
 * costs a worker a few milliseconds.
 *
 * Routed onto the same connection and queue as real notifications on purpose —
 * a heartbeat that took a different path would prove the wrong thing. It is the
 * `iapm` queue on the configured connection that must have a worker.
 */
class QueueHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One attempt. A heartbeat is a point-in-time liveness sample; retrying a
     * stale one proves nothing, and the scheduler enqueues a fresh one anyway.
     */
    public int $tries = 1;

    public int $timeout = 30;

    /** Never keep a failed heartbeat in failed_jobs — it would be pure noise. */
    public bool $deleteWhenMissingModels = true;

    public function __construct()
    {
        if ($queue = config('iapm.queue.name')) {
            $this->onQueue($queue);
        }
        if ($connection = config('iapm.queue.connection')) {
            $this->onConnection($connection);
        }
    }

    public function handle(QueueHeartbeat $heartbeat): void
    {
        $heartbeat->recordConsumed();
    }
}
