<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Command;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class IngestionFifoMigrationTest extends IntegrationTestCase
{
    public function test_the_fifo_index_is_additive_reversible_and_safe_to_repeat(): void
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_20_000002_add_ingestion_fifo_index.php';
        try {
            $migration->up();
            $migration->up();
            $index = collect(Schema::getIndexes('iapm_ingestion_inbox'))->firstWhere('name', 'iapm_inbox_device_fifo_idx');
            self::assertSame(['device_id', 'status', 'id'], $index['columns']);
            $migration->down();
            $migration->down();
            self::assertFalse(Schema::hasIndex('iapm_ingestion_inbox', 'iapm_inbox_device_fifo_idx'));
        } finally {
            $migration->up();
            $connection = DB::connection();
            if (! $connection->getPdo()->inTransaction()) {
                // MariaDB DDL commits the PDO transaction. Reset Laravel's
                // tracked transaction depth before RefreshDatabase teardown.
                $connection->setPdo($connection->getPdo());
                $connection->beginTransaction();
            }
        }
    }
}
