<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vehicle_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            
            $table->dateTime('handover_date');
            $table->enum('type', ['handover', 'return'])->default('handover');
            $table->integer('odometer_reading');
            
            // Photo fields for the four sides of the vehicle
            $table->string('photo_front')->nullable();
            $table->string('photo_back')->nullable();
            $table->string('photo_left')->nullable();
            $table->string('photo_right')->nullable();
            
            $table->text('scratches_details')->nullable();
            $table->text('notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->index('vehicle_id');
            $table->index('employee_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_handovers');
    }
};
