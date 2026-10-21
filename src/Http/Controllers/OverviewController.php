<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\HealthService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InventoryAccess;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ReadinessService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;

class OverviewController extends Controller
{
    public function __invoke(SettingStore $settings, ReadinessService $readiness, HealthService $health)
    {
        $access = app(InventoryAccess::class);
        $checks = $access->unrestricted() ? $readiness->checks() : [];
        $incidents = fn () => $access->records(Incident::query());
        $deliveries = $access->throughIncident(DeliveryLog::query());

        return view('iapm::overview', ['readiness' => $checks, 'health' => $access->unrestricted() ? $health->checks() : [], 'setupComplete' => collect($checks)->where('group', 'setup')->every(fn ($c) => $c['ok']), 'counts' => $incidents()->whereIn('state', ['pending', 'active', 'acknowledged', 'suppressed'])->selectRaw('state, count(*) total')->groupBy('state')->pluck('total', 'state'), 'incidents' => $incidents()->latest('last_seen_at')->simplePaginate(25), 'metrics' => ['active_critical' => $incidents()->where('state', 'active')->where('severity', 'critical')->count(), 'active_warning' => $incidents()->where('state', 'active')->where('severity', 'warning')->count(), 'failed_deliveries' => $deliveries->whereIn('status', ['failed', 'failed_configuration'])->where('created_at', '>=', now()->subDay())->count(), 'missing_policies' => $incidents()->where('state', 'suppressed')->where('suppression_reason', 'no_policy')->count(), 'recovered_24h' => $incidents()->where('state', 'recovered')->where('recovered_at', '>=', now()->subDay())->count(), 'awaiting_escalation' => $incidents()->where('state', 'active')->whereHas('policy.actions', fn ($q) => $q->where('phase', 'escalation')->where('enabled', true))->count(), 'dry_run' => (bool) $settings->get('dry_run', true)]]);
    }
}
