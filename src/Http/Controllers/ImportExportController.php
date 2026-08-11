<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ConfigurationImportPlanner;
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
        return view('iapm::import', $this->importView());
    }

    /**
     * P1-8: import was paste-only, create-only and had no preview — existing
     * items matched by name were silently skipped, which makes the page's stated
     * purpose (promotion between installs) unachievable after the first run.
     *
     * The form now submits either `preview` or `apply`. Both build the same plan;
     * apply simply executes it, so what the operator approved is what happens.
     */
    public function import(Request $request, AuditService $audit, ConfigurationImportValidator $documents, ConfigurationImportPlanner $planner)
    {
        abort_unless($request->user()->can('manage iapm policies'), 403);
        $request->validate([
            'document' => ['nullable', 'string', 'max:5000000'],
            'file' => ['nullable', 'file', 'max:5120', 'mimetypes:application/json,text/plain'],
            'update_existing' => ['nullable', 'boolean'],
        ]);

        $source = $this->documentText($request);
        if ($source === null) {
            return back()->withErrors('Paste the exported JSON or choose a file to upload.');
        }

        try {
            $doc = json_decode($source, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return back()->withErrors('That content is not valid JSON.')->withInput();
        }
        if (! is_array($doc) || ($doc['version'] ?? null) !== 1) {
            return back()->withErrors('Unrecognised export format (expected version 1).')->withInput();
        }

        $updateExisting = $request->boolean('update_existing');
        $apply = $request->input('action') === 'apply';

        try {
            // Validation runs for the preview too, so problems surface before the
            // operator commits rather than after they press the destructive button.
            $doc = $documents->validate($doc, $updateExisting);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->validator)->withInput();
        }

        if (! $apply) {
            return view('iapm::import', $this->importView(
                plan: $planner->plan($doc, $updateExisting),
                document: $source,
                updateExisting: $updateExisting,
            ));
        }

        // One snapshot: if any write fails the whole document is rolled back.
        $report = DB::transaction(fn () => $planner->apply($doc, $updateExisting, $request->user()?->getAuthIdentifier()));
        $audit->record($request, 'imported', 'configuration', null, null, ['counts' => $report['counts'], 'update_existing' => $updateExisting]);

        return view('iapm::import', $this->importView(report: $report, updateExisting: $updateExisting));
    }

    /**
     * An uploaded file wins over the textarea, so choosing a file after pasting
     * does the obvious thing rather than silently importing the stale paste.
     */
    private function documentText(Request $request): ?string
    {
        if ($request->hasFile('file')) {
            $contents = file_get_contents($request->file('file')->getRealPath());

            return $contents === false ? null : $contents;
        }
        $pasted = trim((string) $request->input('document', ''));

        return $pasted === '' ? null : $pasted;
    }

    /** @return array<string, mixed> */
    private function importView(?array $plan = null, ?array $report = null, string $document = '', bool $updateExisting = false): array
    {
        return ['plan' => $plan, 'report' => $report, 'document' => $document, 'updateExisting' => $updateExisting];
    }

    private function strip(array $row): array
    {
        return collect($row)->except(self::EXCLUDED)->all();
    }
}
