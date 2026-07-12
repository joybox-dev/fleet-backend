<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'is_locked',
                'default_fixed_salary',
                'default_hourly_rate',
                'default_work_hours_source',
                'threshold_type',
                'required_drivers_count',
                'minor_threshold_limit',
                'major_threshold_limit'
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false);
            $table->decimal('default_fixed_salary', 10, 3)->nullable();
            $table->decimal('default_hourly_rate', 10, 3)->nullable();
            $table->string('default_work_hours_source')->default('manual');
            $table->string('threshold_type')->nullable();
            $table->integer('required_drivers_count')->nullable();
            $table->decimal('minor_threshold_limit', 10, 2)->nullable();
            $table->decimal('major_threshold_limit', 10, 2)->nullable();
        });
    }
};
