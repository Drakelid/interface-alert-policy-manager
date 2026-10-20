<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('iapm_ingestion_inbox', 'iapm_inbox_device_fifo_idx')) {
            Schema::table('iapm_ingestion_inbox', function (Blueprint $table): void {
                $table->index(['device_id', 'status', 'id'], 'iapm_inbox_device_fifo_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('iapm_ingestion_inbox', 'iapm_inbox_device_fifo_idx')) {
            Schema::table('iapm_ingestion_inbox', function (Blueprint $table): void {
                $table->dropIndex('iapm_inbox_device_fifo_idx');
            });
        }
    }
};
