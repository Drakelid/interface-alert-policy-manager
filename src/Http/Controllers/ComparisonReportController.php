<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\IncidentEvent;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Outage;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InventoryAccess;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Support\SafeCsv;

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
            SafeCsv::write($out, ['metric', 'value']);
            foreach ($data['metrics'] as $key => $value) {
                SafeCsv::write($out, [$key, $value]);
            }
            SafeCsv::write($out, []);
            SafeCsv::write($out, ['policy', 'outage_episodes', 'would_send', 'sent', 'suppressed']);
            foreach ($data['byPolicy'] as $row) {
                SafeCsv::write($out, [$row['policy'], $row['incidents'], $row['would_send'], $row['sent'], $row['suppressed']]);
            }
            fclose($out);
        }, 'iapm-comparison-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function data(int $days): array
    {
        $since = now()->subDays($days);

        $metrics = [
            'alerts_received' => app(InventoryAccess::class)->throughIncident(IncidentEvent::query())->where('event_type', 'received')->where('created_at', '>=', $since)->count(),
            'outage_episodes' => app(InventoryAccess::class)->records(Outage::query())->where('recovered_at', '>=', $since)->count(),
            'would_send' => app(InventoryAccess::class)->throughIncident(NotificationOutbox::query())->where('status', 'dry_run')->where('created_at', '>=', $since)->count(),
            'sent' => app(InventoryAccess::class)->throughIncident(NotificationOutbox::query())->where('status', 'sent')->where('created_at', '>=', $since)->count(),
            'transport_attempts' => app(InventoryAccess::class)->throughIncident(DeliveryLog::query())->whereNotNull('notification_outbox_id')->whereIn('status', ['sent', 'failed'])->where('created_at', '>=', $since)->count(),
            'suppressed' => app(InventoryAccess::class)->records(Outage::query())->whereNotNull('suppression_reason')->where('recovered_at', '>=', $since)->count(),
            'missing_receivers' => app(InventoryAccess::class)->throughIncident(DeliveryLog::query())->where('status', 'failed_configuration')->where('error_message', 'like', '%No notification receiver%')->where('created_at', '>=', $since)->count(),
            'missing_policies' => app(InventoryAccess::class)->records(Outage::query())->where('suppression_reason', 'no_policy')->where('recovered_at', '>=', $since)->count(),
            'processing_errors' => app(InventoryAccess::class)->throughIncident(DeliveryLog::query())->whereIn('status', ['failed', 'failed_configuration'])->where('created_at', '>=', $since)->count(),
        ];

        $policyNames = Policy::pluck('name', 'id');
        $incidentCounts = app(InventoryAccess::class)->records(Outage::query())->where('recovered_at', '>=', $since)->selectRaw('policy_id, count(*) c')->groupBy('policy_id')->pluck('c', 'policy_id');
        $wouldByPolicy = app(InventoryAccess::class)->throughIncident(NotificationOutbox::query())->where('iapm_notification_outbox.status', 'dry_run')->where('iapm_notification_outbox.created_at', '>=', $since)->join('iapm_outages', function ($join): void {
            $join->on('iapm_outages.incident_id', '=', 'iapm_notification_outbox.incident_id')->on('iapm_outages.episode_uuid', '=', 'iapm_notification_outbox.episode_uuid');
        })->selectRaw('iapm_outages.policy_id, count(*) c')->groupBy('iapm_outages.policy_id')->pluck('c', 'policy_id');
        $sentByPolicy = app(InventoryAccess::class)->throughIncident(NotificationOutbox::query())->where('iapm_notification_outbox.status', 'sent')->where('iapm_notification_outbox.created_at', '>=', $since)->join('iapm_outages', function ($join): void {
            $join->on('iapm_outages.incident_id', '=', 'iapm_notification_outbox.incident_id')->on('iapm_outages.episode_uuid', '=', 'iapm_notification_outbox.episode_uuid');
        })->selectRaw('iapm_outages.policy_id, count(*) c')->groupBy('iapm_outages.policy_id')->pluck('c', 'policy_id');
        $suppressedByPolicy = app(InventoryAccess::class)->records(Outage::query())->where('recovered_at', '>=', $since)->whereNotNull('suppression_reason')->selectRaw('policy_id, count(*) c')->groupBy('policy_id')->pluck('c', 'policy_id');

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
