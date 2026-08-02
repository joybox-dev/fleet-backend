<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_slips', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_slips', 'driver_expense_deduction')) {
                $table->decimal('driver_expense_deduction', 8, 3)->default(0)->after('custody_deduction');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_slips', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_slips', 'driver_expense_deduction')) {
                $table->dropColumn('driver_expense_deduction');
            }
        });
    }
};
