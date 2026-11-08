<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Talabat pays the company per order in two parts — the pickup and the drop-off — each at its own
 * price per vehicle type, and the prices change during a month (September 2026: car 0.500 + 0.550
 * until the 15th, 0.590 + 0.590 from the 16th). Its monthly statement is the figure; until it comes,
 * the orders logged here are priced at the contract's rates for their day.
 *
 * - contracts.talabat_settlement_from: from this month the client is billed this way.
 * - contracts.talabat_rates: the price periods, each from a date on.
 * - talabat_statements / talabat_statement_riders: an imported statement, its riders, and the lines
 *   Talabat charges the company as a whole (cash deficit, contract fees…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->date('talabat_settlement_from')->nullable();
            $table->json('talabat_rates')->nullable();
        });

        Schema::create('talabat_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('riders_count')->default(0);
            $table->unsignedInteger('deliveries')->default(0);
            $table->unsignedInteger('pickups')->default(0);
            $table->unsignedInteger('dropoffs')->default(0);
            $table->decimal('pickup_payment', 12, 3)->default(0);
            $table->decimal('dropoff_payment', 12, 3)->default(0);
            $table->decimal('service_payment', 12, 3)->default(0);
            $table->decimal('rider_adjustments', 12, 3)->default(0); // signed: deductions, compensations…
            $table->decimal('riders_total', 12, 3)->default(0);      // Σ riders' net: the month's revenue
            $table->json('company_lines')->nullable();               // [{label, amount}] charged to the company as a whole
            $table->decimal('company_total', 12, 3)->default(0);
            $table->decimal('net_payment', 12, 3)->default(0);       // what Talabat transfers
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contract_id', 'year', 'month']);
        });

        Schema::create('talabat_statement_riders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talabat_statement_id')->constrained()->cascadeOnDelete();
            $table->string('rider_id', 40);
            $table->string('name')->nullable();
            $table->string('vehicle', 10)->nullable(); // car, bike
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('hours', 9, 2)->default(0);
            $table->unsignedInteger('deliveries')->default(0);
            $table->unsignedInteger('pickups')->default(0);
            $table->unsignedInteger('dropoffs')->default(0);
            $table->decimal('pickup_payment', 12, 3)->default(0);
            $table->decimal('dropoff_payment', 12, 3)->default(0);
            $table->decimal('service_payment', 12, 3)->default(0);
            $table->decimal('adjustments', 12, 3)->default(0);
            $table->decimal('net', 12, 3)->default(0);
            $table->timestamps();

            $table->index(['talabat_statement_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talabat_statement_riders');
        Schema::dropIfExists('talabat_statements');
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['talabat_settlement_from', 'talabat_rates']);
        });
    }
};
