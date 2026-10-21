<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\AuditLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Simulation;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\RealSimulationService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class SimulationOwnershipTest extends IntegrationTestCase
{
    public function test_recovering_a_failed_simulation_cannot_restore_a_newer_simulations_port(): void
    {
        $port = $this->simulationPort();
        $failed = $this->failedStart($port);
        $service = app(RealSimulationService::class);
        $current = $service->start(Request::create('/'), $port->fresh(), 600, 'up', 'down', true);
        $deliveries = DeliveryLog::count();

        $service->recover($failed);

        self::assertSame('failed', $failed->fresh()->status);
        self::assertSame('running', $current->fresh()->status);
        self::assertSame('down', $this->operStatus($port));
        self::assertSame(IncidentState::Active, $current->incident->fresh()->state);
        self::assertSame($deliveries, DeliveryLog::count());
    }

    public function test_recovering_a_failed_simulation_cannot_clear_a_genuine_outage(): void
    {
        $port = $this->simulationPort();
        $failed = $this->failedStart($port);
        $port->update(['ifOperStatus' => 'down']);
        $this->ingest($this->alertPayload($port->device, [['port_id' => $port->port_id]], overrides: ['alert_uid' => 'real-outage']))->assertOk();
        $incident = Incident::where('port_id', $port->port_id)->firstOrFail();
        $episode = $incident->episode_uuid;

        app(RealSimulationService::class)->recover($failed);

        self::assertSame('down', $this->operStatus($port));
        self::assertSame(IncidentState::Active, $incident->fresh()->state);
        self::assertSame('real-outage', $incident->fresh()->source_alert_uid);
        self::assertSame($episode, $incident->fresh()->episode_uuid);
    }

    public function test_a_running_simulation_cannot_recover_a_replacement_real_episode(): void
    {
        $port = $this->simulationPort();
        $service = app(RealSimulationService::class);
        $simulation = $service->start(Request::create('/'), $port, 600, 'up', 'down', true);
        $this->ingest($this->alertPayload($port->device, [['port_id' => $port->port_id]], overrides: ['alert_uid' => 'physical-alert']))->assertOk();

        $service->recover($simulation);

        self::assertSame('failed', $simulation->fresh()->status);
        self::assertSame('down', $this->operStatus($port));
        self::assertSame(IncidentState::Active, $simulation->incident->fresh()->state);
        self::assertSame('physical-alert', $simulation->incident->fresh()->source_alert_uid);
    }

    public function test_maintenance_does_not_overwrite_a_physical_observation_that_took_ownership(): void
    {
        $port = $this->simulationPort();
        $service = app(RealSimulationService::class);
        $simulation = $service->start(Request::create('/'), $port, 600, 'down', 'up', true);
        // The simulator writes directly to storage, so refresh before using
        // Eloquent's dirty tracking to emulate the physical poller's writes.
        $port->refresh()->update(['ifAdminStatus' => 'up', 'ifOperStatus' => 'down']);
        $admin = $port->fresh()->ifAdminStatus;
        self::assertSame('up', $admin instanceof \BackedEnum ? $admin->value : $admin);
        self::assertSame('down', $this->operStatus($port));
        $this->ingest($this->alertPayload($port->device, [['port_id' => $port->port_id]], overrides: ['alert_uid' => 'physical-alert']))->assertOk();

        $counts = $service->recoverDue();

        self::assertSame('failed', $simulation->fresh()->status);
        self::assertSame('down', $this->operStatus($port));
        $admin = $port->fresh()->ifAdminStatus;
        self::assertSame('up', $admin instanceof \BackedEnum ? $admin->value : $admin);
        self::assertSame(0, $counts['maintained']);
    }

    public function test_start_cannot_resurrect_a_simulation_recovered_during_ingestion(): void
    {
        $port = $this->simulationPort();
        $service = app(RealSimulationService::class);
        $interleaved = false;
        Incident::saved(function (Incident $incident) use ($service, &$interleaved): void {
            if (! $interleaved && $incident->state === IncidentState::Active) {
                $interleaved = true;
                $service->recover(Simulation::where('port_id', $incident->port_id)->firstOrFail());
            }
        });
        try {
            $simulation = $service->start(Request::create('/'), $port, 600, 'up', 'down', true);
        } finally {
            Incident::getEventDispatcher()->forget('eloquent.saved: '.Incident::class);
        }

        self::assertTrue($interleaved);
        self::assertSame('recovered', $simulation->status);
        self::assertSame('up', $this->operStatus($port));
        self::assertSame(IncidentState::Recovered, Incident::where('port_id', $port->port_id)->firstOrFail()->state);
        self::assertSame(0, $service->recoverDue()['maintained']);
        self::assertSame('up', $this->operStatus($port));
    }

    private function simulationPort(): Port
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('last_simulation_maintenance_at', now()->toIso8601String());
        $policy = $this->defaultPolicy(['trigger_after_seconds' => 0, 'recovery_after_seconds' => 0]);
        $this->triggerAction($policy, $this->smsDestination());

        return $this->downPort($this->device(), ['ifOperStatus' => 'up']);
    }

    private function failedStart(Port $port): Simulation
    {
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->action === 'started_real_simulation') {
                throw new \RuntimeException('Audit storage unavailable.');
            }
        });
        try {
            app(RealSimulationService::class)->start(Request::create('/'), $port, 600, 'up', 'down', false);
            self::fail('The injected audit failure must reach the caller.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Audit storage unavailable.', $exception->getMessage());
        } finally {
            AuditLog::getEventDispatcher()->forget('eloquent.creating: '.AuditLog::class);
        }
        $simulation = Simulation::where('port_id', $port->port_id)->firstOrFail();
        self::assertSame('failed', $simulation->status);
        self::assertSame('up', $this->operStatus($port));

        return $simulation;
    }

    private function operStatus(Port $port): string
    {
        $status = $port->fresh()->ifOperStatus;

        return $status instanceof \BackedEnum ? $status->value : $status;
    }
}
