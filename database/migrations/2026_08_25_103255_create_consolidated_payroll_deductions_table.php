<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A ledger of what each approved consolidated month actually collected, and from where.
     *
     * The alternative — an `is_deducted` boolean on every source table — is what let the same
     * traffic fine be charged twice, and it is why maintenance and custody charges (which have
     * no such column) are re-collected every single month by the legacy payroll path. One
     * ledger answers "has this already been taken, and by which run" for every source at once,
     * and makes unapproving exact instead of best-effort.
     */
    public function up(): void
    {
        Schema::create('consolidated_payroll_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('consolidated_run_id')
                ->constrained('consolidated_payroll_runs')
                ->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // violation | maintenance | custody | leave | driver_expense | advance
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();

            $table->decimal('amount', 12, 3);
            $table->string('label')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id'], 'cpd_source_idx');
            $table->index(['consolidated_run_id', 'employee_id'], 'cpd_run_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidated_payroll_deductions');
    }
};
