<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 10, 3);
            $table->decimal('monthly_installment', 10, 3);
            $table->integer('total_installments');
            $table->integer('paid_installments')->default(0);
            $table->decimal('remaining_balance', 10, 3);
            $table->date('advance_date');
            $table->string('reason')->nullable();
            $table->string('status', 20)->default('active'); // active, completed, cancelled
            // ERPNext sync tracking
            $table->string('erp_id')->nullable();
            $table->timestamp('erp_synced_at')->nullable();
            $table->string('erp_sync_status', 20)->default('pending');
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->index('employee_id');
            $table->index('status');
            $table->index('erp_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_advances');
    }
};
