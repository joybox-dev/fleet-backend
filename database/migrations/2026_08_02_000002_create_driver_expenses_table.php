<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->onDelete('set null');
            $table->foreignId('expense_type_id')->nullable()->constrained('vehicle_expense_types')->onDelete('set null');
            $table->string('expense_type')->nullable(); // String fallback or label
            $table->decimal('amount', 10, 3);
            $table->enum('borne_by', ['company', 'driver', 'split'])->default('company');
            $table->decimal('company_amount', 10, 3)->default(0);
            $table->decimal('driver_amount', 10, 3)->default(0);
            $table->date('expense_date');
            $table->string('vendor')->nullable();
            $table->string('receipt_path')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_deducted')->default(false);
            $table->foreignId('payroll_slip_id')->nullable()->constrained('payroll_slips')->onDelete('set null');
            $table->softDeletes();
            $table->timestamps();
        });

        if (Schema::hasTable('payroll_slips') && !Schema::hasColumn('payroll_slips', 'driver_expense_deduction')) {
            Schema::table('payroll_slips', function (Blueprint $table) {
                $table->decimal('driver_expense_deduction', 10, 3)->default(0)->after('custody_deduction');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_slips') && Schema::hasColumn('payroll_slips', 'driver_expense_deduction')) {
            Schema::table('payroll_slips', function (Blueprint $table) {
                $table->dropColumn('driver_expense_deduction');
            });
        }
        Schema::dropIfExists('driver_expenses');
    }
};
