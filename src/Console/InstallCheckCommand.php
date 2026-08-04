<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\IapmServiceProvider;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;

class InstallCheckCommand extends Command
{
    protected $signature = 'iapm:install-check {--gateway : Perform a network request using the first enabled SMS destination}';
    protected $description = 'Check IAPM installation and required configuration';

    public function handle(SettingStore $settings, PluginManagerInterface $plugins, Schedule $schedule): int
    {
        $tables = ['iapm_policies','iapm_assignments','iapm_destinations','iapm_policy_actions','iapm_incidents','iapm_incident_events','iapm_delivery_logs','iapm_settings','iapm_interface_policy_cache','iapm_audit_logs'];
        $checks = [
            'plugin_registration' => fn () => $plugins->pluginExists(IapmServiceProvider::PLUGIN_NAME) && $plugins->pluginEnabled(IapmServiceProvider::PLUGIN_NAME),
            'database' => fn () => DB::connection()->getPdo() !== null,
            'migrations' => fn () => collect($tables)->every(fn ($table) => Schema::hasTable($table)),
            'writable_storage' => fn () => is_writable(storage_path()) && is_writable(storage_path('logs')),
            'encryption_key' => fn () => filled(config('app.key')),
            'scheduler_registration' => fn () => collect($schedule->events())->contains(fn ($event) => str_contains((string) ($event->command ?? ''), 'iapm:reconcile')) && collect($schedule->events())->contains(fn ($event) => str_contains((string) ($event->command ?? ''), 'iapm:process-actions')),
            'enabled_destination' => fn () => Destination::where('enabled', true)->exists(),
            'ingestion_token' => fn () => filled($settings->get('ingestion_token')),
            'default_policy' => fn () => DB::table('iapm_assignments')->where('assignment_type', 'default')->where('enabled', true)->exists() || filled($settings->get('default_policy_id')),
            'sms_receiver' => fn () => filled($settings->get('sms_default_receiver', config('iapm.sms.default_receiver'))) || Destination::where('type', 'sms_gateway')->get()->contains(fn ($d) => filled($d->configuration_encrypted['default_receiver'] ?? null)),
        ];
        $ok = true;
        foreach ($checks as $name => $check) { try { $pass = (bool) $check(); } catch (\Throwable $e) { $pass = false; $this->line("  {$e->getMessage()}"); } $this->line(($pass ? '[OK] ' : '[FAIL] ').$name); $ok = $ok && $pass; }
        $this->line('[INFO] queue='.$this->laravel['config']->get('queue.default', 'sync').'; IAPM uses durable database incidents/actions and does not require queues.');
        if ($this->option('gateway')) $this->warn('Gateway reachability is intentionally checked with iapm:test-destination so a receiver and explicit send confirmation are required.');
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
