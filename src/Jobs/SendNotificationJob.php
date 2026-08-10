<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\NotificationDispatcher;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;

/** The serialized job contains only this non-sensitive database identifier. */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(public int $outboxId)
    {
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
        $settings->putThrottled('last_queue_worker_at', now()->toIso8601String(), 30);
        $dispatcher->deliverOutbox($this->outboxId);
    }

    public function failed(?\Throwable $exception): void
    {
        NotificationOutbox::whereKey($this->outboxId)->whereIn('status', ['pending', 'queued', 'processing'])->update(['status' => 'failed', 'last_error_redacted' => 'Queue worker failed before finalization.']);
    }
}
