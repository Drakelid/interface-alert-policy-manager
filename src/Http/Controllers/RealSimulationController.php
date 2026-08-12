<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use App\Models\Port;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Simulation;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\EntityLookup;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\QueueHeartbeat;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\RealSimulationService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;

class RealSimulationController extends Controller
{
    public function index(Request $request, EntityLookup $lookup, SettingStore $settings, QueueHeartbeat $heartbeat, ViewFactory $views)
    {
        $port = $request->filled('port_id') ? Port::with('device')->find($request->integer('port_id')) : null;
        $simulations = Simulation::with(['port.device', 'incident.policy', 'incident.deliveries'])->latest('id')->limit(25)->get();
        $lastSimulationMaintenance = $settings->get('last_simulation_maintenance_at');
        try {
            $lastSimulationMaintenanceAt = is_string($lastSimulationMaintenance) ? CarbonImmutable::parse($lastSimulationMaintenance) : null;
            $simulationRecoveryReady = $lastSimulationMaintenanceAt?->addMinutes(10)->isFuture() ?? false;
            $simulationRecoveryDetail = $lastSimulationMaintenanceAt ? 'Last safety pass '.$lastSimulationMaintenanceAt->diffForHumans().'.' : 'The recovery scheduler has not run yet.';
        } catch (\Throwable) {
            $simulationRecoveryReady = false;
            $simulationRecoveryDetail = 'The recovery scheduler timestamp is invalid.';
        }

        return $views->make('iapm::real-simulations', [
            'simulations' => $simulations,
            'portLabel' => $port ? $lookup->portLabel($port) : '',
            'dryRun' => (bool) $settings->get('dry_run', true),
            'dispatchMode' => (string) $settings->get('dispatch_mode', 'queue'),
            'queueHealth' => $heartbeat->status(),
            'simulationRecoveryReady' => $simulationRecoveryReady,
            'simulationRecoveryDetail' => $simulationRecoveryDetail,
        ]);
    }

    public function store(Request $request, RealSimulationService $simulations)
    {
        abort_unless($request->user()->can('manage iapm policies'), 403);
        $data = $request->validate([
            'port_id' => ['required', 'integer', 'exists:ports,port_id'],
            'duration_seconds' => ['required', 'integer', 'between:60,86400'],
        ]);

        try {
            $simulation = $simulations->start(
                $request,
                Port::with(['device.location', 'device.groups', 'groups'])->findOrFail($data['port_id']),
                (int) $data['duration_seconds'],
                true,
            );
        } catch (\DomainException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'The simulation failed and the interface state was restored. Check the LibreNMS log for details.');
        }

        return redirect()->route('iapm.real-simulations.index')
            ->with('status', "Real simulation #{$simulation->id} started. The interface will be restored automatically.");
    }

    public function recover(Request $request, Simulation $simulation, RealSimulationService $simulations)
    {
        abort_unless($request->user()->can('manage iapm policies'), 403);
        try {
            $result = $simulations->recover($simulation, $request, 'manual');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'The port was restored, but the recovery pipeline reported an error. Check the LibreNMS log for details.');
        }

        if ($result->status !== 'recovered') {
            return back()->with('status', "Simulation #{$simulation->id} recovery is already in progress.");
        }

        return back()->with('status', "Simulation #{$simulation->id} recovered and its original port state was restored.");
    }
}
