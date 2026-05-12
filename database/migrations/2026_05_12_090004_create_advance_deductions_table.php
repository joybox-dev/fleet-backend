<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advance_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_advance_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_slip_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 3);
            $table->date('deduction_date');
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index('company_id');
            $table->index('salary_advance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_deductions');
    }
};
