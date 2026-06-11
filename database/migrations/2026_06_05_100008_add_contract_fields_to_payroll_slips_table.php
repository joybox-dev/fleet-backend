<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_slips', function (Blueprint $table) {
            $table->string('final_monthly_status')->default('valid')->after('cash_portion'); // valid, invalid, protected_exception
            $table->text('status_override_reason')->nullable()->after('final_monthly_status');
            
            $table->decimal('total_contract_bonuses', 10, 3)->default(0.000)->after('status_override_reason');
            $table->decimal('total_capacity_incentive', 10, 3)->default(0.000)->after('total_contract_bonuses');
            $table->decimal('total_experience_incentive', 10, 3)->default(0.000)->after('total_capacity_incentive');
            $table->decimal('exchange_rate', 10, 6)->default(1.000000)->after('total_experience_incentive');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_slips', function (Blueprint $table) {
            $table->dropColumn([
                'final_monthly_status',
                'status_override_reason',
                'total_contract_bonuses',
                'total_capacity_incentive',
                'total_experience_incentive',
                'exchange_rate'
            ]);
        });
    }
};
