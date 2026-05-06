<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();

            // Earnings — from meeting: الراتب الأساسي + بونصات + بدلات
            $table->decimal('base_official', 8, 3)->default(0);   // Ministry-registered salary
            $table->decimal('base_actual', 8, 3)->default(0);     // Full actual salary
            $table->decimal('orders_bonus', 8, 3)->default(0);    // Bonus from order count
            $table->decimal('fuel_allowance', 8, 3)->default(0);  // Fuel allowance
            $table->decimal('other_bonuses', 8, 3)->default(0);   // Other allowances
            $table->unsignedInteger('total_orders')->default(0);  // Orders delivered this month

            // Deductions — from meeting employee ledger
            $table->decimal('violations_deduction', 8, 3)->default(0);
            $table->decimal('maintenance_deduction', 8, 3)->default(0);
            $table->decimal('custody_deduction', 8, 3)->default(0);
            $table->decimal('other_deductions', 8, 3)->default(0);

            // Totals
            $table->decimal('gross_official', 8, 3)->default(0);  // Official total → bank
            $table->decimal('gross_actual', 8, 3)->default(0);    // Actual total → full payment
            $table->decimal('cash_portion', 8, 3)->default(0);    // gross_actual - gross_official → cash

            // ERPNext sync — official salary only → Salary Slip
            $table->string('erp_id')->nullable()->index();
            $table->timestamp('erp_synced_at')->nullable();
            $table->enum('erp_sync_status', ['pending', 'synced', 'failed'])->default('pending');

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_slips');
    }
};
