<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('operational_advances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('employee_id');
            $table->decimal('amount', 10, 3);
            $table->date('date');
            $table->string('reason');
            $table->string('status')->default('pending'); // pending, active, rejected, completed
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('operational_advance_expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operational_advance_id');
            $table->decimal('amount', 10, 3);
            $table->date('date');
            $table->string('description');
            $table->unsignedBigInteger('contract_id')->nullable(); // null means Company Overhead
            $table->string('receipt_path')->nullable();
            $table->timestamps();

            $table->foreign('operational_advance_id')->references('id')->on('operational_advances')->onDelete('cascade');
            $table->foreign('contract_id')->references('id')->on('contracts')->onDelete('set null');
        });

        Schema::create('operational_advance_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operational_advance_id');
            $table->decimal('amount', 10, 3);
            $table->date('date');
            $table->timestamps();

            $table->foreign('operational_advance_id')->references('id')->on('operational_advances')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_advance_returns');
        Schema::dropIfExists('operational_advance_expenses');
        Schema::dropIfExists('operational_advances');
    }
};
