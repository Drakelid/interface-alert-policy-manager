<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\AuditLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Outage;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class CsvExportSecurityTest extends IntegrationTestCase
{
    public function test_every_csv_export_neutralizes_untrusted_formula_cells(): void
    {
        $formula = '=1+1';
        $admin = $this->admin();
        $admin->update(['username' => $formula]);
        $policy = $this->defaultPolicy(['name' => $formula]);
        $port = $this->downPort($this->device(), ['ifAlias' => $formula]);
        $incident = $this->incident($policy, $port);
        Outage::create(['incident_id' => $incident->id, 'episode_uuid' => $incident->episode_uuid, 'device_id' => $incident->device_id, 'port_id' => $incident->port_id, 'policy_id' => $policy->id, 'severity' => 'critical', 'started_at' => now()->subHour(), 'recovered_at' => now(), 'duration_seconds' => 60]);
        $destination = $this->smsDestination();
        $destination->update(['name' => $formula]);
        DeliveryLog::create(['destination_id' => $destination->id, 'incident_id' => $incident->id, 'phase' => 'trigger', 'status' => 'failed', 'error_message' => $formula]);
        AuditLog::create(['user_id' => $admin->user_id, 'action' => 'updated', 'object_type' => 'policy', 'object_id' => $policy->id, 'created_at' => now()]);
        $this->actingAs($admin);

        $matrix = $this->rows('/interface-matrix/export');
        self::assertSame("'=1+1", $matrix[1][4]);
        self::assertSame("'=1+1", $matrix[1][7]);
        $deliveries = $this->rows('/delivery-log/export');
        self::assertSame("'=1+1", $deliveries[1][2]);
        self::assertSame("'=1+1", $deliveries[1][6]);
        $audit = $this->rows('/audit-log/export');
        self::assertSame("'=1+1", $audit[1][1]);
        $comparison = $this->rows('/comparison-report/export');
        self::assertContains("'=1+1", array_column($comparison, 0));
    }

    private function rows(string $path): array
    {
        $csv = $this->get('/plugin/interface-alert-policy-manager'.$path)->assertOk()->streamedContent();
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
