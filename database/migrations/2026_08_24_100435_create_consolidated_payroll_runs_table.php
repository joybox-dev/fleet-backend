<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company-wide monthly payroll approval.
     *
     * Until a month is approved here the consolidated sheet is a projection: traffic
     * fines and salary-advance instalments are shown as pending and are NOT taken off
     * the driver's net. Approving freezes the sheet and is the single point at which
     * those company-level deductions are actually committed.
     */
    public function up(): void
    {
        Schema::create('consolidated_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->integer('year');
            $table->integer('month');
            $table->string('status', 20)->default('approved');
            $table->integer('total_drivers')->default(0);
            $table->integer('total_orders')->default(0);
            $table->decimal('total_gross_earnings', 12, 3)->default(0);
            $table->decimal('total_violations_deductions', 12, 3)->default(0);
            $table->decimal('total_advances_deductions', 12, 3)->default(0);
            $table->decimal('total_manual_adjustments', 12, 3)->default(0);
            $table->decimal('total_final_net_payout', 12, 3)->default(0);
            $table->longText('snapshot_data')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'year', 'month'], 'consolidated_payroll_runs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidated_payroll_runs');
    }
};
