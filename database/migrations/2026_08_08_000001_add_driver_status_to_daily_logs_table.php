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
        if (!Schema::hasColumn('daily_logs', 'driver_status')) {
            Schema::table('daily_logs', function (Blueprint $table) {
                $table->string('driver_status', 50)->default('working')->after('is_valid');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('daily_logs', 'driver_status')) {
            Schema::table('daily_logs', function (Blueprint $table) {
                $table->dropColumn('driver_status');
            });
        }
    }
};
