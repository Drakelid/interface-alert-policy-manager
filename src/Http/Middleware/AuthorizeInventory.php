<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Simulation;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InventoryAccess;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeInventory
{
    public function __construct(private readonly InventoryAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->access->unrestricted()) {
            return $next($request);
        }
        // Shared configuration can change interfaces outside an itemized grant.
        // Operational pages below instead apply explicit SQL scopes.
        if (! $request->routeIs('iapm.policies.index', 'iapm.settings.edit', 'iapm.import.form') && $request->routeIs('iapm.policies.*', 'iapm.actions.*', 'iapm.assignments.*', 'iapm.import*', 'iapm.export', 'iapm.settings.*', 'iapm.audit-log*', 'iapm.lookup.users', 'iapm.message-templates.update', 'iapm.sms-content-filters.update', 'iapm.destinations.create', 'iapm.destinations.edit', 'iapm.destinations.store', 'iapm.destinations.update', 'iapm.destinations.destroy', 'iapm.destinations.bulk-destroy', 'iapm.destinations.clone', 'iapm.matrix.rebuild-cache', 'iapm.matrix.cache-status', 'iapm.devices.search')) {
            abort(403, 'This operation requires unrestricted LibreNMS device and port access.');
        }
        foreach (['port_id' => 'assertPort', 'device_id' => 'assertDevice'] as $field => $method) {
            if ($request->filled($field) && is_scalar($request->input($field)) && filter_var($request->input($field), FILTER_VALIDATE_INT) !== false) {
                $this->access->{$method}((int) $request->input($field));
            }
        }
        foreach (['port_ids' => 'assertPorts', 'incident_ids' => 'assertIncidents'] as $field => $method) {
            $ids = $request->input($field, []);
            // Match Laravel's integer validator: whitespace and a leading +
            // are valid integer strings. They must not disable the whole
            // batch's access check while ordinary IDs still reach a mutation.
            if (is_array($ids) && $ids !== [] && count($ids) <= 1000 && count(array_filter($ids, static fn ($id) => is_scalar($id) && filter_var($id, FILTER_VALIDATE_INT) !== false)) === count($ids)) {
                $this->access->{$method}(array_map('intval', $ids));
            }
        }
        $incident = $request->route('incident');
        if ($incident instanceof Incident || is_numeric($incident)) {
            $this->access->assertIncident((int) ($incident instanceof Incident ? $incident->id : $incident));
        }
        $simulation = $request->route('simulation');
        if ($simulation !== null) {
            $simulation = $simulation instanceof Simulation ? $simulation : Simulation::findOrFail($simulation);
            $this->access->assertPort((int) $simulation->port_id);
        }

        return $next($request);
    }
}
