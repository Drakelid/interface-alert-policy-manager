<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports the device-digest query, which filters open incidents by state and a
 * triggered_at window before grouping them per device. Without this the digest
 * pre-pass falls back to the broader (state, last_seen_at) index and scans more
 * rows than necessary during a large simultaneous outage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iapm_incidents', function (Blueprint $t): void {
            $t->index(['state', 'triggered_at'], 'iapm_incident_digest_idx');
        });
    }

    public function down(): void
    {
        Schema::table('iapm_incidents', function (Blueprint $t): void {
            $t->dropIndex('iapm_incident_digest_idx');
        });
    }
};
