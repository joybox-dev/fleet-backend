<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money actually handed to a driver against an approved consolidated month.
     *
     * Approval fixes what a driver is owed; nothing recorded what he was then given, so the system
     * could not say whether a month had been paid, and a driver's balance could only be projected.
     * Each row is one payment on one day — bank transfer, cash, or both — and a month may carry
     * several. Deleting a run with payments on it is refused at the database as well as in the
     * controller: a record of money that left the company must not vanish with a reopened month.
     */
    public function up(): void
    {
        Schema::create('payroll_disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('consolidated_run_id')
                ->constrained('consolidated_payroll_runs')
                ->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->decimal('bank_amount', 12, 3)->default(0);
            $table->decimal('cash_amount', 12, 3)->default(0);
            $table->date('paid_at');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['consolidated_run_id', 'employee_id'], 'payroll_disbursements_run_employee_idx');
            $table->index(['employee_id', 'paid_at'], 'payroll_disbursements_employee_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_disbursements');
    }
};
