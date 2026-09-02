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
        //
        // The resolved list can end at the destination's `default_receiver`, which lives
        // in the destination's encrypted configuration and is deliberately withheld from
        // view-only users on the destination form. This page is reachable with only
        // `view iapm` (the Interface Matrix links every row to it), so the receivers are
        // shown to the roles that can already read them from a configuration form and
        // masked for everyone else. The policy verdict itself stays visible to all.
        $canSeeReceivers = (bool) ($r->user()?->can('manage iapm destinations') || $r->user()?->can('manage iapm policies'));
        $delivery = [];
        if ($resolution && $resolution->policy) {
            $policy = $resolution->policy;
            foreach ($policy->actions()->where('enabled', true)->with('destination')->orderBy('sort_order')->get() as $action) {
                $resolved = $receivers->forAction($action, $resolution);
                $delivery[] = [
                    'phase' => $action->phase->value,
                    'destination' => $action->destination?->name,
                    // Whether a receiver resolves at all is the operational answer and
                    // stays visible; only the values themselves are privileged.
                    'has_receivers' => $resolved !== [],
                    'receivers' => $canSeeReceivers ? $resolved : [],
                ];
            }
        }

        // Label for the interface picker, so returning to the page shows the name
        // the operator chose rather than an empty box beside a bare number (P1-2).
        $portLabel = $port ? $lookup->portLabel($port) : '';

        return view('iapm::policy-test', compact('port', 'resolution', 'delivery', 'portLabel', 'canSeeReceivers'));
    }
}
