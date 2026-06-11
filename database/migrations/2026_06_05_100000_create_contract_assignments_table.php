<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_assignments', function (Blueprint $table) {
            $table->id();
            
            // Tenant isolation
            $table->unsignedBigInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            
            // Core relations
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            
            // Core fields
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status')->default('active'); // active, inactive
            $table->string('courier_id')->nullable(); // External platform code (e.g. Keeta)
            
            $table->timestamps();
            
            // Prevent overlapping or duplicate active assignments for the same driver on the same contract
            $table->index(['employee_id', 'contract_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_assignments');
    }
};
