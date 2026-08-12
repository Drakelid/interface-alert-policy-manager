<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SafeTemplateRenderer;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\TemplateContextBuilder;

class PolicyActionController extends Controller
{
    public function create(Policy $policy)
    {
        return view('iapm::actions.form', ['policy' => $policy, 'action' => new PolicyAction, 'destinations' => Destination::where('enabled', true)->orderBy('name')->get()]);
    }

    public function store(Request $r, Policy $policy, AuditService $audit, SafeTemplateRenderer $renderer, TemplateContextBuilder $placeholders)
    {
        abort_unless($r->user()->can('manage iapm policies'), 403);
        $a = $policy->actions()->create($this->validated($r, $renderer, $placeholders));
        $audit->record($r, 'created', 'policy_action', $a, null, $a->toArray());

        return redirect()->route('iapm.policies.edit', $policy)->with('status', 'Action created.');
    }

    public function edit(PolicyAction $action)
    {
        return view('iapm::actions.form', ['policy' => $action->policy, 'action' => $action, 'destinations' => Destination::orderBy('name')->get()]);
    }

    public function update(Request $r, PolicyAction $action, AuditService $audit, SafeTemplateRenderer $renderer, TemplateContextBuilder $placeholders)
    {
        abort_unless($r->user()->can('manage iapm policies'), 403);
        $before = $action->toArray();
        $action->update($this->validated($r, $renderer, $placeholders));
        $audit->record($r, 'updated', 'policy_action', $action, $before, $action->toArray());

        return back()->with('status', 'Action updated.');
    }

    public function destroy(Request $r, PolicyAction $action, AuditService $audit)
    {
        abort_unless($r->user()->can('manage iapm policies'), 403);
        $policy = $action->policy_id;
        $action->delete();
        $audit->record($r, 'deleted', 'policy_action', $action->id);

        return redirect()->route('iapm.policies.edit', $policy);
    }

    private function validated(Request $r, SafeTemplateRenderer $renderer, TemplateContextBuilder $placeholders): array
    {
        $d = $r->validate(['destination_id' => ['required', 'exists:iapm_destinations,id'], 'phase' => ['required', Rule::in(['trigger', 'escalation', 'reminder', 'recovery', 'acknowledged'])], 'delay_seconds' => ['required', 'integer', 'min:0', 'max:2592000'], 'repeat_seconds' => ['nullable', 'integer', 'min:60'], 'maximum_sends' => ['nullable', 'integer', 'min:1', 'max:10000'], 'receivers_text' => ['nullable', 'string', 'max:10000'], 'message_template' => ['nullable', 'string', 'max:10000'], 'enabled' => ['nullable', 'boolean'], 'sort_order' => ['required', 'integer', 'min:0', 'max:10000']]);
        if (! empty($d['message_template'])) {
            try {
                $renderer->render($d['message_template'], $placeholders->sample());
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['message_template' => $e->getMessage()]);
            }
        }
        $d['enabled'] = $r->boolean('enabled');
        $d['receivers_json'] = preg_split('/[\r\n,]+/', trim((string) ($d['receivers_text'] ?? '')), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        unset($d['receivers_text']);

        return $d;
    }
}
