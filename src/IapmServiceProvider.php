<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\CacheClearCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\CacheRebuildCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\CleanupCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\DrainIngestionCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\DrainOutboxCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\HealthCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\InstallCheckCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\ProcessActionsCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\QueueHeartbeatCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\ReconcileCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\RecoverSimulationsCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\TestDestinationCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\TestPolicyCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Hooks\MenuEntry;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Hooks\Settings;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\PluginNotFoundRenderer;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;

class IapmServiceProvider extends ServiceProvider
{
    public const PLUGIN_NAME = 'interface-alert-policy-manager';

    public const ABILITIES = ['view iapm', 'manage iapm policies', 'manage iapm assignments', 'manage iapm destinations', 'manage iapm settings', 'acknowledge iapm incidents', 'mute iapm incidents', 'test iapm destinations', 'view iapm audit logs'];

    /**
     * Offset between consecutive scheduler-managed workers' lifetimes. Without it
     * every worker starts and exits on the same tick, so the queue is unattended
     * until the next one. 60s is one scheduler tick, which is enough to guarantee
     * the recycle windows never coincide.
     */
    private const WORKER_RECYCLE_STAGGER_SECONDS = 60;

    /**
     * Ceiling on how long a killed scheduler-managed worker may stay unreplaced:
     * its overlap lock, plus the scheduler tick needed to notice the lock is gone.
     */
    private const MAX_RESPAWN_MINUTES = 9;

    /**
     * Overlap-lock window, in minutes, for a scheduler-managed queue worker.
     *
     * The lock must outlive the worker, or the scheduler starts a second worker
     * under the same name on every tick after it expires and process count grows
     * without bound. It must also not outlive it by much: a worker killed without
     * running schedule:finish (OOM kill, container stop, SIGKILL) leaves the lock
     * held, and nothing replaces that worker until it expires. So bound it just
     * above the true worst-case lifetime — --max-time only stops the worker
     * accepting new jobs, so one in-flight job may still run for the full job
     * timeout after that — and add a minute of slack for a slow shutdown.
     */
    private function workerLockMinutes(int $maxSeconds, int $jobTimeout): int
    {
        return (int) ceil(($maxSeconds + $jobTimeout) / 60) + 1;
    }

    /**
     * Longest worker lifetime whose lock still fits the respawn ceiling.
     *
     * A worker cannot be replaced faster than one job can run, so a job timeout
     * at or above the whole budget makes the ceiling unreachable; the floor keeps
     * the worker useful rather than pretending otherwise.
     */
    private function workerLifetimeCeiling(int $jobTimeout): int
    {
        return max(60, ((self::MAX_RESPAWN_MINUTES - 2) * 60) - $jobTimeout);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/iapm.php', 'iapm');
        $this->app->singleton(SettingStore::class);
        if (config('logging.channels.iapm') === null) {
            config(['logging.channels.iapm' => ['driver' => 'single', 'path' => storage_path('logs/iapm.log'), 'level' => 'info', 'replace_placeholders' => true]]);
        }
    }

    public function boot(PluginManagerInterface $plugins): void
    {
        foreach (self::ABILITIES as $ability) {
            Gate::define($ability, function (User $user) use ($ability): bool {
                if ($user->hasRole('admin')) {
                    return true;
                } try {
                    return $user->hasPermissionTo($ability);
                } catch (\Throwable) {
                    return false;
                }
            });
        }
        $plugins->publishHook(self::PLUGIN_NAME, MenuEntryHook::class, MenuEntry::class);
        $plugins->publishHook(self::PLUGIN_NAME, SettingsHook::class, Settings::class);

        // A 404 under the plugin prefix should still show the plugin's navigation.
        // Resolved lazily so a console run never builds the exception handler.
        $this->callAfterResolving(ExceptionHandler::class, PluginNotFoundRenderer::register(...));

        // Registered unconditionally: `php artisan migrate` must work before the
        // plugin row exists, and routes carry their own enablement middleware.
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'iapm');
        $this->publishes([__DIR__.'/../config/iapm.php' => config_path('iapm.php')], 'iapm-config');

        // Registered for web requests too, not just the console. The incident
        // screen's "reconcile now" and "resend" buttons call Artisan::call(), and
        // a console-only registration made those actions fail with
        // CommandNotFoundException in the browser. Registration is lazy — the
        // command classes are only resolved when one is actually run — so there
        // is no cost to an ordinary web request.
        $this->commands([CacheClearCommand::class, CacheRebuildCommand::class, CleanupCommand::class, DrainIngestionCommand::class, DrainOutboxCommand::class, HealthCommand::class, InstallCheckCommand::class, ProcessActionsCommand::class, QueueHeartbeatCommand::class, ReconcileCommand::class, RecoverSimulationsCommand::class, TestDestinationCommand::class, TestPolicyCommand::class]);

        // The scheduler is resolved during app boot, when the plugins table may
        // not exist yet on a fresh install. So entries are always registered and
        // each scheduled command checks enablement when it actually runs.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // A 10-minute lock expiry: without it Laravel holds the overlap lock for
            // 24h, so a run killed mid-outage (OOM, deploy) would silently freeze all
            // processing for a day. 10m lets a stuck run self-clear on the next tick.
            // Runs first so a real simulation's down overlay survives a physical
            // poll, and restores expired simulations before normal reconciliation.
            $schedule->command('iapm:recover-simulations')->everyMinute()->withoutOverlapping(10);
            $schedule->command('iapm:reconcile')->everyMinute()->withoutOverlapping(10);
            $schedule->command('iapm:process-actions')->everyMinute()->withoutOverlapping(10);
            $schedule->command('iapm:drain-outbox')->everyMinute()->withoutOverlapping(10);
            $inboxWorkers = max(1, (int) config('iapm.ingestion.inbox_workers', 2));
            $inboxBatch = max(1, min(100, (int) config('iapm.ingestion.inbox_batch_per_worker', 1)));
            for ($i = 1; $i <= $inboxWorkers; $i++) {
                $schedule->command("iapm:drain-ingestion --worker={$i} --limit={$inboxBatch}")->everyMinute()->withoutOverlapping(20)->runInBackground();
            }
            $schedule->command('iapm:cleanup --force')->dailyAt('02:35')->withoutOverlapping(10);

            // Refresh the Interface Matrix's materialized policy resolution once
            // per hour. A long rebuild cannot overlap the next scheduled run,
            // and the command also yields to a rebuild started from the UI.
            $schedule->command('iapm:cache-rebuild')->hourly()->withoutOverlapping(180)->runInBackground();

            // Worker-liveness heartbeat. Registered unconditionally and gated
            // inside the command, so it covers both scheduler-managed workers and
            // externally supervised ones (IAPM_QUEUE_WORKERS=0), and follows a
            // change of Delivery dispatch without needing a restart. It enqueues
            // at most one outstanding job, so a stopped worker cannot make it
            // accumulate.
            $schedule->command('iapm:queue-heartbeat')->everyMinute()->withoutOverlapping(5);

            // When queued dispatch is enabled (the default), keep queue workers running
            // via the scheduler so notifications drain without requiring systemd. Each is
            // a background, non-overlapping worker that recycles every few minutes (avoids
            // leaks, picks up new code). --name makes each command distinct so N run in
            // parallel. For heavier throughput add dedicated systemd workers (they safely
            // share the same queue); set IAPM_QUEUE_WORKERS=0 to let the scheduler manage none.
            try {
                $queued = app(SettingStore::class)->get('dispatch_mode', 'queue') === 'queue';
            } catch (\Throwable) {
                $queued = true;
            }
            $workers = max(0, (int) config('iapm.queue.workers', 3));
            $conn = config('iapm.queue.connection');
            // Don't spawn database workers before the jobs table exists (fresh install
            // pre-migration) — they'd just crash-loop. Redis needs no such table.
            $backendReady = $conn === 'redis' || (function () {
                try {
                    return Schema::hasTable('jobs');
                } catch (\Throwable) {
                    return false;
                }
            })();
            if ($queued && $workers > 0 && $backendReady) {
                $jobTimeout = max(1, (int) config('iapm.queue.timeout', 60));
                $lifetimeCeiling = $this->workerLifetimeCeiling($jobTimeout);
                $baseMaxSeconds = max(60, (int) config('iapm.queue.worker_max_seconds', 240));
                for ($i = 1; $i <= $workers; $i++) {
                    // Stagger each worker's lifetime so they do not all exit on the
                    // same tick and leave the queue briefly unattended, but never
                    // past the ceiling — the lock follows the lifetime, and a lock
                    // longer than the respawn budget is the outage this avoids.
                    $maxSeconds = min($lifetimeCeiling, $baseMaxSeconds + (($i - 1) * self::WORKER_RECYCLE_STAGGER_SECONDS));
                    $args = $conn ? [$conn] : [];
                    $args += ['--queue' => (string) config('iapm.queue.name', 'iapm'), '--name' => 'iapm-'.$i, '--sleep' => 1, '--tries' => (int) config('iapm.queue.tries', 3), '--timeout' => $jobTimeout, '--backoff' => (int) config('iapm.queue.retry_base_seconds', 15), '--max-time' => $maxSeconds];
                    $schedule->command('queue:work', $args)->everyMinute()->withoutOverlapping($this->workerLockMinutes($maxSeconds, $jobTimeout))->runInBackground();
                }
            }
        });
    }
}
