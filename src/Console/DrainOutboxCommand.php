<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Illuminate\Console\Command;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\Concerns\SkipsWhenPluginDisabled;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\NotificationDispatcher;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;

class DrainOutboxCommand extends Command
{
    use SkipsWhenPluginDisabled;

    protected $signature = 'iapm:drain-outbox {--limit=}';

    protected $description = 'Enqueue due durable notification outbox rows';

    public function handle(NotificationDispatcher $dispatcher, SettingStore $settings): int
    {
        if ($this->pluginDisabled()) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) ($this->option('limit') ?: config('iapm.queue.drain_batch', 1000)));
        $queued = $dispatcher->enqueueDue($limit);
        $settings->put('last_outbox_drain_at', now()->toIso8601String());
        $this->info("Queued {$queued} due outbox row(s).");

        return self::SUCCESS;
    }
}
