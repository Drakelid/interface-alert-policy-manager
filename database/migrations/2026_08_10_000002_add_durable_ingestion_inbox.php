<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('iapm_ingestion_inbox')) {
            Schema::create('iapm_ingestion_inbox', function (Blueprint $table): void {
                $table->id();
                $table->char('idempotency_key', 64)->unique();
                $table->unsignedBigInteger('device_id');
                $table->unsignedInteger('fault_count');
                $table->longText('payload_encrypted');
                $table->string('status', 24)->default('pending');
                $table->unsignedInteger('attempt_count')->default(0);
                $table->dateTime('available_at')->nullable();
                $table->dateTime('claimed_at')->nullable();
                $table->dateTime('processed_at')->nullable();
                $table->string('last_error_redacted', 512)->nullable();
                $table->timestamps();
            });
        } else {
            $this->addMissingColumns();
        }

        $this->addMissingIndexes();
    }

    public function down(): void
    {
        Schema::dropIfExists('iapm_ingestion_inbox');
    }

    /**
     * MariaDB implicitly commits DDL. Add each absent column separately so a
     * migration interrupted between ALTER statements can safely be rerun.
     */
    private function addMissingColumns(): void
    {
        $columns = [
            'id' => fn (Blueprint $table) => $table->id(),
            'idempotency_key' => fn (Blueprint $table) => $table->char('idempotency_key', 64)->unique(),
            'device_id' => fn (Blueprint $table) => $table->unsignedBigInteger('device_id'),
            'fault_count' => fn (Blueprint $table) => $table->unsignedInteger('fault_count'),
            'payload_encrypted' => fn (Blueprint $table) => $table->longText('payload_encrypted'),
            'status' => fn (Blueprint $table) => $table->string('status', 24)->default('pending'),
            'attempt_count' => fn (Blueprint $table) => $table->unsignedInteger('attempt_count')->default(0),
            'available_at' => fn (Blueprint $table) => $table->dateTime('available_at')->nullable(),
            'claimed_at' => fn (Blueprint $table) => $table->dateTime('claimed_at')->nullable(),
            'processed_at' => fn (Blueprint $table) => $table->dateTime('processed_at')->nullable(),
            'last_error_redacted' => fn (Blueprint $table) => $table->string('last_error_redacted', 512)->nullable(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];

        foreach ($columns as $name => $definition) {
            if (! Schema::hasColumn('iapm_ingestion_inbox', $name)) {
                Schema::table('iapm_ingestion_inbox', $definition);
            }
        }
    }

    private function addMissingIndexes(): void
    {
        $existing = collect(Schema::getIndexes('iapm_ingestion_inbox'))
            ->pluck('name')
            ->filter()
            ->all();

        Schema::table('iapm_ingestion_inbox', function (Blueprint $table) use ($existing): void {
            if (! in_array('iapm_ingestion_inbox_idempotency_key_unique', $existing, true)) {
                $table->unique('idempotency_key', 'iapm_ingestion_inbox_idempotency_key_unique');
            }
            if (! in_array('iapm_inbox_due_idx', $existing, true)) {
                $table->index(['status', 'available_at', 'id'], 'iapm_inbox_due_idx');
            }
            if (! in_array('iapm_inbox_claim_idx', $existing, true)) {
                $table->index(['status', 'claimed_at'], 'iapm_inbox_claim_idx');
            }
            if (! in_array('iapm_inbox_device_time_idx', $existing, true)) {
                $table->index(['device_id', 'created_at'], 'iapm_inbox_device_time_idx');
            }
        });
    }
};
