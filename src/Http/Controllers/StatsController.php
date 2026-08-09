<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Outage;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;

class StatsController extends Controller
{
    public function __invoke(Request $request)
    {
        $days = max(1, min(365, $request->integer('days', 30)));
        $since = now()->subDays($days);
        $base = fn () => Outage::query()->where('recovered_at', '>=', $since);

        $metrics = [
            'outages' => $base()->count(),
            'avg_detect' => $base()->whereNotNull('detect_seconds')->avg('detect_seconds'),
            'avg_duration' => $base()->avg('duration_seconds'),
            'max_duration' => $base()->max('duration_seconds'),
            'flapping' => $base()->where('was_flapping', true)->count(),
            'notifications' => (int) $base()->sum('notification_count'),
        ];

        $topInterfaces = $base()->selectRaw('device_id, port_id, count(*) as outages, sum(duration_seconds) as total_down')
            ->groupBy('device_id', 'port_id')->orderByDesc('outages')->limit(10)->get();

        $byPolicy = $base()->selectRaw('policy_id, count(*) as outages, avg(duration_seconds) as avg_duration')
            ->groupBy('policy_id')->orderByDesc('outages')->get();

        // Daily outage counts for a sparkline (gap-filled over the window).
        $perDay = Outage::query()->where('recovered_at', '>=', $since)->selectRaw('date(recovered_at) as d, count(*) as c')->groupBy('d')->pluck('c', 'd');
        $spark = [];
        $sparkDays = min($days, 60);
        for ($i = $sparkDays - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $spark[$day] = (int) ($perDay[$day] ?? 0);
        }

        $delivery = NotificationOutbox::where('created_at', '>=', $since)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $attempts = DeliveryLog::whereNotNull('notification_outbox_id')->where('created_at', '>=', $since)->whereIn('status', ['sent', 'failed'])->count();
        $liveCompleted = (int) ($delivery['sent'] ?? 0) + (int) ($delivery['failed'] ?? 0);
        $deliverySuccessRate = $liveCompleted > 0 ? round(100 * (int) ($delivery['sent'] ?? 0) / $liveCompleted, 1) : null;

        return view('iapm::stats', [
            'days' => $days,
            'metrics' => $metrics,
            'topInterfaces' => $topInterfaces,
            'byPolicy' => $byPolicy,
            'policyNames' => Policy::pluck('name', 'id'),
            'delivery' => $delivery,
            'transportAttempts' => $attempts,
            'deliverySuccessRate' => $deliverySuccessRate,
            'spark' => $spark,
        ]);
    }
}
