<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\AuditLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;

class LogController extends Controller
{
    /**
     * The Overview's "Failed deliveries (24h)" tile counts both failure statuses
     * over a 24-hour window, so the log needs a matching grouped status and a
     * time window for the tile's link to land on the same rows (P0-3).
     */
    public const FAILED_STATUSES = ['failed', 'failed_configuration'];

    public function deliveries(Request $r)
    {
        $status = (string) $r->query('status', '');
        $q = DeliveryLog::latest()
            ->when($status === 'failed_any', fn ($q) => $q->whereIn('status', self::FAILED_STATUSES))
            ->when($status !== '' && $status !== 'failed_any', fn ($q) => $q->where('status', $status))
            ->when($r->filled('within'), fn ($q) => $q->where('created_at', '>=', now()->subHours($r->integer('within'))))
            ->when($r->filled('phase'), fn ($q) => $q->where('phase', $r->string('phase')))
            ->when($r->filled('incident_id'), fn ($q) => $q->where('incident_id', $r->integer('incident_id')))
            ->when($r->filled('destination_id'), fn ($q) => $q->where('destination_id', $r->integer('destination_id')));

        $deliveries = $q->paginate(100)->withQueryString();
        $incident = $r->filled('incident_id') ? Incident::find($r->integer('incident_id')) : null;

        return view('iapm::delivery-log', [
            'deliveries' => $deliveries,
            // P1-3: the table showed "Dest: 1". Resolve the names for the rows on
            // this page only, rather than eager-loading a relation per row.
            'destinationNames' => Destination::whereIn('id', $deliveries->pluck('destination_id')->filter()->unique())->pluck('name', 'id'),
            'destinations' => Destination::orderBy('name')->get(['id', 'name']),
            'incidentFilterLabel' => $incident ? $this->incidentLabel($incident) : '',
        ]);
    }

    public function audits(Request $r)
    {
        $q = AuditLog::latest('created_at')->when($r->filled('action'), fn ($q) => $q->where('action', 'like', '%'.$r->string('action').'%'))->when($r->filled('object_type'), fn ($q) => $q->where('object_type', $r->string('object_type')))->when($r->filled('user_id'), fn ($q) => $q->where('user_id', $r->integer('user_id')));
        $audits = $q->paginate(100)->withQueryString();
        $user = $r->filled('user_id') ? User::find($r->integer('user_id')) : null;

        return view('iapm::audit-log', [
            'audits' => $audits,
            // P1-3: the User column rendered a bare id, which defeats the purpose
            // of an audit log. Deleted accounts simply have no entry here and the
            // view falls back to the id.
            'userNames' => User::whereIn('user_id', $audits->pluck('user_id')->filter()->unique())->pluck('username', 'user_id'),
            'objectTypes' => AuditLog::OBJECT_TYPES,
            'userFilterLabel' => $user ? ($user->realname ? "$user->username ($user->realname)" : (string) $user->username) : '',
        ]);
    }

    private function incidentLabel(Incident $incident): string
    {
        $context = (array) $incident->context_json;

        return sprintf('#%d — %s / %s', $incident->id, $context['hostname'] ?? 'device '.$incident->device_id, $context['ifName'] ?? 'port '.$incident->port_id);
    }
}
