<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-5: `failed_poll_count` did not count polls.
 *
 * The field was labelled "Down observations" in the UI and its own help text
 * said explicitly that it does NOT count LibreNMS polls — reconciliation
 * increments it once a minute while the interface stays down. The column name
 * contradicted both, which is exactly the sort of thing that gets misconfigured
 * at 3am.
 *
 * A straight rename: iapm_policies holds tens of rows, so the lock is
 * negligible, and down() restores the old name for a full rollback. Rolling the
 * plugin code back without also rolling this migration back will break — the
 * same is true of any rename, and migrations are applied on upgrade anyway.
 *
 * Import accepts the old key so existing export documents keep working; see
 * ConfigurationImportValidator.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('iapm_policies', 'failed_poll_count') && ! Schema::hasColumn('iapm_policies', 'down_observations')) {
            Schema::table('iapm_policies', function (Blueprint $table): void {
                $table->renameColumn('failed_poll_count', 'down_observations');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('iapm_policies', 'down_observations') && ! Schema::hasColumn('iapm_policies', 'failed_poll_count')) {
            Schema::table('iapm_policies', function (Blueprint $table): void {
                $table->renameColumn('down_observations', 'failed_poll_count');
            });
        }
    }
};
