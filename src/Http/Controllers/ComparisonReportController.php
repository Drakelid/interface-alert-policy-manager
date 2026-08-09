<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\IncidentEvent;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Outage;
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
            fputcsv($out, ['policy', 'outage_episodes', 'would_send', 'sent', 'suppressed']);
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
            'outage_episodes' => Outage::where('recovered_at', '>=', $since)->count(),
            'would_send' => NotificationOutbox::where('status', 'dry_run')->where('created_at', '>=', $since)->count(),
            'sent' => NotificationOutbox::where('status', 'sent')->where('created_at', '>=', $since)->count(),
            'transport_attempts' => DeliveryLog::whereNotNull('notification_outbox_id')->whereIn('status', ['sent', 'failed'])->where('created_at', '>=', $since)->count(),
            'suppressed' => Outage::whereNotNull('suppression_reason')->where('recovered_at', '>=', $since)->count(),
            'missing_receivers' => DeliveryLog::where('status', 'failed_configuration')->where('error_message', 'like', '%No notification receiver%')->where('created_at', '>=', $since)->count(),
            'missing_policies' => Outage::where('suppression_reason', 'no_policy')->where('recovered_at', '>=', $since)->count(),
            'processing_errors' => DeliveryLog::whereIn('status', ['failed', 'failed_configuration'])->where('created_at', '>=', $since)->count(),
        ];

        $policyNames = Policy::pluck('name', 'id');
        $incidentCounts = Outage::where('recovered_at', '>=', $since)->selectRaw('policy_id, count(*) c')->groupBy('policy_id')->pluck('c', 'policy_id');
        $wouldByPolicy = NotificationOutbox::where('iapm_notification_outbox.status', 'dry_run')->where('iapm_notification_outbox.created_at', '>=', $since)->join('iapm_outages', function ($join): void {
            $join->on('iapm_outages.incident_id', '=', 'iapm_notification_outbox.incident_id')->on('iapm_outages.episode_uuid', '=', 'iapm_notification_outbox.episode_uuid');
        })->selectRaw('iapm_outages.policy_id, count(*) c')->groupBy('iapm_outages.policy_id')->pluck('c', 'policy_id');
        $sentByPolicy = NotificationOutbox::where('iapm_notification_outbox.status', 'sent')->where('iapm_notification_outbox.created_at', '>=', $since)->join('iapm_outages', function ($join): void {
            $join->on('iapm_outages.incident_id', '=', 'iapm_notification_outbox.incident_id')->on('iapm_outages.episode_uuid', '=', 'iapm_notification_outbox.episode_uuid');
        })->selectRaw('iapm_outages.policy_id, count(*) c')->groupBy('iapm_outages.policy_id')->pluck('c', 'policy_id');
        $suppressedByPolicy = Outage::where('recovered_at', '>=', $since)->whereNotNull('suppression_reason')->selectRaw('policy_id, count(*) c')->groupBy('policy_id')->pluck('c', 'policy_id');

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
