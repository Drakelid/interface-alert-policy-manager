<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Carbon\CarbonImmutable;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;

/**
 * Self-monitoring / dead-man's switch. Silence from a paging system is only
 * trustworthy if the system itself is known to be running, so these checks
 * surface a stalled scheduler, a failing gateway, or an action backlog — both
 * in the UI and via `iapm:health` (non-zero exit) for an external monitor.
 */
class HealthService
{
    /** Scheduler runs every minute; allow generous slack before flagging it stalled. */
    public const STALE_AFTER_SECONDS = 600;
    public const GATEWAY_FAILURE_WINDOW = 900;
    public const BACKLOG_OVERDUE_SECONDS = 600;

    public function __construct(private readonly SettingStore $settings) {}

    /** @return list<array{key:string,label:string,ok:bool,detail:string}> */
    public function checks(): array
    {
        return [
            $this->schedulerCheck('reconcile', 'Reconciliation running', 'last_reconcile_at'),
            $this->schedulerCheck('process_actions', 'Action processing running', 'last_process_actions_at'),
            $this->gatewayCheck(),
            $this->backlogCheck(),
        ];
    }

    public function healthy(): bool
    {
        return collect($this->checks())->every(fn ($c) => $c['ok']);
    }

    private function schedulerCheck(string $key, string $label, string $settingKey): array
    {
        $last = $this->timestamp($settingKey);
        $ok = $last !== null && $last->addSeconds(self::STALE_AFTER_SECONDS)->isFuture();
        $detail = $last === null
            ? 'Has not run yet — confirm the LibreNMS scheduler executes `php artisan schedule:run` every minute.'
            : 'Last run '.$last->diffForHumans();

        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    private function gatewayCheck(): array
    {
        $success = $this->timestamp('last_gateway_success_at');
        $failure = $this->timestamp('last_gateway_failure_at');

        // Unhealthy only when the most recent delivery outcome was a failure and
        // it is recent — a long-idle gateway with no traffic is fine.
        $recentFailure = $failure !== null
            && $failure->addSeconds(self::GATEWAY_FAILURE_WINDOW)->isFuture()
            && ($success === null || $failure->greaterThan($success));

        return [
            'key' => 'gateway',
            'label' => 'Gateway delivering',
            'ok' => ! $recentFailure,
            'detail' => $recentFailure ? 'Recent delivery failures — check the destination and delivery log.' : ($success ? 'Last success '.$success->diffForHumans() : 'No deliveries yet.'),
        ];
    }

    private function backlogCheck(): array
    {
        try {
            $overdue = Incident::query()
                ->where('state', 'active')
                ->whereNotNull('triggered_at')
                ->where('triggered_at', '<', now()->subSeconds(self::BACKLOG_OVERDUE_SECONDS))
                ->whereHas('policy', fn ($q) => $q->where('notifications_enabled', true))
                ->whereDoesntHave('deliveries', fn ($q) => $q->whereIn('status', ['sent', 'dry_run']))
                ->count();
        } catch (\Throwable) {
            $overdue = 0;
        }

        return [
            'key' => 'action_backlog',
            'label' => 'No stuck notifications',
            'ok' => $overdue === 0,
            'detail' => $overdue === 0 ? 'All triggered incidents have been actioned.' : "{$overdue} active incident(s) triggered >10m ago with no delivery.",
        ];
    }

    private function timestamp(string $key): ?CarbonImmutable
    {
        $value = $this->settings->get($key);
        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
