<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\CacheClearCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\CacheRebuildCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\CleanupCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\HealthCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\InstallCheckCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\ProcessActionsCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\ReconcileCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\TestDestinationCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\TestPolicyCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Hooks\MenuEntry;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Hooks\Settings;

class IapmServiceProvider extends ServiceProvider
{
    public const PLUGIN_NAME = 'interface-alert-policy-manager';

    public const ABILITIES = ['view iapm', 'manage iapm policies', 'manage iapm assignments', 'manage iapm destinations', 'manage iapm settings', 'acknowledge iapm incidents', 'mute iapm incidents', 'test iapm destinations', 'view iapm audit logs'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/iapm.php', 'iapm');
        if (config('logging.channels.iapm') === null) config(['logging.channels.iapm' => ['driver' => 'single', 'path' => storage_path('logs/iapm.log'), 'level' => 'info', 'replace_placeholders' => true]]);
    }

    public function boot(PluginManagerInterface $plugins): void
    {
        foreach (self::ABILITIES as $ability) {
            Gate::define($ability, function (\App\Models\User $user) use ($ability): bool { if ($user->hasRole('admin')) return true; try { return $user->hasPermissionTo($ability); } catch (\Throwable) { return false; } });
        }
        $plugins->publishHook(self::PLUGIN_NAME, MenuEntryHook::class, MenuEntry::class);
        $plugins->publishHook(self::PLUGIN_NAME, SettingsHook::class, Settings::class);

        // Registered unconditionally: `php artisan migrate` must work before the
        // plugin row exists, and routes carry their own enablement middleware.
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'iapm');
        $this->publishes([__DIR__.'/../config/iapm.php' => config_path('iapm.php')], 'iapm-config');

        if ($this->app->runningInConsole()) {
            $this->commands([CacheClearCommand::class, CacheRebuildCommand::class, CleanupCommand::class, HealthCommand::class, InstallCheckCommand::class, ProcessActionsCommand::class, ReconcileCommand::class, TestDestinationCommand::class, TestPolicyCommand::class]);
        }

        // The scheduler is resolved during app boot, when the plugins table may
        // not exist yet on a fresh install. So entries are always registered and
        // each scheduled command checks enablement when it actually runs.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // A 10-minute lock expiry: without it Laravel holds the overlap lock for
            // 24h, so a run killed mid-outage (OOM, deploy) would silently freeze all
            // processing for a day. 10m lets a stuck run self-clear on the next tick.
            $schedule->command('iapm:reconcile')->everyMinute()->withoutOverlapping(10);
            $schedule->command('iapm:process-actions')->everyMinute()->withoutOverlapping(10);
            $schedule->command('iapm:cleanup --force')->dailyAt('02:35')->withoutOverlapping(10);

            // When queued dispatch is enabled (the default), keep queue workers running
            // via the scheduler so notifications drain without requiring systemd. Each is
            // a background, non-overlapping worker that recycles hourly (avoids leaks,
            // picks up new code). --name makes each command distinct so N run in parallel.
            // For heavier throughput add dedicated systemd workers (they safely share the
            // same queue); set IAPM_QUEUE_WORKERS=0 to let the scheduler manage none.
            try { $queued = app(\LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore::class)->get('dispatch_mode', 'queue') === 'queue'; } catch (\Throwable) { $queued = true; }
            $workers = max(0, (int) config('iapm.queue.workers', 3));
            $conn = config('iapm.queue.connection');
            // Don't spawn database workers before the jobs table exists (fresh install
            // pre-migration) — they'd just crash-loop. Redis needs no such table.
            $backendReady = $conn === 'redis' || (function () { try { return \Illuminate\Support\Facades\Schema::hasTable('jobs'); } catch (\Throwable) { return false; } })();
            if ($queued && $workers > 0 && $backendReady) {
                for ($i = 1; $i <= $workers; $i++) {
                    $args = $conn ? [$conn] : [];
                    $args += ['--queue' => (string) config('iapm.queue.name', 'iapm'), '--name' => 'iapm-'.$i, '--sleep' => 1, '--tries' => (int) config('iapm.queue.tries', 3), '--max-time' => 3600];
                    $schedule->command('queue:work', $args)->everyMinute()->withoutOverlapping(70)->runInBackground();
                }
            }
        });
    }
}
