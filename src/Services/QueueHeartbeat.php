<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\QueueHeartbeatJob;

/**
 * Proof that a queue worker is alive.
 *
 * `iapm:health` used to infer worker liveness from `last_queue_worker_at`, which
 * only SendNotificationJob wrote — so the check went red after ten quiet minutes
 * on a perfectly healthy install, and an operator with six systemd workers, an
 * empty queue and no failed jobs was told no worker was draining the queue.
 *
 * Inferring the opposite ("queue is empty, so a worker must be fine") would be
 * worse: an empty queue with every worker dead looks identical. So liveness is
 * proven end-to-end instead — the scheduler enqueues a trivial job on the IAPM
 * queue and a worker must actually execute it to move the timestamp. That
 * exercises the whole path: settings -> configured connection -> `iapm` queue ->
 * worker process -> execution -> persisted timestamp.
 *
 * Four settings, each with exactly one meaning:
 *   last_queue_heartbeat_at        a worker executed a heartbeat (liveness)
 *   last_queue_delivery_at         a worker executed a real notification (traffic)
 *   queue_heartbeat_pending_since  when the current unconsumed wait began (health)
 *   queue_heartbeat_dispatched_at  when a heartbeat was last enqueued (rate limit)
 *
 * The last two are deliberately separate. Folding them into one made the wait
 * start move every time a replacement was enqueued, which both reset the health
 * clock and turned the self-heal into a job every single minute.
 *
 * All four live in SettingStore; no schema change is required.
 */
class QueueHeartbeat
{
    public const CONSUMED_KEY = 'last_queue_heartbeat_at';

    public const PENDING_KEY = 'queue_heartbeat_pending_since';

    public const DISPATCHED_KEY = 'queue_heartbeat_dispatched_at';

    /** Set by SendNotificationJob. Traffic visibility only — never worker liveness. */
    public const DELIVERY_KEY = 'last_queue_delivery_at';

    public function __construct(private readonly SettingStore $settings) {}

    public function staleAfterSeconds(): int
    {
        // An explicit null in config must fall back to the documented default,
        // not to zero — config()'s own default only covers a missing key.
        $configured = config('iapm.queue.heartbeat_stale_seconds');
        $seconds = is_numeric($configured) ? (int) $configured : 300;

        // A threshold under a minute would alarm on a single missed scheduler
        // tick, which is exactly the false alarm this check exists to avoid.
        return max(60, $seconds);
    }

    /**
     * A heartbeat that is enqueued but never consumed would otherwise block every
     * future heartbeat for good — so a job lost to a queue flush or an exhausted
     * retry would pin health red even after the workers came back. Re-dispatching
     * after this long is a self-heal, not the normal path: while workers are
     * simply stopped the single outstanding job is still sitting on the queue and
     * is consumed the moment one returns.
     */
    private function redispatchAfterSeconds(): int
    {
        return max(600, $this->staleAfterSeconds() * 2);
    }

    /**
     * Enqueue a heartbeat if one is not already outstanding.
     *
     * @return string one of: not-queued-mode, dispatched, redispatched, outstanding, failed
     */
    public function enqueueIfDue(): string
    {
        if ($this->settings->get('dispatch_mode', 'queue') !== 'queue') {
            // Synchronous delivery needs no worker. Clear any wait left over from
            // a previous queued period so switching back does not start red.
            if ($this->pendingSince() !== null) {
                $this->settings->put(self::PENDING_KEY, null);
            }

            return 'not-queued-mode';
        }

        $pending = $this->pendingSince();
        if ($pending !== null) {
            // Something is already queued and unconsumed. Normally leave it alone:
            // while workers are merely stopped that one job is still sitting on the
            // queue and is consumed the instant a worker returns. Only re-enqueue
            // occasionally, in case the job was lost rather than merely waiting.
            $lastDispatch = $this->timestamp(self::DISPATCHED_KEY);
            if ($lastDispatch !== null && $lastDispatch->addSeconds($this->redispatchAfterSeconds())->isFuture()) {
                return 'outstanding';
            }
        }

        // Recorded before dispatching, not after: a fast worker can execute the job
        // and clear the marker before this method resumes, and writing afterwards
        // would then resurrect a wait that had already been satisfied.
        if ($pending === null) {
            $this->settings->put(self::PENDING_KEY, CarbonImmutable::now()->toIso8601String());
        }
        $this->settings->put(self::DISPATCHED_KEY, CarbonImmutable::now()->toIso8601String());

        try {
            QueueHeartbeatJob::dispatch();
        } catch (\Throwable $exception) {
            // The backend is unreachable. Restore the previous wait so the health
            // check keeps measuring from when the trouble actually started, rather
            // than showing a phantom heartbeat that was never really enqueued.
            $this->settings->put(self::PENDING_KEY, $pending?->toIso8601String());
            Log::channel('iapm')->error('Queue heartbeat could not be enqueued.', ['error' => $exception->getMessage()]);

            return 'failed';
        }

        return $pending === null ? 'dispatched' : 'redispatched';
    }

    /** Called by the job, inside the worker process. */
    public function recordConsumed(): void
    {
        $this->settings->put(self::CONSUMED_KEY, CarbonImmutable::now()->toIso8601String());
        $this->settings->put(self::PENDING_KEY, null);
    }

    public function consumedAt(): ?CarbonImmutable
    {
        return $this->timestamp(self::CONSUMED_KEY);
    }

    public function pendingSince(): ?CarbonImmutable
    {
        return $this->timestamp(self::PENDING_KEY);
    }

    public function lastDeliveryAt(): ?CarbonImmutable
    {
        return $this->timestamp(self::DELIVERY_KEY);
    }

    /**
     * Worker liveness, as a health check.
     *
     * Deliberately says nothing about the notification backlog: a stalled worker
     * and a stuck outbox are different faults with different remedies, and the
     * old check collapsed them into one misleading sentence. The backlog is
     * reported by HealthService::backlogCheck().
     *
     * @return array{ok:bool, state:string, detail:string}
     */
    public function status(): array
    {
        $stale = $this->staleAfterSeconds();
        $consumed = $this->consumedAt();
        $pending = $this->pendingSince();
        $delivery = $this->lastDeliveryAt();
        $suffix = $delivery ? ' Last notification delivered '.$delivery->diffForHumans().'.' : '';

        if ($consumed !== null && $consumed->addSeconds($stale)->isFuture()) {
            return ['ok' => true, 'state' => 'alive', 'detail' => 'Last worker heartbeat '.$consumed->diffForHumans().'.'.$suffix];
        }

        // A worker has proven itself before and that proof has now expired. This is
        // authoritative: a freshly enqueued replacement must not buy more grace,
        // or a dead worker would stay green for as long as the scheduler kept
        // enqueueing heartbeats it never consumed.
        if ($consumed !== null) {
            return $pending !== null
                ? ['ok' => false, 'state' => 'stale', 'detail' => $this->stoppedWorkerDetail($consumed)]
                : [
                    'ok' => false,
                    'state' => 'unscheduled',
                    'detail' => 'Last worker heartbeat '.$consumed->diffForHumans().' and no new heartbeat is queued — confirm the LibreNMS scheduler is running every minute.',
                ];
        }

        // No worker has ever consumed a heartbeat on this install.
        if ($pending !== null) {
            return $pending->addSeconds($stale)->isPast()
                ? ['ok' => false, 'state' => 'stale', 'detail' => $this->stoppedWorkerDetail($pending)]
                // Inside the startup grace window: normal for the first minute
                // after enabling queued delivery or restarting workers.
                : ['ok' => true, 'state' => 'waiting', 'detail' => 'Heartbeat queued '.$pending->diffForHumans().'; waiting for a worker to consume it.'.$suffix];
        }

        // Queued delivery was only just enabled; the scheduler enqueues the first
        // heartbeat within a minute, and the stale timer starts from that point.
        return ['ok' => true, 'state' => 'pending-first', 'detail' => 'Queued delivery is enabled; the first heartbeat has not been enqueued yet.'];
    }

    /**
     * Names the queue and connection a worker must be listening on, because the
     * usual cause is a worker running against the wrong one. Deliberately does
     * not say "run php artisan queue:work": the supported production setup has
     * systemd or Supervisor owning the workers, and starting a stray one by hand
     * would mask the real fault.
     */
    private function stoppedWorkerDetail(CarbonImmutable $since): string
    {
        return sprintf(
            'No IAPM queue heartbeat has been consumed for %s. Check the worker process or external supervisor (systemd, Supervisor, container) and confirm workers listen on queue "%s" using the "%s" connection. Switching Delivery dispatch to Synchronous in Settings sends inline without workers.',
            // DIFF_ABSOLUTE so this reads "12 minutes", not "12 minutes ago"
            // inside a sentence that already supplies the tense.
            $since->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE),
            (string) config('iapm.queue.name', 'iapm'),
            $this->connectionName()
        );
    }

    private function connectionName(): string
    {
        return (string) (config('iapm.queue.connection') ?: config('queue.default', 'database'));
    }

    private function timestamp(string $key): ?CarbonImmutable
    {
        $value = $this->settings->get($key);
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            // A malformed timestamp must not be read as "recent"; treat it as absent.
            return null;
        }
    }
}
