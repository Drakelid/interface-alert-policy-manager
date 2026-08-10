<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production preflight for installations that have not yet run the v1.2.1
 * episode/outbox migration. That released migration performs one UPDATE per row;
 * this earlier, additive migration fills UUIDs in restartable set-based batches so
 * the released migration finds no null rows. On already-upgraded installations it
 * is a no-op. Its down() is intentionally empty because the owning 08-09 migration
 * removes these columns during a full rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('iapm_incidents', 'episode_uuid')) {
            Schema::table('iapm_incidents', function (Blueprint $table): void {
                $table->uuid('episode_uuid')->nullable()->after('incident_key');
            });
        }
        if (! Schema::hasColumn('iapm_incidents', 'pre_acknowledgement_state')) {
            Schema::table('iapm_incidents', function (Blueprint $table): void {
                $table->string('pre_acknowledgement_state', 20)->nullable()->after('state');
            });
        }
        $this->backfill('iapm_incidents');

        if (Schema::hasTable('iapm_outages')) {
            if (! Schema::hasColumn('iapm_outages', 'episode_uuid')) {
                Schema::table('iapm_outages', function (Blueprint $table): void {
                    $table->uuid('episode_uuid')->nullable()->after('incident_id');
                });
            }
            $this->backfill('iapm_outages');
        }
    }

    public function down(): void
    {
        // The released 2026_08_09 migration owns these columns and drops them.
    }

    private function backfill(string $table, int $batch = 5000): void
    {
        $cursor = 0;
        do {
            // Advance through the clustered primary key once. Repeated
            // `WHERE episode_uuid IS NULL LIMIT ...` updates have no useful index
            // yet and rescan the already-filled prefix on every batch.
            $ids = DB::table($table)
                ->where('id', '>', $cursor)
                ->whereNull('episode_uuid')
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            $lastId = (int) $ids->last();
            DB::table($table)
                ->where('id', '>', $cursor)
                ->where('id', '<=', $lastId)
                ->whereNull('episode_uuid')
                ->update(['episode_uuid' => DB::raw('UUID()')]);
            $cursor = $lastId;
        } while (true);
    }
};
