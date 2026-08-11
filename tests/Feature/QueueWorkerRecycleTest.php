<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * A scheduler-managed worker that dies without running schedule:finish (OOM
 * kill, container stop, SIGKILL) leaves its withoutOverlapping lock held, and
 * nothing replaces it until that lock expires. The shipped v1.4.0 values were
 * --max-time=3600 with a 70-minute lock, so an operator could lose all IAPM
 * delivery for over an hour with no self-healing. These tests pin the two
 * competing bounds on that lock.
 */
class QueueWorkerRecycleTest extends IntegrationTestCase
{
    /** Lock expiry plus the one scheduler tick needed to notice it. */
    private const MAX_ACCEPTABLE_RESPAWN_MINUTES = 10;

    /** @return list<Event> */
    private function workerEvents(int $workers = 3): array
    {
        // setUp() defaults delivery to synchronous, and the scheduler only manages
        // workers for queued dispatch. Re-resolve the Schedule under the production
        // configuration so these assertions cover the registration logic rather
        // than whatever state the app happened to boot with.
        $this->settings->put('dispatch_mode', 'queue');
        config(['iapm.queue.workers' => $workers]);
        $this->app->forgetInstance(Schedule::class);

        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn (Event $event) => str_contains((string) $event->command, 'queue:work')
                && str_contains((string) $event->command, 'iapm-')
        ));

        self::assertCount($workers, $events, 'The scheduler did not register one queue:work event per configured worker.');

        return $events;
    }

    private function maxTimeOf(Event $event): int
    {
        self::assertSame(1, preg_match('/--max-time[= ](\d+)/', (string) $event->command, $m), "No --max-time in: {$event->command}");

        return (int) $m[1];
    }

    public function test_a_killed_worker_is_replaced_in_under_ten_minutes(): void
    {
        foreach ($this->workerEvents() as $event) {
            $respawn = $event->expiresAt + 1;
            self::assertLessThanOrEqual(
                self::MAX_ACCEPTABLE_RESPAWN_MINUTES,
                $respawn,
                "A worker killed without releasing its lock would stay unreplaced for {$respawn} minutes: {$event->command}"
            );
        }
    }

    public function test_the_overlap_lock_outlives_the_worker_it_guards(): void
    {
        // The opposite failure: a lock shorter than the worker's lifetime expires
        // while the worker is still alive, so every later tick starts another
        // worker under the same name and the process count grows without bound.
        $jobTimeout = (int) config('iapm.queue.timeout', 60);

        foreach ($this->workerEvents() as $event) {
            // --max-time only stops the worker taking new jobs; one in-flight job
            // may still run for the full job timeout after that.
            $worstCaseLifetime = $this->maxTimeOf($event) + $jobTimeout;
            self::assertGreaterThan(
                $worstCaseLifetime,
                $event->expiresAt * 60,
                "Lock of {$event->expiresAt}m expires before the worker can finish ({$worstCaseLifetime}s): {$event->command}"
            );
        }
    }

    public function test_workers_do_not_all_recycle_on_the_same_tick(): void
    {
        // Unstaggered lifetimes mean every worker exits together and the queue is
        // unattended until the next tick.
        $maxTimes = array_map(fn (Event $event) => $this->maxTimeOf($event), $this->workerEvents());
        self::assertSame(
            count($maxTimes),
            count(array_unique($maxTimes)),
            'Workers share a --max-time, so they exit on the same tick: '.implode(',', $maxTimes)
        );

        sort($maxTimes);
        for ($i = 1; $i < count($maxTimes); $i++) {
            self::assertGreaterThanOrEqual(
                60,
                $maxTimes[$i] - $maxTimes[$i - 1],
                'Consecutive workers recycle within the same scheduler tick: '.implode(',', $maxTimes)
            );
        }
    }

    public function test_the_worker_lifetime_is_configurable(): void
    {
        self::assertSame(240, (int) config('iapm.queue.worker_max_seconds'));

        config(['iapm.queue.worker_max_seconds' => 120]);
        foreach ($this->workerEvents(1) as $event) {
            self::assertSame(120, $this->maxTimeOf($event));
        }
    }

    public function test_the_respawn_ceiling_survives_a_hostile_configuration(): void
    {
        // The guarantee has to be structural, not a property of the default. An
        // operator raising the lifetime for fewer restarts, or running many
        // workers (each staggered further out than the last), must not be able to
        // talk the lock back up into an hour-long outage.
        config(['iapm.queue.worker_max_seconds' => 86400]);

        foreach ($this->workerEvents(8) as $event) {
            $respawn = $event->expiresAt + 1;
            self::assertLessThanOrEqual(
                self::MAX_ACCEPTABLE_RESPAWN_MINUTES,
                $respawn,
                "Configuration pushed the unreplaced-worker window to {$respawn} minutes: {$event->command}"
            );
        }
    }

    public function test_a_long_job_timeout_still_bounds_the_lock_by_that_timeout(): void
    {
        // A worker cannot be replaced faster than one job can run, so the lock has
        // to cover the job timeout even when that pushes past the target. Pin the
        // relationship so the lock is never shortened below what the job needs.
        config(['iapm.queue.timeout' => 600, 'iapm.queue.worker_max_seconds' => 240]);

        foreach ($this->workerEvents(1) as $event) {
            self::assertGreaterThan(
                $this->maxTimeOf($event) + 600,
                $event->expiresAt * 60,
                "Lock does not cover a 600s job: {$event->command}"
            );
        }
    }
}
