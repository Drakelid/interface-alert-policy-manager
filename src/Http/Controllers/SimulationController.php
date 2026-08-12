<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Requests\IngestAlertRequest;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\EntityLookup;

/**
 * Admin convenience: fire a synthetic alert for a chosen interface through the
 * real ingestion pipeline (no token needed — gated by manage-policies), so an
 * operator can validate policy/assignment/suppression behaviour without curl.
 * Delivery still respects dry-run, since it happens in process-actions.
 */
class SimulationController extends Controller
{
    public function form(Request $request, EntityLookup $lookup)
    {
        // The Interface Matrix links here with ?port_id=, and the picker needs a
        // label for it so the operator sees the interface, not just a number.
        $port = $request->filled('port_id') ? Port::with('device')->find($request->integer('port_id')) : null;

        return view('iapm::simulate', ['result' => null, 'port' => null, 'portLabel' => $port ? $lookup->portLabel($port) : '']);
    }

    public function run(Request $request, EntityLookup $lookup)
    {
        abort_unless($request->user()->can('manage iapm policies'), 403);
        $data = $request->validate([
            'port_id' => ['required', 'integer', 'exists:ports,port_id'],
            'state' => ['required', 'in:down,up'],
        ]);

        $port = Port::with('device')->findOrFail($data['port_id']);
        $down = $data['state'] === 'down';
        $payload = [
            // Stable for this interface so a later Up simulation correlates with
            // the Down simulation and recovers the same incident. The event
            // timestamp still makes distinct observations independently unique.
            'alert_uid' => 'iapm-sim-port-'.$port->port_id,
            'device_id' => (int) $port->device_id,
            'hostname' => (string) $port->device?->hostname,
            'state' => $down ? 1 : 0,
            'severity' => 'critical',
            'timestamp' => now()->toIso8601String(),
            'title' => 'IAPM simulated alert',
            'faults' => $down ? [[
                'port_id' => (int) $port->port_id,
                'ifName' => (string) $port->ifName,
                'ifDescr' => (string) $port->ifDescr,
                'ifAlias' => (string) $port->ifAlias,
                'ifAdminStatus' => 'up',
                'ifOperStatus' => 'down',
            ]] : [],
        ];

        try {
            $ingest = IngestAlertRequest::create('/plugin/interface-alert-policy-manager/api/v1/alerts', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($payload));
            $ingest->setContainer(app());
            $ingest->validateResolved();
            $response = app()->call([app(IngestionController::class), '__invoke'], ['request' => $ingest]);
            $result = method_exists($response, 'getData') ? $response->getData(true) : ['status' => 'ok'];
        } catch (\Throwable $e) {
            $result = ['error' => $e->getMessage()];
        }

        return view('iapm::simulate', ['result' => $result, 'port' => $port, 'portLabel' => $lookup->portLabel($port)]);
    }
}
