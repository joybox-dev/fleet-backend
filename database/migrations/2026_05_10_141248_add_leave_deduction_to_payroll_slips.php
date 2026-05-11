<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_slips', function (Blueprint $table) {
            $table->decimal('leave_deduction', 8, 3)->default(0)->after('custody_deduction');
            $table->unsignedSmallInteger('unpaid_leave_days')->default(0)->after('leave_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_slips', function (Blueprint $table) {
            $table->dropColumn(['leave_deduction', 'unpaid_leave_days']);
        });
    }
};
