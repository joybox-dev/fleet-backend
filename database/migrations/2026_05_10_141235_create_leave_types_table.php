<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();

            $table->string('name');                                  // English name
            $table->string('name_ar');                               // Arabic name
            $table->boolean('is_paid')->default(false);              // Paid or unpaid
            $table->unsignedSmallInteger('max_days_per_year')->nullable(); // null = unlimited
            $table->boolean('requires_approval')->default(true);
            $table->decimal('penalty_multiplier', 3, 1)->default(1.0); // 1.0 = normal, 2.0 = double deduction
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
