<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\Concerns\BulkDeletes;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Requests\AssignmentRequest;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AssignmentMatchCounter;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;

class AssignmentController extends Controller
{
    use BulkDeletes;

    public function index()
    {
        return redirect()->route('iapm.policies.index');
    }

    public function preview(AssignmentRequest $request, AssignmentMatchCounter $counter)
    {
        $data = $request->validated();
        $data['device_group_ids'] = $request->input('device_group_ids', []);

        return response()->json($counter->count($data));
    }

    public function deviceSearch(Request $r)
    {
        abort_unless($r->user()->can('manage iapm assignments'), 403);
        $q = trim((string) $r->query('q', ''));
        if ($q === '') {
            return response()->json([]);
        }$devices = Device::where('hostname', 'like', '%'.$q.'%')->orWhere('sysName', 'like', '%'.$q.'%')->orderBy('hostname')->limit(20)->get(['device_id', 'hostname', 'sysName']);

        return response()->json($devices->map(fn ($d) => ['id' => (int) $d->device_id, 'label' => $d->hostname.($d->sysName && $d->sysName !== $d->hostname ? ' ('.$d->sysName.')' : '')])->all());
    }

    public function bulkDestroy(Request $r, AuditService $audit)
    {
        abort_unless($r->user()->can('manage iapm assignments'), 403);
        $ids = $this->bulkIds($r);
        $deleted = 0;
        DB::transaction(function () use ($ids, &$deleted) {
            Assignment::whereIn('id', $ids)->get()->each(function ($a) use (&$deleted) {
                $a->delete();
                $deleted++;
            });
        });
        $audit->record($r, 'bulk_deleted', 'assignment', null, null, ['ids' => $ids, 'deleted' => $deleted]);

        $policyId = $r->integer('policy_id');

        return $policyId
            ? redirect()->to(route('iapm.policies.edit', ['policy' => $policyId]).'#assignments')->with('status', "Deleted {$deleted} assignment(s).")
            : redirect()->route('iapm.policies.index')->with('status', "Deleted {$deleted} assignment(s).");
    }

    public function create(Request $request)
    {
        if ($request->filled('policy_id')) {
            return redirect()->to(route('iapm.policies.edit', [
                'policy' => $request->integer('policy_id'),
                'assignment' => 'new',
            ]).'#assignments');
        }

        return redirect()->route('iapm.policies.index')->with('error', 'Choose a policy before adding an assignment.');
    }

    public function store(AssignmentRequest $r, AuditService $audit)
    {
        $a = DB::transaction(function () use ($r) {
            $d = $r->safe()->except(['device_group_ids', 'receivers']);
            $d['metadata_json'] = ['receivers' => $r->input('receivers', [])];
            $a = Assignment::create($d);
            foreach ($r->input('device_group_ids', []) as $id) {
                $a->deviceGroups()->create(['device_group_id' => $id, 'inclusion_mode' => $r->input('match_mode') === 'exclude' ? 'exclude' : 'include']);
            }

            return $a;
        });
        $audit->record($r, 'created', 'assignment', $a, null, $a->toArray());

        return redirect()->to(route('iapm.policies.edit', ['policy' => $a->policy_id]).'#assignments')->with('status', 'Assignment created.');
    }

    public function edit(Assignment $assignment)
    {
        return redirect()->to(route('iapm.policies.edit', [
            'policy' => $assignment->policy_id,
            'assignment' => $assignment->id,
        ]).'#assignments');
    }

    public function update(AssignmentRequest $r, Assignment $assignment, AuditService $audit)
    {
        $before = $assignment->toArray();
        DB::transaction(function () use ($r, $assignment) {
            $d = $r->safe()->except(['device_group_ids', 'receivers']);
            $d['metadata_json'] = ['receivers' => $r->input('receivers', [])];
            $assignment->update($d);
            $assignment->deviceGroups()->delete();
            foreach ($r->input('device_group_ids', []) as $id) {
                $assignment->deviceGroups()->create(['device_group_id' => $id, 'inclusion_mode' => $r->input('match_mode') === 'exclude' ? 'exclude' : 'include']);
            }
        });
        $audit->record($r, 'updated', 'assignment', $assignment, $before, $assignment->fresh()->toArray());

        return redirect()->to(route('iapm.policies.edit', [
            'policy' => $assignment->policy_id,
            'assignment' => $assignment->id,
        ]).'#assignments')->with('status', 'Assignment updated.');
    }

    public function destroy(Request $r, Assignment $assignment, AuditService $audit)
    {
        abort_unless($r->user()->can('manage iapm assignments'), 403);
        $before = $assignment->toArray();
        $assignment->delete();
        $audit->record($r, 'deleted', 'assignment', $assignment->id, $before, null);

        return redirect()->to(route('iapm.policies.edit', ['policy' => $assignment->policy_id]).'#assignments')->with('status', 'Assignment deleted.');
    }
}
