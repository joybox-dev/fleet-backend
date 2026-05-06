<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->date('violation_date');
            $table->string('violation_type');                   // e.g. "تجاوز سرعة", "وقوف خاطئ"
            $table->string('reference_number')->nullable();     // Official ticket number
            $table->decimal('amount', 8, 3);                    // KWD amount

            // Photo proof — from meeting: "مطلوب إثبات مسجل للسائق ليطمئن"
            $table->string('photo_path')->nullable();

            // Deduction tracking
            $table->boolean('is_driver_liable')->default(true); // Driver pays or company absorbs
            $table->boolean('is_deducted')->default(false);     // Has been applied to payroll
            $table->foreignId('payroll_slip_id')->nullable()->constrained()->nullOnDelete();

            $table->text('notes')->nullable();

            // ERPNext sync — Violation → Journal Entry (debit driver, credit cash)
            $table->string('erp_id')->nullable()->index();
            $table->timestamp('erp_synced_at')->nullable();
            $table->enum('erp_sync_status', ['pending', 'synced', 'failed'])->default('pending');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('violations');
    }
};
