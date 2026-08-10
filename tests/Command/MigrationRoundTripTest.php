<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Command;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class MigrationRoundTripTest extends IntegrationTestCase
{
    public function test_latest_additive_migration_round_trips(): void
    {
        $indexes = require dirname(__DIR__, 2).'/database/migrations/2026_08_10_000001_add_storm_path_indexes.php';
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_08_09_000001_add_notification_outbox_and_episode_identity.php';
        $indexes->down();
        $migration->down();
        self::assertFalse(Schema::hasTable('iapm_notification_outbox'));
        self::assertFalse(Schema::hasColumn('iapm_incidents', 'episode_uuid'));
        $migration->up();
        $indexes->up();
        self::assertTrue(Schema::hasTable('iapm_notification_outbox'));
        self::assertTrue(Schema::hasColumn('iapm_incidents', 'episode_uuid'));
        $this->restoreTransactionAfterDdl();
    }

    public function test_the_complete_plugin_migration_chain_round_trips_without_dropping_shared_queue_tables(): void
    {
        $paths = glob(dirname(__DIR__, 2).'/database/migrations/*.php');
        sort($paths);
        $sharedQueueTables = [
            'jobs' => Schema::hasTable('jobs'),
            'failed_jobs' => Schema::hasTable('failed_jobs'),
        ];

        foreach (array_reverse($paths) as $path) {
            (require $path)->down();
        }

        self::assertFalse(Schema::hasTable('iapm_incidents'));
        self::assertFalse(Schema::hasTable('iapm_notification_outbox'));
        self::assertFalse(Schema::hasTable('iapm_outages'));
        self::assertSame($sharedQueueTables['jobs'], Schema::hasTable('jobs'));
        self::assertSame($sharedQueueTables['failed_jobs'], Schema::hasTable('failed_jobs'));

        foreach ($paths as $path) {
            (require $path)->up();
        }

        self::assertTrue(Schema::hasTable('iapm_incidents'));
        self::assertTrue(Schema::hasTable('iapm_notification_outbox'));
        self::assertTrue(Schema::hasTable('iapm_notification_outbox_incidents'));
        self::assertTrue(Schema::hasTable('iapm_ingestion_inbox'));
        self::assertTrue(Schema::hasTable('iapm_outages'));
        self::assertTrue(Schema::hasColumn('iapm_incidents', 'episode_uuid'));
        self::assertTrue(Schema::hasColumn('iapm_incidents', 'pre_acknowledgement_state'));
        self::assertTrue(collect(Schema::getIndexes('iapm_incidents'))->contains(fn (array $index) => $index['name'] === 'iapm_incident_state_id_idx'));
        self::assertTrue(collect(Schema::getIndexes('iapm_notification_outbox'))->contains(fn (array $index) => $index['name'] === 'iapm_outbox_status_available_idx'));
        self::assertTrue(collect(Schema::getIndexes('iapm_notification_outbox'))->contains(fn (array $index) => $index['name'] === 'iapm_outbox_status_claimed_idx'));
        self::assertTrue(Schema::hasColumn('iapm_notification_outbox', 'finalized_at'));
        $this->restoreTransactionAfterDdl();
    }

    public function test_interrupted_inbox_and_index_migrations_resume_safely(): void
    {
        $inbox = require dirname(__DIR__, 2).'/database/migrations/2026_08_10_000002_add_durable_ingestion_inbox.php';
        $indexes = require dirname(__DIR__, 2).'/database/migrations/2026_08_10_000001_add_storm_path_indexes.php';

        $inbox->down();
        Schema::create('iapm_ingestion_inbox', function (Blueprint $table): void {
            $table->id();
            $table->char('idempotency_key', 64);
            $table->unsignedBigInteger('device_id');
        });

        $inbox->up();
        $inbox->up();
        $indexes->up();
        $indexes->up();

        self::assertTrue(Schema::hasColumn('iapm_ingestion_inbox', 'payload_encrypted'));
        self::assertTrue(Schema::hasColumn('iapm_ingestion_inbox', 'processed_at'));
        self::assertTrue(collect(Schema::getIndexes('iapm_ingestion_inbox'))->contains(fn (array $index) => $index['name'] === 'iapm_ingestion_inbox_idempotency_key_unique'));
        self::assertTrue(collect(Schema::getIndexes('iapm_ingestion_inbox'))->contains(fn (array $index) => $index['name'] === 'iapm_inbox_due_idx'));
        self::assertTrue(collect(Schema::getIndexes('iapm_notification_outbox'))->contains(fn (array $index) => $index['name'] === 'iapm_outbox_status_claimed_idx'));
        $this->restoreTransactionAfterDdl();
    }

    private function restoreTransactionAfterDdl(): void
    {
        $connection = DB::connection();
        if (! $connection->getPdo()->inTransaction()) {
            // MariaDB DDL commits the PDO transaction while Laravel still tracks
            // its savepoint depth. Re-attaching the same PDO resets that counter
            // before RefreshDatabase's teardown callback rolls the test back.
            $connection->setPdo($connection->getPdo());
            $connection->beginTransaction();
        }
    }
}
