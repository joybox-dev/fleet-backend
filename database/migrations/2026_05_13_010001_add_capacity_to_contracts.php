<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedInteger('required_drivers')->nullable()->after('notes');
            $table->unsignedInteger('daily_target')->nullable()->after('required_drivers');
            $table->unsignedInteger('monthly_target')->nullable()->after('daily_target');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['required_drivers', 'daily_target', 'monthly_target']);
        });
    }
};
