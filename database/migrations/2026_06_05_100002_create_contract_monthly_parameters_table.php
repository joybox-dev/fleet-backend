<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_monthly_parameters', function (Blueprint $table) {
            $table->id();
            
            // Tenant isolation
            $table->unsignedBigInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            
            // Relation
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            
            // Period
            $table->integer('year');
            $table->integer('month');
            
            // General targets
            $table->integer('min_valid_days')->nullable();
            $table->integer('min_completed_orders')->nullable();
            $table->decimal('daily_active_time_percentage', 5, 2)->nullable();
            $table->integer('daily_min_orders')->nullable();
            
            // JSON rules for capacity and experience
            $table->json('capacity_incentive_rules')->nullable();
            $table->json('experience_incentive_rules')->nullable();
            
            $table->timestamps();
            
            // Ensure unique parameter record per contract, year, month
            $table->unique(['contract_id', 'year', 'month'], 'uidx_contract_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_monthly_parameters');
    }
};
