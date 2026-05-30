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
        Schema::table('contracts', function (Blueprint $table) {
            $table->integer('target_orders_monthly')->nullable()->default(400);
            $table->decimal('base_commission_rate', 8, 3)->nullable()->default(0.250);
            $table->decimal('premium_commission_rate', 8, 3)->nullable()->default(0.500);
            $table->decimal('expected_monthly_revenue', 12, 3)->nullable();
            $table->integer('target_driver_count')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'target_orders_monthly',
                'base_commission_rate',
                'premium_commission_rate',
                'expected_monthly_revenue',
                'target_driver_count'
            ]);
        });
    }
};
