<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Advance instalments could previously only be recorded against a PayrollSlip, which
     * exists solely on the legacy payroll path. Approving a consolidated month must be able
     * to record them too, so the slip link becomes optional and a consolidated run link is
     * added alongside it. Exactly one of the two is set on any given row.
     */
    public function up(): void
    {
        Schema::table('advance_deductions', function (Blueprint $table) {
            $table->foreignId('payroll_slip_id')->nullable()->change();
            $table->foreignId('consolidated_run_id')
                ->nullable()
                ->after('payroll_slip_id')
                ->constrained('consolidated_payroll_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('advance_deductions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consolidated_run_id');
            $table->foreignId('payroll_slip_id')->nullable(false)->change();
        });
    }
};
