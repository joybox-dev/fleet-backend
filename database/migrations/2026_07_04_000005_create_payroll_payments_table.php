<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('payroll_slip_id');
            $table->decimal('amount', 10, 3);
            $table->date('date');
            $table->string('type')->default('disbursement'); // disbursement, write_off
            $table->string('payment_method')->default('bank_transfer'); // bank_transfer, cash
            $table->text('audit_reason')->nullable(); // reasoning for write-off
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('payroll_slip_id')->references('id')->on('payroll_slips')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payments');
    }
};
