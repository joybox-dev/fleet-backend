<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->integer('target_orders_monthly')->nullable();
            $table->decimal('base_commission_rate', 8, 3)->nullable();
            $table->decimal('premium_commission_rate', 8, 3)->nullable();
        });

        Schema::table('daily_logs', function (Blueprint $table) {
            $table->decimal('driver_commission', 8, 3)->default(0.000);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'target_orders_monthly',
                'base_commission_rate',
                'premium_commission_rate'
            ]);
        });

        Schema::table('daily_logs', function (Blueprint $table) {
            $table->dropColumn('driver_commission');
        });
    }
};
