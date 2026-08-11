<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\Concerns\BulkDeletes;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ScheduleEvaluator;

class ScheduleController extends Controller
{
    use BulkDeletes;

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const MODES = ['always', 'business_hours', 'after_hours', 'custom'];

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
        return view('iapm::schedules.form', $this->formData(new Schedule, ['mode' => 'always', 'days' => []]));
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
        return view('iapm::schedules.form', $this->formData($schedule, (array) $schedule->schedule_json));
    }

    /** @return array<string, mixed> */
    private function formData(Schedule $schedule, array $definition): array
    {
        $evaluator = app(ScheduleEvaluator::class);
        $timezone = $schedule->timezone ?: (string) config('app.timezone');

        return [
            'schedule' => $schedule,
            'definition' => $definition + ['mode' => 'always', 'days' => []],
            'days' => self::DAYS,
            'modes' => self::MODES,
            'timezones' => \DateTimeZone::listIdentifiers(),
            // P1-5: "is this schedule open right now?" was impossible to answer
            // from the form, which is a large part of why the step gets skipped.
            'inWindow' => $schedule->exists ? $evaluator->permits($schedule) : null,
            'localTime' => CarbonImmutable::now($timezone)->format('D H:i'),
        ];
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
        $d = $r->validate(['name' => ['required', 'string', 'max:255'], 'timezone' => ['required', 'timezone:all'], 'enabled' => ['nullable', 'boolean']]);
        $d['enabled'] = $r->boolean('enabled');
        $d['schedule_json'] = $this->definition($r, $e);

        return $d;
    }

    /**
     * P1-5: the form used to require hand-written JSON. The structured editor is
     * now the real form contract — the weekday rows post as `days[mon][0][start]`
     * — so it works and is testable without JavaScript. The raw JSON textarea
     * remains as an advanced escape hatch and wins only when explicitly enabled.
     */
    private function definition(Request $r, ScheduleEvaluator $e): array
    {
        // The JSON path also handles a request that carries no `mode` at all, so
        // the previous contract — post `schedule_json` alone — keeps working.
        if ($r->boolean('advanced_json') || ! $r->has('mode')) {
            $r->validate(['schedule_json' => ['required', 'json', 'max:50000']]);

            return $e->validate(json_decode((string) $r->string('schedule_json'), true, 32, JSON_THROW_ON_ERROR));
        }

        $r->validate([
            'mode' => ['required', Rule::in(self::MODES)],
            'days' => ['nullable', 'array'],
            'days.*' => ['array', 'max:12'],
            // Both ends are optional at this stage so a row the operator added
            // and left blank is discarded rather than blocking the save; a
            // half-filled row is still rejected below.
            'days.*.*.start' => ['nullable', 'date_format:H:i'],
            'days.*.*.end' => ['nullable', 'date_format:H:i'],
        ]);

        $days = [];
        foreach ((array) $r->input('days', []) as $day => $periods) {
            $day = strtolower((string) $day);
            if (! in_array($day, self::DAYS, true) || ! is_array($periods)) {
                continue;
            }
            foreach ($periods as $period) {
                $start = trim((string) ($period['start'] ?? ''));
                $end = trim((string) ($period['end'] ?? ''));
                if ($start === '' && $end === '') {
                    continue;
                }
                if ($start === '' || $end === '') {
                    throw ValidationException::withMessages([
                        'days' => ucfirst($day).': a time range needs both a start and an end.',
                    ]);
                }
                $days[$day][] = ['start' => $start, 'end' => $end];
            }
        }

        return $e->validate(['mode' => (string) $r->string('mode'), 'days' => $days]);
    }
}
