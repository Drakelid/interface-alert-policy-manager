<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ConfigurationImportValidator;

/**
 * Export/import of the alerting configuration (schedules, policies, actions,
 * assignments) as JSON — for backup and staging->production promotion.
 *
 * Destinations are NOT exported (they hold environment-specific encrypted
 * secrets); actions reference their destination by name and are resolved on
 * import, skipping any whose destination is missing.
 */
class ImportExportController extends Controller
{
    private const EXCLUDED = ['id', 'created_at', 'updated_at', 'created_by', 'updated_by', 'business_schedule_id', 'policy_id', 'destination_id'];

    public function export(Request $request)
    {
        abort_unless($request->user()->can('manage iapm policies'), 403);

        $schedules = Schedule::all()->map(fn ($s) => $this->strip($s->toArray()))->values();

        $policies = Policy::with(['schedule', 'actions.destination', 'assignments.deviceGroups'])->get()->map(function (Policy $p) {
            $row = $this->strip($p->toArray());
            $row['schedule'] = $p->schedule?->name;
            $row['actions'] = $p->actions->map(function ($a) {
                $action = $this->strip($a->toArray());
                $action['destination'] = $a->destination?->name;

                return $action;
            })->values();
            $row['assignments'] = $p->assignments->map(function (Assignment $a) {
                $assignment = $this->strip($a->toArray());
                $assignment['device_group_ids'] = $a->deviceGroups->pluck('device_group_id')->all();

                return $assignment;
            })->values();

            return $row;
        })->values();

        $document = ['version' => 1, 'exported_at' => now()->toIso8601String(), 'schedules' => $schedules, 'policies' => $policies];

        return response()->streamDownload(
            fn () => print (json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
            'iapm-config-'.now()->format('Ymd-His').'.json',
            ['Content-Type' => 'application/json']
        );
    }

    public function importForm()
    {
        return view('iapm::import', ['report' => null]);
    }

    public function import(Request $request, AuditService $audit, ConfigurationImportValidator $documents)
    {
        abort_unless($request->user()->can('manage iapm policies'), 403);
        $data = $request->validate(['document' => ['required', 'string', 'max:5000000']]);

        try {
            $doc = json_decode($data['document'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return back()->withErrors('The pasted content is not valid JSON.');
        }
        if (! is_array($doc) || ($doc['version'] ?? null) !== 1) {
            return back()->withErrors('Unrecognised export format (expected version 1).');
        }
        $report = ['schedules' => 0, 'policies' => 0, 'actions' => 0, 'assignments' => 0, 'skipped' => []];
        DB::transaction(function () use ($doc, &$report, $request, $documents, $audit): void {
            // Validate references and write from one database snapshot. If any
            // validation or write fails, the entire document is rolled back.
            $doc = $documents->validate($doc);
            $destinationNames = collect($doc['policies'] ?? [])->flatMap(fn (array $policy) => collect($policy['actions'] ?? [])->pluck('destination'))->filter()->unique();
            $destinations = Destination::whereIn('name', $destinationNames)->pluck('id', 'name');
            foreach ((array) ($doc['schedules'] ?? []) as $s) {
                if (empty($s['name']) || Schedule::where('name', $s['name'])->exists()) {
                    continue;
                }
                Schedule::create($this->only($s, (new Schedule)->getFillable()));
                $report['schedules']++;
            }

            foreach ((array) ($doc['policies'] ?? []) as $p) {
                if (empty($p['name']) || Policy::where('name', $p['name'])->exists()) {
                    $report['skipped'][] = 'policy "'.($p['name'] ?? '?').'" (already exists)';

                    continue;
                }
                $policy = Policy::create($this->only($p, (new Policy)->getFillable()) + [
                    'business_schedule_id' => isset($p['schedule']) ? Schedule::where('name', $p['schedule'])->value('id') : null,
                    'created_by' => $request->user()->getAuthIdentifier(),
                    'updated_by' => $request->user()->getAuthIdentifier(),
                ]);
                $report['policies']++;

                foreach ((array) ($p['actions'] ?? []) as $a) {
                    $destId = $destinations[$a['destination'] ?? ''] ?? null;
                    if (! $destId) {
                        throw new \LogicException('Validated destination disappeared during import.');
                    }
                    $policy->actions()->create($this->only($a, (new PolicyAction)->getFillable()) + ['destination_id' => $destId]);
                    $report['actions']++;
                }

                foreach ((array) ($p['assignments'] ?? []) as $a) {
                    $assignment = $policy->assignments()->create($this->only($a, (new Assignment)->getFillable()));
                    foreach ((array) ($a['device_group_ids'] ?? []) as $gid) {
                        $assignment->deviceGroups()->create(['device_group_id' => (int) $gid, 'inclusion_mode' => ($a['match_mode'] ?? 'any') === 'exclude' ? 'exclude' : 'include']);
                    }
                    $report['assignments']++;
                }
            }
            $audit->record($request, 'imported', 'configuration', null, null, $report);
        });

        return view('iapm::import', ['report' => $report]);
    }

    private function strip(array $row): array
    {
        return collect($row)->except(self::EXCLUDED)->all();
    }

    private function only(array $row, array $fillable): array
    {
        return collect($row)->only($fillable)->except(['created_by', 'updated_by'])->all();
    }
}
