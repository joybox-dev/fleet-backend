<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_bonuses', function (Blueprint $table) {
            $table->id();
            
            // Tenant isolation
            $table->unsignedBigInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            
            // Relation
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            
            $table->string('bonus_name');
            $table->decimal('amount', 10, 3);
            $table->boolean('is_valid_drivers_only')->default(true);
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_bonuses');
    }
};
