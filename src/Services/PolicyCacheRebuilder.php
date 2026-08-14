<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use App\Models\Port;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the materialized effective-policy cache the Interface Matrix filters
 * read, and tracks enough state for the UI to report progress (P1-7).
 *
 * The matrix used to tell operators to run `php artisan iapm:cache-rebuild`,
 * which a web-only administrator cannot do. The work itself is unchanged; this
 * wraps it so both the console command and a queued job share one implementation
 * and one notion of "when was this last rebuilt".
 *
 * Rebuilding is O(every port), so edits only mark the cache stale instead of
 * rebuilding immediately. The scheduler refreshes it hourly and the UI offers
 * an on-demand rebuild when an operator needs the new result sooner.
 */
class PolicyCacheRebuilder
{
    public const REBUILT_AT = 'cache_rebuilt_at';

    public const STATUS = 'cache_rebuild_status';

    public const PROGRESS = 'cache_rebuild_progress';

    public const TOTAL = 'cache_rebuild_total';

    public const STARTED_AT = 'cache_rebuild_started_at';

    public const ACTIVITY_AT = 'cache_rebuild_activity_at';

    public const ERROR = 'cache_rebuild_error';

    /** A queued rebuild that has not moved in this long means nothing is draining the queue. */
    private const STALLED_AFTER_SECONDS = 300;

    public function __construct(
        private readonly SettingStore $settings,
        private readonly InterfaceContextService $contexts,
        private readonly PolicyResolver $resolver,
    ) {}

    public function markQueued(): void
    {
        $this->settings->put(self::STATUS, 'queued');
        $this->settings->put(self::PROGRESS, 0);
        $this->settings->put(self::TOTAL, Port::whereHas('device')->count());
        $now = now()->toIso8601String();
        $this->settings->put(self::STARTED_AT, $now);
        $this->settings->put(self::ACTIVITY_AT, $now);
        $this->settings->put(self::ERROR, null);
    }

    public function markFailed(string $message): void
    {
        $this->settings->put(self::STATUS, 'failed');
        $this->settings->put(self::ERROR, $message);
        $this->settings->put(self::ACTIVITY_AT, now()->toIso8601String());
    }

    /** Mark a synchronous rebuild as active and expose its progress to the UI. */
    public function markRunning(int $progress = 0): void
    {
        $this->settings->put(self::STATUS, 'running');
        $this->settings->put(self::PROGRESS, $progress);
        $this->settings->put(self::ACTIVITY_AT, now()->toIso8601String());
    }

    /**
     * Resolves one batch of ports, resuming after $afterPortId.
     *
     * Chunked rather than run to completion in one job because the iapm queue's
     * worker timeout is 60 seconds by default; a fleet-wide rebuild would be
     * killed and stale-reclaimed part way through.
     *
     * @return array{done: bool, last: int|null, processed: int}
     */
    public function runBatch(?int $afterPortId, int $limit): array
    {
        $baseProgress = (int) $this->settings->get(self::PROGRESS, 0);
        $this->markRunning($baseProgress);

        // First batch clears the whole table: a partial rebuild that only
        // overwrites the ports it visits would leave rows for deleted ports.
        if ($afterPortId === null) {
            DB::table('iapm_interface_policy_cache')->delete();
        }

        $ports = Port::with(['device.location', 'device.groups', 'groups'])
            // LibreNMS installations can retain ports after their device row has
            // been removed. They cannot resolve to a policy without device
            // context, so exclude them instead of aborting the fleet rebuild.
            ->whereHas('device')
            ->when($afterPortId !== null, fn ($q) => $q->where('port_id', '>', $afterPortId))
            ->orderBy('port_id')
            ->limit($limit)
            ->get();

        $processed = 0;
        $last = null;
        $checkpointEvery = max(1, min($limit, (int) config('iapm.processing.cache_rebuild_checkpoint_every', 10)));
        $workerTimeout = max(15, (int) config('iapm.queue.timeout', 60));
        $maxSeconds = max(5, min($workerTimeout - 5, (int) config('iapm.processing.cache_rebuild_max_seconds', 40)));
        $deadline = microtime(true) + $maxSeconds;

        foreach ($ports as $port) {
            $this->resolver->resolve($this->contexts->forPort($port));
            $processed++;
            $last = (int) $port->port_id;

            if ($processed % $checkpointEvery === 0) {
                $this->markRunning($baseProgress + $processed);
            }
            if (microtime(true) >= $deadline) {
                break;
            }
        }

        $this->markRunning($baseProgress + $processed);
        $done = $processed === $ports->count() && $ports->count() < $limit;
        if ($done) {
            $this->settings->put(self::STATUS, 'complete');
            $this->settings->put(self::REBUILT_AT, now()->toIso8601String());
            $this->settings->put(self::ERROR, null);
        }

        return ['done' => $done, 'last' => $last, 'processed' => $processed];
    }

    /** Called by the console command, which rebuilds synchronously in one pass. */
    public function markCompletedNow(): void
    {
        $this->settings->put(self::STATUS, 'complete');
        $this->settings->put(self::REBUILT_AT, now()->toIso8601String());
        $this->settings->put(self::ERROR, null);
        $this->settings->put(self::ACTIVITY_AT, now()->toIso8601String());
    }

    public function rebuiltAt(): ?CarbonImmutable
    {
        $value = $this->settings->get(self::REBUILT_AT);

        return $value ? CarbonImmutable::parse($value) : null;
    }

    /**
     * The most recent policy or assignment edit. Anything newer than the last
     * rebuild means the matrix's policy/source/no-policy filters may be wrong.
     */
    public function configurationChangedAt(): ?CarbonImmutable
    {
        $latest = collect([
            DB::table('iapm_policies')->max('updated_at'),
            DB::table('iapm_assignments')->max('updated_at'),
        ])->filter()->max();

        return $latest ? CarbonImmutable::parse($latest) : null;
    }

    public function isStale(): bool
    {
        $rebuilt = $this->rebuiltAt();
        if ($rebuilt === null) {
            // Never rebuilt: stale only if there is configuration to reflect.
            return $this->configurationChangedAt() !== null;
        }
        $changed = $this->configurationChangedAt();

        return $changed !== null && $changed->greaterThan($rebuilt);
    }

    /**
     * Everything the matrix banner and the status poll need.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $status = (string) ($this->settings->get(self::STATUS) ?? 'idle');
        $activityAt = $this->settings->get(self::ACTIVITY_AT) ?? $this->settings->get(self::STARTED_AT);
        $progress = (int) $this->settings->get(self::PROGRESS, 0);

        // Queued means no worker picked it up; running means a worker started but
        // stopped between checkpoints (usually a timeout or forced restart).
        $stalled = in_array($status, ['queued', 'running'], true)
            && $activityAt !== null
            && CarbonImmutable::parse($activityAt)->addSeconds(self::STALLED_AFTER_SECONDS)->isPast();

        return [
            'status' => $stalled ? 'stalled' : $status,
            'progress' => $progress,
            'total' => (int) $this->settings->get(self::TOTAL, 0),
            'rebuilt_at' => $this->rebuiltAt()?->toIso8601String(),
            'rebuilt_at_human' => $this->rebuiltAt()?->diffForHumans(),
            'stale' => $this->isStale(),
            'changed_at' => $this->configurationChangedAt()?->toIso8601String(),
            'error' => $this->settings->get(self::ERROR),
            'status_message' => $stalled
                ? ($status === 'queued'
                    ? 'The rebuild is still queued. IAPM workers may be stopped or busy on older jobs.'
                    : 'A rebuild worker stopped between progress checkpoints. Retry the rebuild; the new run uses time-bounded batches.')
                : null,
            'running' => in_array($status, ['queued', 'running'], true) && ! $stalled,
        ];
    }
}
