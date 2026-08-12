<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\Concerns\BulkDeletes;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Requests\PolicyRequest;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AssignmentFormData;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;

class PolicyController extends Controller
{
    use BulkDeletes;

    public function index()
    {
        return view('iapm::policies.index', ['policies' => Policy::withCount(['assignments', 'actions', 'actions as enabled_actions_count' => fn ($q) => $q->where('enabled', true)])->orderByDesc('priority')->paginate(50)]);
    }

    public function bulkDestroy(Request $r, AuditService $audit)
    {
        abort_unless($r->user()->can('manage iapm policies'), 403);
        $ids = $this->bulkIds($r);
        $deleted = 0;
        $skipped = [];
        DB::transaction(function () use ($ids, &$deleted, &$skipped) {
            foreach (Policy::whereIn('id', $ids)->withCount(['assignments'])->get() as $policy) {
                if ($policy->assignments_count > 0 || $policy->incidents()->where('state', '!=', 'recovered')->exists()) {
                    $skipped[] = $policy->name;

                    continue;
                }$policy->delete();
                $deleted++;
            }
        });
        $audit->record($r, 'bulk_deleted', 'policy', null, null, ['deleted' => $deleted, 'skipped' => $skipped]);
        $msg = "Deleted {$deleted} policy(ies).";
        if ($skipped) {
            $msg .= ' Skipped (still referenced by assignments or active incidents): '.implode(', ', $skipped).'.';
        }

        return redirect()->route('iapm.policies.index')->with($skipped ? 'error' : 'status', $msg);
    }

    public function create()
    {
        return view('iapm::policies.form', ['policy' => new Policy]);
    }

    public function store(PolicyRequest $r, AuditService $audit)
    {
        $p = Policy::create($r->validated() + ['created_by' => $r->user()->getAuthIdentifier(), 'updated_by' => $r->user()->getAuthIdentifier()]);
        $audit->record($r, 'created', 'policy', $p, null, $p->toArray());

        return redirect()->route('iapm.policies.edit', $p)->with('status', 'Policy created.');
    }

    public function edit(Request $request, Policy $policy, AssignmentFormData $assignmentForms)
    {
        $assignment = null;
        if ($request->query('assignment') === 'new') {
            $assignment = new Assignment(['policy_id' => $policy->id]);
            $assignment->setRelation('deviceGroups', collect());
        } elseif ($request->filled('assignment')) {
            $assignment = $policy->assignments()->with('deviceGroups')->findOrFail($request->integer('assignment'));
        }

        return view('iapm::policies.form', [
            'policy' => $policy->load('assignments.deviceGroups'),
            'otherPolicies' => Policy::whereKeyNot($policy->id)->orderBy('name')->get(),
            'openIncidentCount' => $policy->incidents()->where('state', '!=', 'recovered')->count(),
            'assignmentEditor' => $assignment,
            'assignmentFormData' => $assignment ? $assignmentForms->for($assignment) : [],
        ]);
    }

    public function update(PolicyRequest $r, Policy $policy, AuditService $audit)
    {
        $before = $policy->toArray();
        $policy->update($r->validated() + ['updated_by' => $r->user()->getAuthIdentifier()]);
        $audit->record($r, 'updated', 'policy', $policy, $before, $policy->fresh()->toArray());

        return back()->with('status', 'Policy updated.');
    }

    public function destroy(Request $r, Policy $policy, AuditService $audit)
    {
        abort_unless($r->user()->can('manage iapm policies'), 403);
        $data = $r->validate(['migrate_to' => ['nullable', Rule::notIn([$policy->id]), 'exists:iapm_policies,id']]);
        $openIncidents = $policy->incidents()->where('state', '!=', 'recovered');
        if ($openIncidents->exists()) {
            if (empty($data['migrate_to'])) {
                return back()->withErrors('Policy has active incidents. Choose another policy to migrate them to before deleting.');
            }DB::transaction(function () use ($openIncidents, $data, $r) {
                foreach ($openIncidents->get() as $incident) {
                    $incident->update(['policy_id' => $data['migrate_to']]);
                    $incident->events()->create(['event_type' => 'policy_changed', 'event_message' => 'Incident migrated to another policy before its policy was deleted.', 'event_data' => ['to_policy_id' => (int) $data['migrate_to']], 'actor_user_id' => $r->user()->getAuthIdentifier()]);
                }
            });
        }$before = $policy->toArray();
        $policy->delete();
        $audit->record($r, 'deleted', 'policy', $policy->id, $before, ['migrated_incidents_to' => $data['migrate_to'] ?? null]);

        return redirect()->route('iapm.policies.index')->with('status', 'Policy deleted.');
    }

    public function clone(Request $r, Policy $policy, AuditService $audit)
    {
        abort_unless($r->user()->can('manage iapm policies'), 403);
        $copy = $policy->replicate();
        $copy->business_schedule_id = null;
        $copy->name = $policy->name.' (copy)';
        $copy->enabled = false;
        $copy->created_by = $r->user()->getAuthIdentifier();
        $copy->updated_by = $copy->created_by;
        $copy->save();
        foreach ($policy->actions as $a) {
            $new = $a->replicate();
            $new->policy_id = $copy->id;
            $new->save();
        }$audit->record($r, 'cloned', 'policy', $copy, null, $copy->toArray());

        return redirect()->route('iapm.policies.edit', $copy);
    }
}
