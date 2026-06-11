<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_mandatory_days', function (Blueprint $table) {
            $table->id();
            
            // Relation to monthly parameters
            $table->foreignId('contract_monthly_parameter_id')
                ->constrained('contract_monthly_parameters')
                ->cascadeOnDelete();
                
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('min_required_days');
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_mandatory_days');
    }
};
