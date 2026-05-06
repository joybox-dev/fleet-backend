<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();

            // Item type — from meeting: phone, SIM, clothing, cash, other
            $table->enum('item_type', ['phone', 'sim', 'clothing', 'cash', 'other']);
            $table->string('item_description')->nullable();     // e.g. "iPhone 13 Black"
            $table->string('serial_number')->nullable();        // For phones/SIMs
            $table->decimal('value', 8, 3)->default(0);        // KWD value at time of issue

            // Tracking
            $table->date('issued_date');
            $table->date('returned_date')->nullable();          // null = still with driver
            $table->boolean('is_returned')->default(false);
            $table->enum('return_condition', ['good', 'damaged', 'lost'])->nullable();
            $table->decimal('deduction_amount', 8, 3)->default(0); // If damaged/lost

            $table->text('notes')->nullable();

            // ERPNext sync — Custody → Stock Entry (Material Issue / Receipt)
            $table->string('erp_id')->nullable()->index();
            $table->timestamp('erp_synced_at')->nullable();
            $table->enum('erp_sync_status', ['pending', 'synced', 'failed'])->default('pending');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_items');
    }
};
