<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_contract_overrides', function (Blueprint $table) {
            $table->id();
            
            // Tenant isolation
            $table->unsignedBigInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            
            // Relation
            $table->foreignId('contract_assignment_id')->constrained('contract_assignments')->cascadeOnDelete();
            
            // Overrides (all nullable to use fallback chains)
            $table->decimal('custom_order_commission', 10, 3)->nullable();
            $table->decimal('custom_hourly_rate', 10, 3)->nullable();
            $table->decimal('custom_fixed_salary', 10, 3)->nullable();
            $table->integer('custom_monthly_target')->nullable();
            $table->decimal('custom_monthly_bonus', 10, 3)->nullable();
            $table->integer('custom_valid_days')->nullable();
            
            // Reason and effective dates
            $table->text('customization_reason'); // Mandatory reason
            $table->date('effective_from'); // Mandatory
            $table->date('effective_to')->nullable(); // Nullable
            
            $table->timestamps();
            
            // Indexing for quick overrides lookup
            $table->index(['contract_assignment_id', 'effective_from', 'effective_to'], 'idx_assignment_effective_dates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_contract_overrides');
    }
};
