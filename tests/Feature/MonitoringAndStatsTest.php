<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Outage;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\HealthService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class MonitoringAndStatsTest extends IntegrationTestCase
{
    public function test_health_is_unhealthy_when_the_scheduler_has_not_run(): void
    {
        $this->artisan('iapm:health')
            ->expectsOutputToContain('[FAIL] Reconciliation running')
            ->assertExitCode(1);
    }

    public function test_health_passes_once_the_scheduled_commands_have_run(): void
    {
        $this->settings->put('last_reconcile_at', now()->toIso8601String());
        $this->settings->put('last_process_actions_at', now()->toIso8601String());

        self::assertTrue(app(HealthService::class)->healthy());
        $this->artisan('iapm:health')->assertExitCode(0);
    }

    public function test_reconcile_records_its_run_timestamp(): void
    {
        $this->artisan('iapm:reconcile')->assertExitCode(0);

        self::assertNotNull($this->settings->get('last_reconcile_at'));
    }

    public function test_recovering_an_incident_writes_an_outage_record(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();
        $this->ingest($this->alertPayload($device, [], 0, ['timestamp' => now()->addMinute()->toIso8601String()]))->assertOk();

        self::assertSame(1, Outage::count());
        $outage = Outage::first();
        self::assertSame((int) $port->port_id, (int) $outage->port_id);
        self::assertNotNull($outage->recovered_at);
        self::assertNotNull($outage->duration_seconds);
    }

    public function test_the_stats_page_renders_sla_metrics(): void
    {
        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager/stats')
            ->assertOk()
            ->assertSee('MTTR')
            ->assertSee('Noisiest interfaces');
    }

    public function test_the_comparison_csv_export_downloads(): void
    {
        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager/comparison-report/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
}
