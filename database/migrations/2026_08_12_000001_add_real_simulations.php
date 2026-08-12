<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('iapm_simulations')) {
            Schema::create('iapm_simulations', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('device_id');
                $table->unsignedBigInteger('port_id');
                $table->string('status', 24)->default('running');
                $table->string('original_admin_status', 32);
                $table->string('original_oper_status', 32);
                $table->unsignedInteger('duration_seconds');
                $table->boolean('send_notifications')->default(true);
                $table->unsignedBigInteger('incident_id')->nullable();
                $table->uuid('episode_uuid')->nullable();
                $table->dateTime('started_at');
                $table->dateTime('recover_at');
                $table->dateTime('recovered_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->index(['status', 'recover_at'], 'iapm_simulations_due_idx');
                $table->index(['port_id', 'status'], 'iapm_simulations_port_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('iapm_simulations') && Schema::hasTable('ports')) {
            DB::table('iapm_simulations')->whereIn('status', ['starting', 'running', 'recovering'])
                ->orderBy('id')->chunkById(100, function ($simulations): void {
                    foreach ($simulations as $simulation) {
                        DB::table('ports')->where('port_id', $simulation->port_id)->update([
                            'ifAdminStatus' => $simulation->original_admin_status,
                            'ifOperStatus' => $simulation->original_oper_status,
                        ]);
                    }
                });
        }
        Schema::dropIfExists('iapm_simulations');
    }
};
