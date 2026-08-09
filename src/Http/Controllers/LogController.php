<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\AuditLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;

class LogController extends Controller
{
    public function deliveries(Request $r)
    {
        $q = DeliveryLog::latest()->when($r->filled('status'), fn ($q) => $q->where('status', $r->string('status')))->when($r->filled('phase'), fn ($q) => $q->where('phase', $r->string('phase')))->when($r->filled('incident_id'), fn ($q) => $q->where('incident_id', $r->integer('incident_id')))->when($r->filled('destination_id'), fn ($q) => $q->where('destination_id', $r->integer('destination_id')));

        return view('iapm::delivery-log', ['deliveries' => $q->paginate(100)->withQueryString()]);
    }

    public function audits(Request $r)
    {
        $q = AuditLog::latest('created_at')->when($r->filled('action'), fn ($q) => $q->where('action', 'like', '%'.$r->string('action').'%'))->when($r->filled('object_type'), fn ($q) => $q->where('object_type', $r->string('object_type')))->when($r->filled('user_id'), fn ($q) => $q->where('user_id', $r->integer('user_id')));

        return view('iapm::audit-log', ['audits' => $q->paginate(100)->withQueryString()]);
    }
}
