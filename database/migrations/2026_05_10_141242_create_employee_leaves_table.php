<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_leaves', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();

            // Date range
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('days_count');              // Auto-calculated

            // Status workflow
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');

            // Snapshots — frozen at creation time for audit trail
            $table->boolean('is_paid');                              // Snapshot from leave type
            $table->decimal('daily_rate', 8, 3)->default(0);         // actual_salary / 30 at time of creation
            $table->decimal('penalty_multiplier', 3, 1)->default(1.0); // Snapshot from leave type
            $table->string('formula_version', 50)->default('v1_actual_div_30'); // From settings
            $table->decimal('total_deduction', 8, 3)->default(0);    // 0 for paid; daily_rate × days × multiplier for unpaid

            // Approval
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Details
            $table->text('reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['employee_id', 'start_date', 'end_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leaves');
    }
};
