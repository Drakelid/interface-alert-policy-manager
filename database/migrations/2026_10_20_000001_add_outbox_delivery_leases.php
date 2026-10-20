<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['claim_token', 'queued_at', 'action_required', 'forced'] as $column) {
            if (Schema::hasColumn('iapm_notification_outbox', $column)) {
                continue;
            }
            Schema::table('iapm_notification_outbox', function (Blueprint $table) use ($column): void {
                match ($column) {
                    'claim_token' => $table->uuid($column)->nullable(),
                    'queued_at' => $table->dateTime($column)->nullable(),
                    'action_required' => $table->boolean($column)->default(true),
                    'forced' => $table->boolean($column)->default(false),
                };
            });
        }
        if (! Schema::hasIndex('iapm_notification_outbox', 'iapm_outbox_publication_lease_idx')) {
            Schema::table('iapm_notification_outbox', function (Blueprint $table): void {
                $table->index(['status', 'queued_at'], 'iapm_outbox_publication_lease_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('iapm_notification_outbox', 'iapm_outbox_publication_lease_idx')) {
            Schema::table('iapm_notification_outbox', function (Blueprint $table): void {
                $table->dropIndex('iapm_outbox_publication_lease_idx');
            });
        }
        $columns = array_values(array_filter(['claim_token', 'queued_at', 'action_required', 'forced'], fn (string $column): bool => Schema::hasColumn('iapm_notification_outbox', $column)));
        if ($columns !== []) {
            Schema::table('iapm_notification_outbox', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
