<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\IngestionInbox;
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

    /**
     * How long an abandoned ingestion payload keeps the health check red. Dead
     * rows are retained until the retention cutoff (a year by default), so an
     * unbounded check would leave `iapm:health` failing — and any external
     * monitor watching its exit code alarming — long after the operator had
     * seen and handled the loss. The count stays visible in the detail line.
     */
    public const ABANDONED_ALERT_SECONDS = 86400;

    public function __construct(private readonly SettingStore $settings) {}

    /** @return list<array{key:string,label:string,ok:bool,detail:string}> */
    public function checks(): array
    {
        $checks = [
            $this->schedulerCheck('reconcile', 'Reconciliation running', 'last_reconcile_at'),
            $this->schedulerCheck('process_actions', 'Action processing running', 'last_process_actions_at'),
            $this->gatewayCheck(),
            $this->ingestionInboxCheck(),
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
            // Names the condition and the remedy the operator can actually reach
            // from here, rather than a shell command a web-only administrator
            // cannot run (P1-7). Host-level worker setup is in the README.
            'detail' => $ok
                ? ($last ? 'Last worker activity '.$last->diffForHumans() : 'Queued mode enabled; no traffic yet.')
                : "Queued delivery is enabled but no worker is draining the queue ({$pending} notification(s) stuck). Either start the IAPM queue workers on the LibreNMS host, or switch Delivery dispatch to Synchronous in Settings to send inline instead.",
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
        // A stalled scheduler is a host-level cron problem and cannot be fixed
        // from this UI, so state the condition rather than a command to paste.
        $detail = $last === null
            ? 'Has not run yet — IAPM relies on the LibreNMS scheduler running every minute. Confirm the standard LibreNMS cron entry is installed and running on the host.'
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
            $counts = NotificationOutbox::query()->whereIn('status', ['pending', 'queued', 'processing', 'failed'])->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
            $stale = $this->staleOutboxQuery(self::BACKLOG_OVERDUE_SECONDS)->count();
            $unfinalized = NotificationOutbox::whereIn('status', ['sent', 'dry_run'])->whereNull('finalized_at')->count();
        } catch (\Throwable $exception) {
            Log::channel('iapm')->error('Notification backlog health query failed.', ['error' => $exception->getMessage()]);

            return ['key' => 'action_backlog', 'label' => 'No stuck notifications', 'ok' => false, 'detail' => 'Backlog query failed: '.$exception->getMessage()];
        }

        $pending = (int) ($counts['pending'] ?? 0);
        $inFlight = (int) ($counts['queued'] ?? 0) + (int) ($counts['processing'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $ok = $overdue === 0 && $stale === 0 && $unfinalized === 0;

        return [
            'key' => 'action_backlog',
            'label' => 'No stuck notifications',
            'ok' => $ok,
            'detail' => "pending={$pending}, in-flight={$inFlight}, failed={$failed}, stale={$stale}, unfinalized={$unfinalized}, overdue incidents={$overdue}".($ok ? '.' : ' — current-episode notification processing is unhealthy.'),
        ];
    }

    private function ingestionInboxCheck(): array
    {
        try {
            $cutoff = now()->subSeconds(self::BACKLOG_OVERDUE_SECONDS);
            $stuck = IngestionInbox::query()->where(function ($query) use ($cutoff): void {
                $query->where(fn ($pending) => $pending->whereIn('status', ['pending', 'failed'])->where('created_at', '<', $cutoff))
                    ->orWhere(fn ($processing) => $processing->where('status', 'processing')->where('claimed_at', '<', $cutoff));
            })->count();
            $pending = IngestionInbox::whereIn('status', ['pending', 'failed', 'processing'])->count();
            // Abandoned payloads were accepted with 202 but never applied. That is
            // silent alert loss unless an operator is told, so surface it here.
            // Only a recent abandonment fails the check; the total stays visible.
            $dead = IngestionInbox::where('status', 'dead')->count();
            $recentlyDead = IngestionInbox::where('status', 'dead')->where('updated_at', '>=', now()->subSeconds(self::ABANDONED_ALERT_SECONDS))->count();
        } catch (\Throwable $exception) {
            Log::channel('iapm')->error('Ingestion inbox health query failed.', ['error' => $exception->getMessage()]);

            return ['key' => 'ingestion_inbox', 'label' => 'Durable ingestion draining', 'ok' => false, 'detail' => 'Ingestion inbox query failed.'];
        }

        return ['key' => 'ingestion_inbox', 'label' => 'Durable ingestion draining', 'ok' => $stuck === 0 && $recentlyDead === 0, 'detail' => "pending={$pending}, stale={$stuck}, abandoned={$dead}".($dead > 0 ? ' — accepted payloads were never applied; inspect last_error_redacted.' : '.')];
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
                ->orWhere(fn ($processing) => $processing->where('status', 'processing')->where(fn ($claimed) => $claimed->whereNull('claimed_at')->orWhere('claimed_at', '<', $cutoff)))
                ->orWhere(fn ($failed) => $failed->where('status', 'failed')->where('available_at', '<', $cutoff));
        });
    }
}
