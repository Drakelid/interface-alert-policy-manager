<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\IngestionInbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class IngestionFifoTest extends IntegrationTestCase
{
    public function test_an_active_claim_blocks_later_same_device_rows_but_not_another_device(): void
    {
        config(['iapm.ingestion.async_threshold' => 1, 'iapm.ingestion.async_recovery' => true]);
        $this->defaultPolicy();
        $device = $this->device();
        $active = $this->alertPayload($device, [$this->fault($this->downPort($device))]);
        unset($active['timestamp']);
        $this->ingest($active)->assertStatus(202);
        $this->ingest(array_replace($active, ['state' => 0, 'faults' => []]))->assertStatus(202);
        $this->ingest($active)->assertStatus(202);
        $first = IngestionInbox::oldest('id')->firstOrFail();
        $first->update(['status' => 'processing', 'claimed_at' => now()]);
        $otherDevice = $this->device();
        $this->ingest($this->alertPayload($otherDevice, [$this->fault($this->downPort($otherDevice))]))->assertStatus(202);

        $this->artisan('iapm:drain-ingestion', ['--limit' => 10])->assertExitCode(0);

        self::assertSame('processing', $first->fresh()->status);
        self::assertSame(2, IngestionInbox::where('device_id', $device->device_id)->where('status', 'pending')->count());
        self::assertSame('processed', IngestionInbox::where('device_id', $otherDevice->device_id)->sole()->status);
        self::assertSame(0, Incident::where('device_id', $device->device_id)->count());
    }

    public function test_a_failed_predecessor_keeps_fifo_order_through_backoff_and_retry(): void
    {
        config(['iapm.ingestion.async_threshold' => 1, 'iapm.ingestion.async_recovery' => true]);
        $this->defaultPolicy();
        $device = $this->device();
        $active = $this->alertPayload($device, [$this->fault($this->downPort($device))]);
        unset($active['timestamp']);
        $this->ingest($active)->assertStatus(202);
        $this->ingest(array_replace($active, ['state' => 0, 'faults' => []]))->assertStatus(202);
        $this->ingest($active)->assertStatus(202);
        $first = IngestionInbox::oldest('id')->firstOrFail();
        $first->update(['status' => 'failed', 'available_at' => now()->addMinute()]);

        $this->artisan('iapm:drain-ingestion', ['--limit' => 3])->assertExitCode(0);
        self::assertSame(2, IngestionInbox::where('status', 'pending')->count());
        self::assertSame(0, Incident::count());
        $this->travel(61)->seconds();
        $this->artisan('iapm:drain-ingestion', ['--limit' => 3])->assertExitCode(0);

        self::assertSame(3, IngestionInbox::where('status', 'processed')->count());
        self::assertSame(IncidentState::Active, Incident::sole()->state);
        self::assertSame(1, Incident::sole()->events()->where('event_type', 'reopened')->count());
        self::assertSame(1, Incident::sole()->events()->where('event_type', 'recovered')->count());
    }

    public function test_a_dead_predecessor_does_not_block_later_observations(): void
    {
        config(['iapm.ingestion.async_threshold' => 1, 'iapm.ingestion.async_recovery' => true]);
        $this->defaultPolicy();
        $device = $this->device();
        $active = $this->alertPayload($device, [$this->fault($this->downPort($device))]);
        unset($active['timestamp']);
        $this->ingest($active)->assertStatus(202);
        $this->ingest(array_replace($active, ['state' => 0, 'faults' => []]))->assertStatus(202);
        $this->ingest($active)->assertStatus(202);
        IngestionInbox::oldest('id')->firstOrFail()->update(['status' => 'dead', 'available_at' => null]);

        $this->artisan('iapm:drain-ingestion', ['--limit' => 3])->assertExitCode(0);

        self::assertSame(1, IngestionInbox::where('status', 'dead')->count());
        self::assertSame(2, IngestionInbox::where('status', 'processed')->count());
        self::assertSame(IncidentState::Active, Incident::sole()->state);
    }
}
