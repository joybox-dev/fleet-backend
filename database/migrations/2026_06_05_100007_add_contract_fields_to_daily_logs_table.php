<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_logs', function (Blueprint $table) {
            $table->boolean('shift_valid')->default(true)->after('income_amount');
            $table->decimal('online_hours', 5, 2)->nullable()->after('shift_valid');
            $table->decimal('ontime_rate', 5, 2)->nullable()->after('online_hours');
            $table->integer('avg_delivery_time')->nullable()->after('ontime_rate');
        });
    }

    public function down(): void
    {
        Schema::table('daily_logs', function (Blueprint $table) {
            $table->dropColumn([
                'shift_valid',
                'online_hours',
                'ontime_rate',
                'avg_delivery_time'
            ]);
        });
    }
};
