<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('daily_log_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();

            $table->date('settlement_date');
            $table->decimal('amount', 8, 3);                    // KWD amount handed over

            // Receipt photo — from meeting: "تسوية + تصوير إيصال التسليم"
            $table->string('receipt_photo_path')->nullable();

            $table->text('notes')->nullable();

            // ERPNext sync — CashSettlement → Payment Entry
            $table->string('erp_id')->nullable()->index();
            $table->timestamp('erp_synced_at')->nullable();
            $table->enum('erp_sync_status', ['pending', 'synced', 'failed'])->default('pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_settlements');
    }
};
