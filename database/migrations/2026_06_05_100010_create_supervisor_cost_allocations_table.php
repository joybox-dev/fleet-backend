<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisor_cost_allocations', function (Blueprint $table) {
            $table->id();
            
            // Tenant isolation
            $table->unsignedBigInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            
            // Core relations
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->restrictOnDelete();
            
            $table->decimal('allocation_percentage', 5, 2);
            $table->date('effective_date');
            
            $table->timestamps();
            
            // Compound index for lookup
            $table->index(['employee_id', 'effective_date']);
        });

        Schema::create('supervisor_allocation_audit_logs', function (Blueprint $table) {
            $table->id();
            
            // Relation to supervisor
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            
            // Log info
            $table->foreignId('action_by')->constrained('users')->restrictOnDelete();
            $table->json('old_allocation');
            $table->json('new_allocation');
            
            $table->timestamp('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_allocation_audit_logs');
        Schema::dropIfExists('supervisor_cost_allocations');
    }
};
