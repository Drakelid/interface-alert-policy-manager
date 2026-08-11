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
 * Rebuilding stays an explicit action. It is O(every port), so triggering it
 * automatically on each policy or assignment save would re-resolve the whole
 * fleet on every edit; instead the UI shows a staleness warning and a button.
 */
class PolicyCacheRebuilder
{
    public const REBUILT_AT = 'cache_rebuilt_at';

    public const STATUS = 'cache_rebuild_status';

    public const PROGRESS = 'cache_rebuild_progress';

    public const TOTAL = 'cache_rebuild_total';

    public const STARTED_AT = 'cache_rebuild_started_at';

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
        $this->settings->put(self::TOTAL, Port::count());
        $this->settings->put(self::STARTED_AT, now()->toIso8601String());
        $this->settings->put(self::ERROR, null);
    }

    public function markFailed(string $message): void
    {
        $this->settings->put(self::STATUS, 'failed');
        $this->settings->put(self::ERROR, $message);
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
        $this->settings->put(self::STATUS, 'running');

        // First batch clears the whole table: a partial rebuild that only
        // overwrites the ports it visits would leave rows for deleted ports.
        if ($afterPortId === null) {
            DB::table('iapm_interface_policy_cache')->delete();
        }

        $ports = Port::with(['device.location', 'device.groups', 'groups'])
            ->when($afterPortId !== null, fn ($q) => $q->where('port_id', '>', $afterPortId))
            ->orderBy('port_id')
            ->limit($limit)
            ->get();

        foreach ($ports as $port) {
            $this->resolver->resolve($this->contexts->forPort($port));
        }

        $processed = $ports->count();
        $this->settings->put(self::PROGRESS, (int) $this->settings->get(self::PROGRESS, 0) + $processed);
        $done = $processed < $limit;
        if ($done) {
            $this->settings->put(self::STATUS, 'complete');
            $this->settings->put(self::REBUILT_AT, now()->toIso8601String());
        }

        return ['done' => $done, 'last' => $ports->last()?->port_id, 'processed' => $processed];
    }

    /** Called by the console command, which rebuilds synchronously in one pass. */
    public function markCompletedNow(): void
    {
        $this->settings->put(self::STATUS, 'complete');
        $this->settings->put(self::REBUILT_AT, now()->toIso8601String());
        $this->settings->put(self::ERROR, null);
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
        $startedAt = $this->settings->get(self::STARTED_AT);
        $progress = (int) $this->settings->get(self::PROGRESS, 0);

        // A rebuild that was queued but never picked up means no worker is
        // draining the queue; say so rather than spinning forever.
        $stalled = in_array($status, ['queued', 'running'], true)
            && $startedAt !== null
            && CarbonImmutable::parse($startedAt)->addSeconds(self::STALLED_AFTER_SECONDS)->isPast()
            && $progress === 0;

        return [
            'status' => $stalled ? 'stalled' : $status,
            'progress' => $progress,
            'total' => (int) $this->settings->get(self::TOTAL, 0),
            'rebuilt_at' => $this->rebuiltAt()?->toIso8601String(),
            'rebuilt_at_human' => $this->rebuiltAt()?->diffForHumans(),
            'stale' => $this->isStale(),
            'changed_at' => $this->configurationChangedAt()?->toIso8601String(),
            'error' => $this->settings->get(self::ERROR),
            'running' => in_array($status, ['queued', 'running'], true) && ! $stalled,
        ];
    }
}
