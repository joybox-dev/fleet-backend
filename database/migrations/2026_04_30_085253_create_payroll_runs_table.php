<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');              // 1–12

            $table->enum('status', ['draft', 'approved', 'paid'])->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // Totals (computed)
            $table->decimal('total_official', 10, 3)->default(0);   // Sum of official (bank) salaries
            $table->decimal('total_actual', 10, 3)->default(0);     // Sum of actual (full) salaries
            $table->decimal('total_cash_diff', 10, 3)->default(0);  // total_actual - total_official (cash portion)

            $table->text('notes')->nullable();

            $table->timestamps();

            // One payroll run per month
            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
