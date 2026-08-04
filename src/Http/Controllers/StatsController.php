<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
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

        $delivery = DeliveryLog::where('created_at', '>=', $since)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $deliveryTotal = $delivery->sum();
        $deliverySuccessRate = $deliveryTotal > 0 ? round(100 * (($delivery['sent'] ?? 0) + ($delivery['dry_run'] ?? 0)) / $deliveryTotal, 1) : null;

        return view('iapm::stats', [
            'days' => $days,
            'metrics' => $metrics,
            'topInterfaces' => $topInterfaces,
            'byPolicy' => $byPolicy,
            'policyNames' => Policy::pluck('name', 'id'),
            'delivery' => $delivery,
            'deliverySuccessRate' => $deliverySuccessRate,
        ]);
    }
}
