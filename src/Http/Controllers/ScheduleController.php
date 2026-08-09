<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\Concerns\BulkDeletes;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ScheduleEvaluator;

class ScheduleController extends Controller
{
    use BulkDeletes;

    public function index()
    {
        return view('iapm::schedules.index', ['schedules' => Schedule::withCount('policies')->orderBy('name')->paginate(50)]);
    }

    public function bulkDestroy(Request $r, AuditService $a)
    {
        abort_unless($r->user()->can('manage iapm policies'), 403);
        $ids = $this->bulkIds($r);
        $deleted = 0;
        $skipped = [];
        DB::transaction(function () use ($ids, &$deleted, &$skipped) {
            foreach (Schedule::whereIn('id', $ids)->withCount('policies')->get() as $schedule) {
                if ($schedule->policies_count > 0) {
                    $skipped[] = $schedule->name;

                    continue;
                }$schedule->delete();
                $deleted++;
            }
        });
        $a->record($r, 'bulk_deleted', 'schedule', null, null, ['deleted' => $deleted, 'skipped' => $skipped]);
        $msg = "Deleted {$deleted} schedule(s).";
        if ($skipped) {
            $msg .= ' Skipped (used by policies): '.implode(', ', $skipped).'.';
        }

        return redirect()->route('iapm.schedules.index')->with($skipped ? 'error' : 'status', $msg);
    }

    public function create()
    {
        return view('iapm::schedules.form', ['schedule' => new Schedule, 'definition' => ['mode' => 'always', 'days' => []]]);
    }

    public function store(Request $r, ScheduleEvaluator $e, AuditService $a)
    {
        abort_unless($r->user()->can('manage iapm policies'), 403);
        $d = $this->validated($r, $e);
        $s = Schedule::create($d);
        $a->record($r, 'created', 'schedule', $s, null, $s->toArray());

        return redirect()->route('iapm.schedules.edit', $s);
    }

    public function edit(Schedule $schedule)
    {
        return view('iapm::schedules.form', ['schedule' => $schedule, 'definition' => $schedule->schedule_json]);
    }

    public function update(Request $r, Schedule $schedule, ScheduleEvaluator $e, AuditService $a)
    {
        abort_unless($r->user()->can('manage iapm policies'), 403);
        $before = $schedule->toArray();
        $schedule->update($this->validated($r, $e));
        $a->record($r, 'updated', 'schedule', $schedule, $before, $schedule->toArray());

        return back()->with('status', 'Schedule updated.');
    }

    public function destroy(Request $r, Schedule $schedule, AuditService $a)
    {
        abort_unless($r->user()->can('manage iapm policies'), 403);
        if ($schedule->policies()->exists()) {
            return back()->withErrors('Schedule is used by policies.');
        }$schedule->delete();
        $a->record($r, 'deleted', 'schedule', $schedule->id);

        return redirect()->route('iapm.schedules.index');
    }

    private function validated(Request $r, ScheduleEvaluator $e): array
    {
        $d = $r->validate(['name' => ['required', 'string', 'max:255'], 'timezone' => ['required', 'timezone:all'], 'enabled' => ['nullable', 'boolean'], 'schedule_json' => ['required', 'json', 'max:50000']]);
        $d['enabled'] = $r->boolean('enabled');
        $d['schedule_json'] = $e->validate(json_decode($d['schedule_json'], true, 32, JSON_THROW_ON_ERROR));

        return $d;
    }
}
