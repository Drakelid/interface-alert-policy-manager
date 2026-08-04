<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\IapmServiceProvider;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ReadinessService;

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
            }
            $ok = $ok && $check['ok'];
        }

        $scheduled = collect($schedule->events())->contains(fn ($event) => str_contains((string) ($event->command ?? ''), 'iapm:reconcile'))
            && collect($schedule->events())->contains(fn ($event) => str_contains((string) ($event->command ?? ''), 'iapm:process-actions'));
        $this->report('scheduler_registration', $scheduled);
        $ok = $ok && $scheduled;

        $this->line('[INFO] queue='.$this->laravel['config']->get('queue.default', 'sync').'; IAPM uses durable database incidents/actions and does not require queues.');
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
