<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contract_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->integer('year');
            $table->integer('month');
            $table->string('status', 20)->default('approved');
            $table->integer('total_drivers')->default(0);
            $table->integer('total_orders')->default(0);
            $table->decimal('total_gross_earnings', 12, 3)->default(0);
            $table->decimal('total_violations_deductions', 12, 3)->default(0);
            $table->decimal('total_net_payout', 12, 3)->default(0);
            $table->longText('snapshot_data')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'contract_id', 'year', 'month'], 'contract_payroll_runs_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_payroll_runs');
    }
};
