<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\IapmServiceProvider;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\QueueHeartbeat;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ReadinessService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;

class InstallCheckCommand extends Command
{
    protected $signature = 'iapm:install-check {--gateway : Perform a network request using the first enabled SMS destination}';

    protected $description = 'Check IAPM installation and required configuration';

    public function handle(ReadinessService $readiness, PluginManagerInterface $plugins, Schedule $schedule): int
    {
        $ok = true;

        // Plugin registration and scheduler are CLI-specific; the shared readiness
        // service covers migrations, encryption, storage, and configuration so the
        // CLI and the in-UI checklist never disagree.
        $registered = $plugins->pluginExists(IapmServiceProvider::PLUGIN_NAME) && $plugins->pluginEnabled(IapmServiceProvider::PLUGIN_NAME);
        $this->report('plugin_registration', $registered);
        $ok = $registered;

        foreach ($readiness->checks() as $check) {
            // 'info' checks (e.g. whether LibreNMS has posted an alert yet) are
            // guidance, not a pass/fail gate, so they never affect the exit code.
            if (($check['group'] ?? '') === 'info') {
                $this->line(($check['ok'] ? '[OK] ' : '[INFO] ').$check['key'].($check['ok'] ? '' : ' — '.$check['hint']));

                continue;
            }
            $this->report($check['key'], $check['ok']);
            if (! $check['ok']) {
                $this->line('  '.$check['hint']);
                // Shell remediation lives only here; the UI renders `hint` alone.
                if (! empty($check['cli_hint'])) {
                    $this->line('  '.$check['cli_hint']);
                }
            }
            $ok = $ok && $check['ok'];
        }

        $scheduled = collect($schedule->events())->contains(fn ($event) => str_contains((string) ($event->command ?? ''), 'iapm:reconcile'))
            && collect($schedule->events())->contains(fn ($event) => str_contains((string) ($event->command ?? ''), 'iapm:process-actions'));
        $this->report('scheduler_registration', $scheduled);
        $ok = $ok && $scheduled;

        $dispatchMode = app(SettingStore::class)->get('dispatch_mode', 'queue');
        $queueConnection = config('iapm.queue.connection') ?: $this->laravel['config']->get('queue.default', 'sync');
        $managedWorkers = max(0, (int) config('iapm.queue.workers', 3));
        $workerMode = $managedWorkers > 0 ? "scheduler-managed workers={$managedWorkers}" : 'externally supervised workers required';
        $this->line('[INFO] delivery='.$dispatchMode.($dispatchMode === 'queue'
            ? ' (IAPM queue connection='.$queueConnection.'; '.$workerMode.'; queue='.config('iapm.queue.name', 'iapm').')'
            : ' (synchronous, sent inside the scheduled run)'));
        if ($dispatchMode === 'queue') {
            // Worker liveness is proven by a consumed heartbeat, not by the queue
            // being empty, so point at the check that actually answers the question.
            $heartbeat = app(QueueHeartbeat::class);
            $consumed = $heartbeat->consumedAt();
            $this->line('[INFO] queue worker heartbeat='.($consumed ? 'last consumed '.$consumed->diffForHumans() : 'none consumed yet')
                .' (stale after '.$heartbeat->staleAfterSeconds().'s; run iapm:health for the verdict)');
        }
        $this->line('[INFO] dry_run='.($readiness->dryRun() ? 'enabled (no external delivery)' : 'disabled (live delivery)'));

        if ($this->option('gateway')) {
            $this->warn('Gateway reachability is intentionally checked with iapm:test-destination so a receiver and explicit send confirmation are required.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function report(string $key, bool $ok): void
    {
        $this->line(($ok ? '[OK] ' : '[FAIL] ').$key);
    }
}
