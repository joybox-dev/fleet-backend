<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_guarantees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('guarantee_type', 30); // passport, civil_id_copy, contract_copy, bank_guarantee, other
            $table->string('document_number')->nullable();
            $table->string('file_path')->nullable();
            $table->date('received_date');
            $table->date('returned_date')->nullable();
            $table->string('status', 20)->default('held'); // held, returned
            $table->text('notes')->nullable();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->index('employee_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_guarantees');
    }
};
