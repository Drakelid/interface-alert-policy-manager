<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\IncidentLifecycleService;

class IncidentBulkController
{
    public function __invoke(Request $request, AuditService $audit, IncidentLifecycleService $lifecycle)
    {
        $data = $request->validate(['incident_ids' => ['required', 'array', 'max:1000'], 'incident_ids.*' => ['integer', 'exists:iapm_incidents,id'], 'operation' => ['required', 'in:acknowledge,mute,unmute'], 'muted_until' => ['nullable', 'required_if:operation,mute', 'date', 'after:now']]);
        $ability = $data['operation'] === 'acknowledge' ? 'acknowledge iapm incidents' : 'mute iapm incidents';
        abort_unless($request->user()->can($ability), 403);
        DB::transaction(function () use ($request, $data, $lifecycle) {
            Incident::whereIn('id', $data['incident_ids'])->where('state', '!=', IncidentState::Recovered)->lockForUpdate()->get()->each(function ($incident) use ($request, $data, $lifecycle) {
                if ($data['operation'] === 'acknowledge') {
                    $lifecycle->acknowledge($incident, (int) $request->user()->getAuthIdentifier());
                } elseif ($data['operation'] === 'mute') {
                    $incident->update(['muted_until' => $data['muted_until']]);
                    $incident->events()->create(['event_type' => 'muted', 'event_message' => 'Bulk operation: mute', 'actor_user_id' => $request->user()->getAuthIdentifier()]);
                } else {
                    $incident->update(['muted_until' => null]);
                    $incident->events()->create(['event_type' => 'unmuted', 'event_message' => 'Bulk operation: unmute', 'actor_user_id' => $request->user()->getAuthIdentifier()]);
                }
            });
        });
        $audit->record($request, 'bulk_'.$data['operation'], 'incident', null, null, ['incident_ids' => $data['incident_ids'], 'muted_until' => $data['muted_until'] ?? null]);

        return back()->with('status', 'Bulk incident operation completed.');
    }
}
