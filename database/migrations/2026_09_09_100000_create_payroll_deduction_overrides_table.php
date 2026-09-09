<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The owner's decision about one charge in one payroll month, made before the month is
     * approved: take it later (defer to a named month) or, for an advance, take a different
     * instalment this month. Approval reads these; unapproving keeps them. Without a row the
     * charge follows the ordinary rule, so an empty table changes nothing.
     */
    public function up(): void
    {
        Schema::create('payroll_deduction_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            // violation | maintenance | custody | driver_expense | advance
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');

            // defer: charge in defer_to_* instead of this month. amount: this month's instalment (advances only).
            $table->string('action', 16);
            $table->decimal('amount', 12, 3)->nullable();
            $table->unsignedSmallInteger('defer_to_year')->nullable();
            $table->unsignedTinyInteger('defer_to_month')->nullable();

            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'year', 'month', 'source_type', 'source_id'], 'pdo_month_source_unique');
            $table->index(['company_id', 'source_type', 'source_id'], 'pdo_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_deduction_overrides');
    }
};
