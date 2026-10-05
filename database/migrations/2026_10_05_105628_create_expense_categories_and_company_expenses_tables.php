<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's own spending, the part no vehicle or driver carries: rent, residency renewals,
 * printing, bank fees… The accountant's profit-and-loss needs it, and until now nothing could
 * record it — a vehicle expense needs a vehicle and a driver expense needs a driver.
 *
 * `expense_categories` is a two-level tree (category → item) the company edits from settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->string('name_ar', 120);
            $table->string('code', 30)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'parent_id']);
        });

        Schema::create('company_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->decimal('amount', 12, 3);
            $table->date('expense_date');
            $table->string('description', 255)->nullable();
            $table->string('vendor', 150)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('paid_via', 20)->default('cash');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('receipt_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_expenses');
        Schema::dropIfExists('expense_categories');
    }
};
