<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Illuminate\Console\Command;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\Concerns\SkipsWhenPluginDisabled;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\QueueHeartbeat;

/**
 * Enqueues the worker-liveness heartbeat, once a minute from the scheduler.
 *
 * Registered unconditionally and gated at run time rather than at boot, so it
 * follows a change of Delivery dispatch without waiting for a restart, and works
 * identically whether workers are scheduler-managed (IAPM_QUEUE_WORKERS>0) or
 * externally supervised (IAPM_QUEUE_WORKERS=0 with systemd/Supervisor). The
 * heartbeat is about the queue having *a* worker, not about who started it.
 */
class QueueHeartbeatCommand extends Command
{
    use SkipsWhenPluginDisabled;

    protected $signature = 'iapm:queue-heartbeat';

    protected $description = 'Enqueue the IAPM queue-worker liveness heartbeat';

    public function handle(QueueHeartbeat $heartbeat): int
    {
        if ($this->pluginDisabled()) {
            return self::SUCCESS;
        }

        $result = $heartbeat->enqueueIfDue();
        $this->line(match ($result) {
            'not-queued-mode' => 'Synchronous delivery; no queue heartbeat needed.',
            'dispatched' => 'Heartbeat enqueued.',
            'redispatched' => 'Previous heartbeat was never consumed; enqueued a replacement.',
            'outstanding' => 'A heartbeat is already waiting to be consumed.',
            default => 'Heartbeat could not be enqueued; see storage/logs/iapm.log.',
        });

        // A failure to enqueue is a real fault, but iapm:health is the dead-man's
        // switch an external monitor watches — this command must not turn a
        // scheduler minute red on its own.
        return self::SUCCESS;
    }
}
