<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\IncidentEvent;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;

class ComparisonReportController extends Controller
{
    public function __invoke(Request $request)
    {
        $days = max(1, min(90, $request->integer('days', 7)));

        return view('iapm::comparison-report', $this->data($days) + ['days' => $days]);
    }

    public function export(Request $request)
    {
        $days = max(1, min(90, $request->integer('days', 7)));
        $data = $this->data($days);

        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['metric', 'value']);
            foreach ($data['metrics'] as $key => $value) {
                fputcsv($out, [$key, $value]);
            }
            fputcsv($out, []);
            fputcsv($out, ['policy', 'incidents', 'would_send', 'sent', 'suppressed']);
            foreach ($data['byPolicy'] as $row) {
                fputcsv($out, [$row['policy'], $row['incidents'], $row['would_send'], $row['sent'], $row['suppressed']]);
            }
            fclose($out);
        }, 'iapm-comparison-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function data(int $days): array
    {
        $since = now()->subDays($days);

        $metrics = [
            'alerts_received' => IncidentEvent::where('event_type', 'received')->where('created_at', '>=', $since)->count(),
            'incidents_generated' => Incident::where('created_at', '>=', $since)->count(),
            'would_send' => DeliveryLog::where('status', 'dry_run')->where('created_at', '>=', $since)->count(),
            'sent' => DeliveryLog::where('status', 'sent')->where('created_at', '>=', $since)->count(),
            'suppressed' => IncidentEvent::where('event_type', 'suppressed')->where('created_at', '>=', $since)->count(),
            'missing_receivers' => IncidentEvent::where('event_type', 'notification_failed')->where('event_message', 'like', 'No notification receiver%')->where('created_at', '>=', $since)->count(),
            'missing_policies' => Incident::where('suppression_reason', 'no_policy')->where('updated_at', '>=', $since)->count(),
            'processing_errors' => DeliveryLog::whereIn('status', ['failed', 'failed_configuration'])->where('created_at', '>=', $since)->count(),
        ];

        $policyNames = Policy::pluck('name', 'id');
        $incidentCounts = Incident::where('created_at', '>=', $since)->selectRaw('policy_id, count(*) c')->groupBy('policy_id')->pluck('c', 'policy_id');
        $wouldByPolicy = DeliveryLog::where('iapm_delivery_logs.status', 'dry_run')->where('iapm_delivery_logs.created_at', '>=', $since)->join('iapm_incidents', 'iapm_incidents.id', '=', 'iapm_delivery_logs.incident_id')->selectRaw('iapm_incidents.policy_id, count(*) c')->groupBy('iapm_incidents.policy_id')->pluck('c', 'policy_id');
        $sentByPolicy = DeliveryLog::where('iapm_delivery_logs.status', 'sent')->where('iapm_delivery_logs.created_at', '>=', $since)->join('iapm_incidents', 'iapm_incidents.id', '=', 'iapm_delivery_logs.incident_id')->selectRaw('iapm_incidents.policy_id, count(*) c')->groupBy('iapm_incidents.policy_id')->pluck('c', 'policy_id');
        $suppressedByPolicy = Incident::where('updated_at', '>=', $since)->whereNotNull('suppression_reason')->selectRaw('policy_id, count(*) c')->groupBy('policy_id')->pluck('c', 'policy_id');

        $byPolicy = collect($incidentCounts->keys())
            ->merge($wouldByPolicy->keys())->merge($sentByPolicy->keys())->merge($suppressedByPolicy->keys())
            ->unique()->map(fn ($id) => [
                'policy' => $id ? ($policyNames[$id] ?? "#$id") : 'No policy',
                'incidents' => (int) ($incidentCounts[$id] ?? 0),
                'would_send' => (int) ($wouldByPolicy[$id] ?? 0),
                'sent' => (int) ($sentByPolicy[$id] ?? 0),
                'suppressed' => (int) ($suppressedByPolicy[$id] ?? 0),
            ])->sortByDesc('incidents')->values()->all();

        return ['metrics' => $metrics, 'byPolicy' => $byPolicy];
    }
}
