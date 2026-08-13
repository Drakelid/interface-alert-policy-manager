<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('iapm_simulations')) {
            return;
        }

        if (! Schema::hasColumn('iapm_simulations', 'simulated_admin_status')) {
            Schema::table('iapm_simulations', function (Blueprint $table): void {
                $table->string('simulated_admin_status', 32)->default('up')->after('original_oper_status');
            });
        }
        if (! Schema::hasColumn('iapm_simulations', 'simulated_oper_status')) {
            Schema::table('iapm_simulations', function (Blueprint $table): void {
                $table->string('simulated_oper_status', 32)->default('down')->after('simulated_admin_status');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('iapm_simulations')) {
            return;
        }

        foreach (['simulated_oper_status', 'simulated_admin_status'] as $column) {
            if (Schema::hasColumn('iapm_simulations', $column)) {
                Schema::table('iapm_simulations', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
