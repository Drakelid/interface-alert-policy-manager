<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Rules\PortHasDevice;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\EntityLookup;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ReceiverResolver;

class PolicyTestController extends Controller
{
    public function __invoke(Request $r, InterfaceContextService $contexts, PolicyResolver $resolver, ReceiverResolver $receivers, EntityLookup $lookup)
    {
        $r->validate(['port_id' => ['nullable', 'integer', new PortHasDevice]]);
        $port = $r->filled('port_id') ? Port::with(['device.location', 'device.groups', 'groups'])->find($r->integer('port_id')) : null;
        $resolution = $port ? $resolver->resolve($contexts->forPort($port)) : null;

        // Show the receivers each enabled action would actually resolve to, using the
        // same precedence the delivery path applies (action > port assignment > device
        // group > policy default > destination config > global). This answers the most
        // common question — "who would this page?" — before an outage.
        $delivery = [];
        if ($resolution && $resolution->policy) {
            $policy = $resolution->policy;
            foreach ($policy->actions()->where('enabled', true)->with('destination')->orderBy('sort_order')->get() as $action) {
                $delivery[] = [
                    'phase' => $action->phase->value,
                    'destination' => $action->destination?->name,
                    'receivers' => $receivers->forAction($action, $resolution),
                ];
            }
        }

        // Label for the interface picker, so returning to the page shows the name
        // the operator chose rather than an empty box beside a bare number (P1-2).
        $portLabel = $port ? $lookup->portLabel($port) : '';

        return view('iapm::policy-test', compact('port', 'resolution', 'delivery', 'portLabel'));
    }
}
