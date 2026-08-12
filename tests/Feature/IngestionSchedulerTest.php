<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class IngestionSchedulerTest extends IntegrationTestCase
{
    public function test_durable_ingestion_drainers_are_attached_to_the_scheduler_process(): void
    {
        config([
            'iapm.ingestion.inbox_workers' => 2,
            'iapm.ingestion.inbox_batch_per_worker' => 3,
        ]);
        $this->app->forgetInstance(Schedule::class);

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, 'iapm:drain-ingestion'))
            ->values();

        self::assertCount(2, $events);
        foreach ($events as $index => $event) {
            self::assertStringContainsString('--worker='.($index + 1), (string) $event->command);
            self::assertStringContainsString('--limit=3', (string) $event->command);
            self::assertFalse(
                $event->runInBackground,
                'A detached ingestion drainer can be killed when a service-managed schedule:run process exits.'
            );
            self::assertSame('* * * * *', $event->expression);
        }
    }
}
