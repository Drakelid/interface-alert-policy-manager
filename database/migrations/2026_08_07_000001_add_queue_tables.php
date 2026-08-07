<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provisions Laravel's database queue backing tables so queued dispatch works out
 * of the box (the default on install). Guarded with hasTable so it never clashes
 * with a jobs/failed_jobs table LibreNMS or another component may already provide.
 * If you point IAPM at Redis (IAPM_QUEUE_CONNECTION=redis) these simply go unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->string('queue')->index();
                $t->longText('payload');
                $t->unsignedTinyInteger('attempts');
                $t->unsignedInteger('reserved_at')->nullable();
                $t->unsignedInteger('available_at');
                $t->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $t): void {
                $t->id();
                $t->string('uuid')->unique();
                $t->text('connection');
                $t->text('queue');
                $t->longText('payload');
                $t->longText('exception');
                $t->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        // Intentionally left as a no-op: jobs/failed_jobs are shared Laravel
        // infrastructure that other components may rely on, so we never drop them.
    }
};
