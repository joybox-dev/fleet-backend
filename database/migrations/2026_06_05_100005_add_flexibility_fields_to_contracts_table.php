<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Mandatory fields (with defaults/nullability to prevent breaking existing data)
            $table->string('client_name')->nullable()->after('name');
            $table->string('status')->default('active')->after('client_name'); // active, suspended, ended
            $table->string('currency')->default('KWD')->after('status'); // KWD, SAR, QAR

            // Customization fields
            $table->decimal('default_order_commission', 10, 3)->nullable()->after('currency');
            $table->decimal('default_hourly_rate', 10, 3)->nullable()->after('default_order_commission');
            $table->string('default_work_hours_source')->default('manual')->after('default_hourly_rate'); // manual, timesheet, keeta_report
            $table->decimal('default_fixed_salary', 10, 3)->nullable()->after('default_work_hours_source');
            $table->integer('default_absence_divisor')->default(26)->after('default_fixed_salary');
            $table->integer('default_monthly_target')->nullable()->after('default_absence_divisor');
            $table->integer('default_daily_target')->nullable()->after('default_monthly_target');
            
            $table->integer('required_drivers_count')->nullable()->after('default_daily_target');
            $table->integer('required_vehicles_count')->nullable()->after('required_drivers_count');
            
            // expected_monthly_revenue already exists in 2026_05_28_164525
            $table->decimal('expected_monthly_expenses', 10, 3)->nullable()->after('required_vehicles_count');
            $table->decimal('target_profit_margin', 5, 2)->nullable()->after('expected_monthly_expenses');
            
            $table->integer('default_required_valid_days')->nullable()->after('target_profit_margin');

            // Discrepancy thresholds
            $table->string('threshold_type')->nullable()->after('default_required_valid_days'); // percentage, fixed_count, both
            $table->decimal('minor_threshold_limit', 8, 2)->nullable()->after('threshold_type');
            $table->decimal('major_threshold_limit', 8, 2)->nullable()->after('minor_threshold_limit');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'client_name',
                'status',
                'currency',
                'default_order_commission',
                'default_hourly_rate',
                'default_work_hours_source',
                'default_fixed_salary',
                'default_absence_divisor',
                'default_monthly_target',
                'default_daily_target',
                'required_drivers_count',
                'required_vehicles_count',
                'expected_monthly_expenses',
                'target_profit_margin',
                'default_required_valid_days',
                'threshold_type',
                'minor_threshold_limit',
                'major_threshold_limit'
            ]);
        });
    }
};
