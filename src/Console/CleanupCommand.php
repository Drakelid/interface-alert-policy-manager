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
        $outboxCount = DB::table('iapm_notification_outbox')->whereIn('incident_id', (clone $recovered)->select('id'))->count();
        $this->table(['Record type', 'Eligible'], [['Recovered incidents', $incidentCount], ['Incident events', $eventCount], ['Delivery logs', $deliveryCount], ['Notification outbox', $outboxCount], ['Audit logs', $auditCount], ['Outage records', $outageCount]]);
        if (! $this->option('force')) {
            $this->info('Dry-run only. Re-run with --force to delete eligible records.');

            return self::SUCCESS;
        }
        // Batched, autocommitting deletes — never one giant transaction. At ISP scale a
        // year of recovered incidents (plus cascaded events/deliveries) is tens of
        // millions of rows; a single transaction would hold locks and bloat the undo log.
        $this->purge(fn () => DB::table('iapm_audit_logs')->where('created_at', '<', $cutoff));
        $this->purge(fn () => DB::table('iapm_outages')->whereNotNull('recovered_at')->where('recovered_at', '<', $cutoff));
        // Deleting the incident cascades its events and delivery logs at the DB level.
        $this->purge(fn () => DB::table('iapm_incidents')->where('state', 'recovered')->where('recovered_at', '<', $cutoff));
        $this->info('Retention cleanup completed.');

        return self::SUCCESS;
    }

    /** Delete matching rows in bounded batches so no single statement locks the table. */
    private function purge(\Closure $builder, int $batch = 1000): void
    {
        do {
            $deleted = $builder()->limit($batch)->delete();
        } while ($deleted >= $batch);
    }
}
