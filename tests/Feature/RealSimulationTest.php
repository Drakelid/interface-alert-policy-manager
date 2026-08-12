<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\Port;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\AuditLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Simulation;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;
use Spatie\Permission\Models\Permission;

class RealSimulationTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager/tools/real-simulations';

    public function test_the_page_explains_that_notifications_are_real_and_offers_recovery(): void
    {
        $this->actingAs($this->admin())->get(self::BASE)
            ->assertOk()
            ->assertSee('This sends real notifications')
            ->assertDontSee('SEND REAL ALERTS')
            ->assertSee('Automatic recovery');
    }

    public function test_start_runs_the_real_incident_and_delivery_pipeline(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $port = $this->upPortWithNotifyingPolicy();

        $this->actingAs($this->admin())->post(self::BASE, [
            'port_id' => $port->port_id,
            'duration_seconds' => 600,
        ])->assertRedirect(self::BASE);

        $simulation = Simulation::firstOrFail();
        self::assertSame('running', $simulation->status);
        self::assertSame('down', $this->status($port->fresh()->ifOperStatus));

        $incident = Incident::findOrFail($simulation->incident_id);
        self::assertSame(IncidentState::Active, $incident->state);
        self::assertSame($simulation->episode_uuid, $incident->episode_uuid);
        self::assertTrue(DeliveryLog::where('incident_id', $incident->id)->where('phase', 'trigger')->where('status', 'sent')->exists());
        self::assertTrue(AuditLog::where('action', 'started_real_simulation')->where('object_id', $simulation->id)->exists());
    }

    public function test_manual_recovery_restores_the_port_and_sends_recovery(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $port = $this->upPortWithNotifyingPolicy(withRecovery: true);
        $admin = $this->admin();
        $this->actingAs($admin)->post(self::BASE, [
            'port_id' => $port->port_id,
            'duration_seconds' => 600,
        ]);
        $simulation = Simulation::firstOrFail();

        $this->actingAs($admin)->post(self::BASE."/{$simulation->id}/recover")->assertRedirect();

        $simulation->refresh();
        $incident = $simulation->incident()->firstOrFail();
        self::assertSame('recovered', $simulation->status);
        self::assertSame('up', $this->status($port->fresh()->ifOperStatus));
        self::assertSame(IncidentState::Recovered, $incident->state);
        self::assertTrue(DeliveryLog::where('incident_id', $incident->id)->where('phase', 'recovery')->where('status', 'sent')->exists());
    }

    public function test_due_recovery_command_restores_the_port(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $port = $this->upPortWithNotifyingPolicy();
        $this->actingAs($this->admin())->post(self::BASE, [
            'port_id' => $port->port_id,
            'duration_seconds' => 60,
        ]);

        $this->travel(61)->seconds();
        $this->artisan('iapm:recover-simulations')->assertExitCode(0);

        self::assertSame('recovered', Simulation::firstOrFail()->status);
        self::assertSame('up', $this->status($port->fresh()->ifOperStatus));
    }

    public function test_the_scheduler_reasserts_a_running_simulation_after_a_poll(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $port = $this->upPortWithNotifyingPolicy();
        $this->actingAs($this->admin())->post(self::BASE, [
            'port_id' => $port->port_id,
            'duration_seconds' => 600,
        ]);
        $port->update(['ifOperStatus' => 'up']); // emulate a physical poll

        $this->artisan('iapm:recover-simulations')->assertExitCode(0);

        self::assertSame('running', Simulation::firstOrFail()->status);
        self::assertSame('down', $this->status($port->fresh()->ifOperStatus));
    }

    public function test_a_port_that_is_not_up_is_rejected_without_mutation(): void
    {
        $port = $this->downPort($this->device());

        $this->actingAs($this->admin())->from(self::BASE)->post(self::BASE, [
            'port_id' => $port->port_id,
            'duration_seconds' => 600,
        ])->assertRedirect(self::BASE)->assertSessionHas('error');

        self::assertSame(0, Simulation::count());
        self::assertSame('down', $this->status($port->fresh()->ifOperStatus));
    }

    public function test_a_viewer_cannot_start_a_real_simulation(): void
    {
        Permission::findOrCreate('view iapm', 'web');
        $viewer = User::factory()->create(['enabled' => true]);
        $viewer->givePermissionTo('view iapm');
        $port = $this->downPort($this->device(), ['ifOperStatus' => 'up']);

        $this->actingAs($viewer)->post(self::BASE, [
            'port_id' => $port->port_id,
            'duration_seconds' => 600,
        ])->assertForbidden();

        self::assertSame(0, Simulation::count());
        self::assertSame('up', $this->status($port->fresh()->ifOperStatus));
    }

    public function test_repeated_start_requests_do_not_hit_a_shared_rate_limit(): void
    {
        $admin = $this->admin();

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->actingAs($admin)
                ->post(self::BASE, [])
                ->assertRedirect()
                ->assertSessionHasErrors(['port_id', 'duration_seconds']);
        }
    }

    private function upPortWithNotifyingPolicy(bool $withRecovery = false): Port
    {
        $this->settings->put('last_simulation_maintenance_at', now()->toIso8601String());
        $policy = $this->defaultPolicy(['trigger_after_seconds' => 0, 'down_observations' => 1, 'recovery_after_seconds' => 0]);
        $destination = $this->smsDestination();
        $this->triggerAction($policy, $destination);
        if ($withRecovery) {
            $this->triggerAction($policy, $destination, ['phase' => 'recovery', 'sort_order' => 1]);
        }

        return $this->downPort($this->device(), ['ifOperStatus' => 'up']);
    }

    private function status(mixed $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }
}
