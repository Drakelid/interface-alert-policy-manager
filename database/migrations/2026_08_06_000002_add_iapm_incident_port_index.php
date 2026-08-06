<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Interface Matrix looks incidents up by port_id (the per-row incident map and
 * the incident-state / active-incident / muted whereExists subqueries). The existing
 * (device_id, port_id, state) index leads with device_id, so a port_id-only lookup
 * can't use it — a full scan against a large incidents table on every matrix page.
 * This adds a port_id-leading index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iapm_incidents', function (Blueprint $t): void {
            $t->index(['port_id', 'state'], 'iapm_incident_port_state_idx');
            // Supports process-actions scoping recovered incidents to a recent window
            // instead of scanning a year of retained recoveries every minute.
            $t->index(['state', 'recovered_at'], 'iapm_incident_state_recovered_idx');
        });
    }

    public function down(): void
    {
        Schema::table('iapm_incidents', function (Blueprint $t): void {
            $t->dropIndex('iapm_incident_port_state_idx');
            $t->dropIndex('iapm_incident_state_recovered_idx');
        });
    }
};
