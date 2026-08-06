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
        if (!Schema::hasColumn('daily_logs', 'rejected_orders_count')) {
            Schema::table('daily_logs', function (Blueprint $table) {
                $table->integer('rejected_orders_count')->default(0)->after('orders_cash');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('daily_logs', 'rejected_orders_count')) {
            Schema::table('daily_logs', function (Blueprint $table) {
                $table->dropColumn('rejected_orders_count');
            });
        }
    }
};
