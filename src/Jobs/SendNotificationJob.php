<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\NotificationDispatcher;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;

/**
 * Optional queued delivery of a single notification. Enabled by setting the
 * `dispatch_mode` setting to "queue"; workers (`php artisan queue:work`) then
 * absorb a storm's backlog with real concurrency instead of the scheduled
 * command sending each SMS synchronously.
 *
 * Scalars (not models) are carried so a job serialised during a storm still
 * reflects current row state when a worker picks it up. A "queued" DeliveryLog
 * marker (created at enqueue) dedupes re-enqueues; this job clears it once the
 * real send has been recorded.
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;
    public int $timeout;

    public function __construct(
        public ?int $incidentId,
        public int $destinationId,
        public ?int $actionId,
        public string $phase,
        public string $receiver,
        public string $message,
        public ?int $markerId = null,
    ) {
        $this->tries = max(1, (int) config('iapm.queue.tries', 3));
        $this->timeout = max(15, (int) config('iapm.queue.timeout', 60));
        if ($queue = config('iapm.queue.name')) {
            $this->onQueue($queue);
        }
        if ($connection = config('iapm.queue.connection')) {
            $this->onConnection($connection);
        }
    }

    public function handle(NotificationDispatcher $dispatcher, SettingStore $settings): void
    {
        $settings->put('last_queue_worker_at', now()->toIso8601String());

        $destination = Destination::find($this->destinationId);
        $incident = $this->incidentId ? Incident::find($this->incidentId) : null;

        // The incident or destination may have been deleted between enqueue and now.
        if (! $destination || ! $incident) {
            $this->clearMarker();

            return;
        }

        $action = $this->actionId ? PolicyAction::find($this->actionId) : null;

        try {
            $dispatcher->performSync($incident, $destination, $action, $this->phase, $this->receiver, $this->message);
        } finally {
            // Clear the in-flight marker only after the real attempt has been recorded,
            // so the dedup guard never sees a gap (no marker and no sent/failed row).
            $this->clearMarker();
        }
    }

    private function clearMarker(): void
    {
        if ($this->markerId) {
            DeliveryLog::where('id', $this->markerId)->delete();
        }
    }
}
