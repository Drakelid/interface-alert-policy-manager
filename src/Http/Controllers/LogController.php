<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\AuditLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;

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

        return view('iapm::delivery-log', ['deliveries' => $q->paginate(100)->withQueryString()]);
    }

    public function audits(Request $r)
    {
        $q = AuditLog::latest('created_at')->when($r->filled('action'), fn ($q) => $q->where('action', 'like', '%'.$r->string('action').'%'))->when($r->filled('object_type'), fn ($q) => $q->where('object_type', $r->string('object_type')))->when($r->filled('user_id'), fn ($q) => $q->where('user_id', $r->integer('user_id')));

        return view('iapm::audit-log', ['audits' => $q->paginate(100)->withQueryString()]);
    }
}
