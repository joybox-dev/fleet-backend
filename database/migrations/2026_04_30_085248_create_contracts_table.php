<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('contract_number')->unique();
            $table->string('name');                               // e.g. "Yalla Go Q1 2026"

            // Payment type — from meeting: fixed salary OR per-order OR both
            $table->enum('payment_type', ['per_order', 'fixed', 'hybrid']);
            $table->decimal('rate_per_order', 8, 3)->default(0); // KWD per order (e.g. 1.000, 1.350)
            $table->decimal('fixed_monthly', 8, 3)->default(0);  // For fixed/hybrid contracts

            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);

            // IMMUTABLE after creation — from meeting: "لا يمكن تغييرها بعد الإدخال"
            // Enforced at application layer, this flag tracks if it's been locked
            $table->boolean('is_locked')->default(false);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
