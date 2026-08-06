<?php
namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;
use App\Models\Port; use Illuminate\Http\Request; use Illuminate\Routing\Controller; use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\AssignmentType; use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService; use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver; use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ReceiverResolver; use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
class PolicyTestController extends Controller {
    public function __invoke(Request $r, InterfaceContextService $contexts, PolicyResolver $resolver, ReceiverResolver $receivers, SettingStore $settings) {
        $r->validate(['port_id' => ['nullable', 'integer', 'exists:ports,port_id']]);
        $port = $r->filled('port_id') ? Port::with(['device.location', 'device.groups', 'groups'])->find($r->integer('port_id')) : null;
        $resolution = $port ? $resolver->resolve($contexts->forPort($port)) : null;

        // Show the receivers each enabled action would actually resolve to, using the
        // same precedence the delivery path applies (action > port assignment > device
        // group > policy default > destination config > global). This answers the most
        // common question — "who would this page?" — before an outage.
        $delivery = [];
        if ($resolution && $resolution->policy) {
            $policy = $resolution->policy;
            $portReceivers = collect($resolution->candidates)->first(fn ($c) => $c->assignment_type === AssignmentType::Port)?->metadata_json['receivers'] ?? [];
            $groupReceivers = collect($resolution->candidates)->where('assignment_type', AssignmentType::DeviceGroup)->flatMap(fn ($c) => $c->metadata_json['receivers'] ?? [])->unique()->values()->all();
            $global = [(string) $settings->get('sms_default_receiver', config('iapm.sms.default_receiver'))];
            foreach ($policy->actions()->where('enabled', true)->with('destination')->orderBy('sort_order')->get() as $action) {
                $config = (array) ($action->destination->configuration_encrypted ?? []);
                $delivery[] = [
                    'phase' => $action->phase->value,
                    'destination' => $action->destination?->name,
                    'receivers' => $receivers->resolve((array) $action->receivers_json, (array) $portReceivers, (array) $groupReceivers, [(string) ($policy->default_receiver ?? '')], (array) ($config['receivers'] ?? []), [(string) ($config['default_receiver'] ?? '')], $global),
                ];
            }
        }

        return view('iapm::policy-test', compact('port', 'resolution', 'delivery'));
    }
}
