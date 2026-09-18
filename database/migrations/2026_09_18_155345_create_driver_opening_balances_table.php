<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a driver and the company owed each other before the system's first approved month.
     *
     * A driver's running balance was built only from months approved here, so it started every
     * driver at zero — a man who joined the system already in debt, or with pay still held for him,
     * had nowhere to carry it. One signed figure per driver: positive is money the company holds
     * for him, negative is money he owes. It is the first line of his account, never a deduction
     * on a sheet, so no approved month's figures move when one is written.
     */
    public function up(): void
    {
        Schema::create('driver_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->decimal('amount', 12, 3);
            $table->date('balance_date');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id'], 'driver_opening_balances_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_opening_balances');
    }
};
