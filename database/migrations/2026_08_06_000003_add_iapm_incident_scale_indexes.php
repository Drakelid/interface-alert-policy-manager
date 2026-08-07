<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two scale indexes for very large installs (500k+ interfaces, millions of
 * retained recovered incidents):
 *
 * - (state, id): the every-minute reconcile and process-actions passes iterate the
 *   OPEN incidents via chunkById (WHERE state IN (...) AND id > ? ORDER BY id). Without
 *   a state-leading index that also carries id, the optimizer walks the primary key
 *   (all rows, mostly recovered) to find the few open ones — O(total). This makes each
 *   chunk seek open rows directly — O(open).
 * - last_seen_at: the Overview "recent incidents" list orders by last_seen_at DESC
 *   LIMIT n; without this it filesorts the whole table on every page load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iapm_incidents', function (Blueprint $t): void {
            $t->index(['state', 'id'], 'iapm_incident_state_id_idx');
            $t->index('last_seen_at', 'iapm_incident_last_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::table('iapm_incidents', function (Blueprint $t): void {
            $t->dropIndex('iapm_incident_state_id_idx');
            $t->dropIndex('iapm_incident_last_seen_idx');
        });
    }
};
