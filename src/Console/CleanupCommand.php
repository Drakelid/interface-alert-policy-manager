<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\Concerns\SkipsWhenPluginDisabled;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;

class CleanupCommand extends Command
{
    use SkipsWhenPluginDisabled;

    protected $signature = 'iapm:cleanup {--force : Delete instead of previewing}';
    protected $description = 'Preview or clean retained IAPM history without deleting open incidents';

    public function handle(SettingStore $settings): int
    {
        if ($this->pluginDisabled()) {
            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $settings->get('retention_days', 365));
        $recovered = Incident::where('state', 'recovered')->where('recovered_at', '<', $cutoff);
        $incidentCount = (clone $recovered)->count();
        $eventCount = DB::table('iapm_incident_events')->whereIn('incident_id', (clone $recovered)->select('id'))->count();
        $deliveryCount = DB::table('iapm_delivery_logs')->whereIn('incident_id', (clone $recovered)->select('id'))->count();
        $auditCount = DB::table('iapm_audit_logs')->where('created_at', '<', $cutoff)->count();
        $outageCount = DB::table('iapm_outages')->whereNotNull('recovered_at')->where('recovered_at', '<', $cutoff)->count();
        $this->table(['Record type', 'Eligible'], [['Recovered incidents', $incidentCount], ['Incident events', $eventCount], ['Delivery logs', $deliveryCount], ['Audit logs', $auditCount], ['Outage records', $outageCount]]);
        if (! $this->option('force')) { $this->info('Dry-run only. Re-run with --force to delete eligible records.'); return self::SUCCESS; }
        DB::transaction(function () use ($recovered, $cutoff): void { DB::table('iapm_audit_logs')->where('created_at', '<', $cutoff)->delete(); DB::table('iapm_outages')->whereNotNull('recovered_at')->where('recovered_at', '<', $cutoff)->delete(); $recovered->chunkById(500, fn ($incidents) => Incident::whereKey($incidents->modelKeys())->delete()); });
        $this->info('Retention cleanup completed.'); return self::SUCCESS;
    }
}
