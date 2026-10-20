<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Simulation;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\RealSimulationService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class SimulationConcurrencyTest extends IntegrationTestCase
{
    public function test_maintenance_does_not_reassert_a_simulation_recovered_after_batch_load(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('last_simulation_maintenance_at', now()->toIso8601String());
        $policy = $this->defaultPolicy();
        $this->triggerAction($policy, $this->smsDestination());
        $port = $this->downPort($this->device(), ['ifOperStatus' => 'up']);
        $service = app(RealSimulationService::class);
        $simulation = $service->start(Request::create('/'), $port, 600, 'up', 'down', true);
        $interleaved = false;
        Simulation::retrieved(function () use ($simulation, $service, &$interleaved): void {
            if (! $interleaved) {
                $interleaved = true;
                $service->recover($simulation);
            }
        });
        try {
            $counts = $service->recoverDue();
        } finally {
            Simulation::getEventDispatcher()->forget('eloquent.retrieved: '.Simulation::class);
        }

        self::assertTrue($interleaved);
        self::assertSame('recovered', $simulation->fresh()->status);
        $oper = $port->fresh()->ifOperStatus;
        self::assertSame('up', $oper instanceof \BackedEnum ? $oper->value : $oper);
        self::assertSame(0, $counts['maintained']);
    }
}
