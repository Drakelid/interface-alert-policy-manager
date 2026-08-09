<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Command;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class MigrationRoundTripTest extends IntegrationTestCase
{
    public function test_latest_additive_migration_round_trips(): void
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_08_09_000001_add_notification_outbox_and_episode_identity.php';
        $migration->down();
        self::assertFalse(Schema::hasTable('iapm_notification_outbox'));
        self::assertFalse(Schema::hasColumn('iapm_incidents', 'episode_uuid'));
        $migration->up();
        self::assertTrue(Schema::hasTable('iapm_notification_outbox'));
        self::assertTrue(Schema::hasColumn('iapm_incidents', 'episode_uuid'));
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
