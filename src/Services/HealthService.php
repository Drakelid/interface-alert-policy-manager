<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;

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
        $checks = [
            $this->schedulerCheck('reconcile', 'Reconciliation running', 'last_reconcile_at'),
            $this->schedulerCheck('process_actions', 'Action processing running', 'last_process_actions_at'),
            $this->gatewayCheck(),
            $this->backlogCheck(),
        ];

        // Only relevant when queued delivery is enabled: a worker must be draining
        // the queue, or notifications pile up undelivered.
        if ($this->settings->get('dispatch_mode', 'queue') === 'queue') {
            $checks[] = $this->queueWorkerCheck();
        }

        return $checks;
    }

    private function queueWorkerCheck(): array
    {
        $last = $this->timestamp('last_queue_worker_at');
        try {
            $pending = $this->staleOutboxQuery(self::STALE_AFTER_SECONDS)->count();
        } catch (\Throwable $exception) {
            Log::channel('iapm')->error('Queue health query failed.', ['error' => $exception->getMessage()]);

            return ['key' => 'queue_worker', 'label' => 'Queue worker delivering', 'ok' => false, 'detail' => 'Queue health query failed: '.$exception->getMessage()];
        }
        $ok = $pending === 0 && ($last === null || $last->addSeconds(self::STALE_AFTER_SECONDS)->isFuture());

        return [
            'key' => 'queue_worker',
            'label' => 'Queue worker delivering',
            'ok' => $ok,
            'detail' => $ok
                ? ($last ? 'Last worker activity '.$last->diffForHumans() : 'Queued mode enabled; no traffic yet.')
                : "Queued delivery is enabled but a worker is not draining the queue ({$pending} stuck). Run `php artisan queue:work`.",
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
                ->whereDoesntHave('deliveries', fn ($query) => $query->whereIn('phase', ['trigger', 'digest'])->whereIn('status', ['sent', 'dry_run'])->whereColumn('iapm_delivery_logs.episode_uuid', 'iapm_incidents.episode_uuid'))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('iapm_notification_outbox_incidents as noi')->join('iapm_notification_outbox as no', 'no.id', '=', 'noi.notification_outbox_id')->whereColumn('noi.incident_id', 'iapm_incidents.id')->whereColumn('noi.episode_uuid', 'iapm_incidents.episode_uuid')->whereIn('no.phase', ['trigger', 'digest'])->whereIn('no.status', ['sent', 'dry_run', 'pending', 'queued', 'processing']))
                ->count();
            $counts = NotificationOutbox::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
            $stale = $this->staleOutboxQuery(self::BACKLOG_OVERDUE_SECONDS)->count();
        } catch (\Throwable $exception) {
            Log::channel('iapm')->error('Notification backlog health query failed.', ['error' => $exception->getMessage()]);

            return ['key' => 'action_backlog', 'label' => 'No stuck notifications', 'ok' => false, 'detail' => 'Backlog query failed: '.$exception->getMessage()];
        }

        $pending = (int) ($counts['pending'] ?? 0);
        $inFlight = (int) ($counts['queued'] ?? 0) + (int) ($counts['processing'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $ok = $overdue === 0 && $stale === 0;

        return [
            'key' => 'action_backlog',
            'label' => 'No stuck notifications',
            'ok' => $ok,
            'detail' => "pending={$pending}, in-flight={$inFlight}, failed={$failed}, stale={$stale}, overdue incidents={$overdue}".($ok ? '.' : ' — current-episode notification processing is unhealthy.'),
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

    private function staleOutboxQuery(int $seconds)
    {
        $cutoff = now()->subSeconds($seconds);

        return NotificationOutbox::query()->where(function ($query) use ($cutoff): void {
            $query->where(fn ($pending) => $pending->whereIn('status', ['pending', 'queued'])->where('created_at', '<', $cutoff))
                ->orWhere(fn ($processing) => $processing->where('status', 'processing')->where(fn ($claimed) => $claimed->whereNull('claimed_at')->orWhere('claimed_at', '<', $cutoff)));
        });
    }
}
