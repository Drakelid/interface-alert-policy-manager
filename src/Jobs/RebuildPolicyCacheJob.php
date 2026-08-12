<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyCacheRebuilder;

/**
 * Rebuilds the Interface Matrix's effective-policy cache from the web UI (P1-7).
 *
 * Each run handles one batch and re-dispatches itself for the next, carrying
 * only a port-id cursor. That keeps every job well inside the iapm queue's
 * 60-second worker timeout — a fleet-wide rebuild in a single job would be
 * killed and stale-reclaimed part way through — and gives the UI real progress
 * to display instead of an indeterminate spinner.
 */
class RebuildPolicyCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public ?int $afterPortId = null)
    {
        $this->timeout = max(15, (int) config('iapm.queue.timeout', 60));
        if ($queue = config('iapm.queue.name')) {
            $this->onQueue($queue);
        }
        if ($connection = config('iapm.queue.connection')) {
            $this->onConnection($connection);
        }
    }

    public function handle(PolicyCacheRebuilder $rebuilder): void
    {
        $batch = max(10, min(500, (int) config('iapm.processing.cache_rebuild_batch_size', 100)));
        $result = $rebuilder->runBatch($this->afterPortId, $batch);

        if ($result['done']) {
            return;
        }

        // On the sync driver a re-dispatch runs inline, so this unwinds as a
        // loop of nested calls rather than separate worker runs. That is fine
        // for the small installations sync is meant for, and it keeps one code
        // path for both drivers.
        self::dispatch($result['last']);
    }

    public function failed(?\Throwable $exception): void
    {
        app(PolicyCacheRebuilder::class)->markFailed(
            $exception ? substr($exception->getMessage(), 0, 500) : 'The rebuild job failed.'
        );
    }
}
