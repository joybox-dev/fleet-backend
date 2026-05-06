<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->date('assigned_date');
            $table->date('unassigned_date')->nullable();      // null = currently assigned
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            // A vehicle can only have one active assignment at a time
            $table->index(['vehicle_id', 'is_active']);
            $table->index(['employee_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_assignments');
    }
};
