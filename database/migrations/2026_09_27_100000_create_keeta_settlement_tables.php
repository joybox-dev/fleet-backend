<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeta pays by a monthly partner statement: a price for every order (a base fee plus a distance
 * fee, capped), a performance incentive per rider, and its own deductions. Nothing on our side can
 * reproduce that figure — the distance is Keeta's and so is the ranking — so the statement itself is
 * imported and becomes the month's revenue. Until it arrives, the month is estimated.
 *
 * - `contracts.keeta_settlement_from`: the first month read this way. Months before it keep the
 *   contract's own client pricing, untouched.
 * - `keeta_invoices` / `keeta_invoice_riders` / `keeta_invoice_lines`: one statement per contract
 *   and month, its rider rows, and its adjustment and penalty lines (the order lines are summed
 *   into the rider rows and not kept).
 * - `keeta_level_snapshots` / `keeta_level_rows`: Keeta's «expected level» export, uploaded during a
 *   month, which names each rider's current tier and the incentive it would pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->date('keeta_settlement_from')->nullable()->after('client_payment_method');
        });

        Schema::create('keeta_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('billing_cycle', 50)->nullable();
            $table->string('partner_id', 50)->nullable();
            $table->string('partner_name')->nullable();
            $table->decimal('order_pricing', 12, 3)->default(0);
            $table->decimal('experience_incentive', 12, 3)->default(0);
            $table->decimal('capacity_incentive', 12, 3)->default(0);
            $table->decimal('other_income', 12, 3)->default(0);
            $table->decimal('tips', 12, 3)->default(0);
            $table->decimal('deduction', 12, 3)->default(0);
            $table->decimal('food_compensation', 12, 3)->default(0);
            $table->decimal('other_adjustment', 12, 3)->default(0);
            $table->decimal('withholding', 12, 3)->default(0);
            $table->decimal('invoice_amount', 12, 3)->default(0);
            $table->decimal('total_payable', 12, 3)->default(0);
            $table->unsignedInteger('riders_count')->default(0);
            $table->unsignedInteger('valid_riders')->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contract_id', 'year', 'month']);
        });

        Schema::create('keeta_invoice_riders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('keeta_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('courier_id', 50);
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_valid')->default(false);
            $table->string('reason')->nullable();
            $table->decimal('valid_days', 6, 2)->default(0);
            $table->decimal('daily_hours', 6, 2)->default(0);
            $table->decimal('peak_hours', 6, 2)->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->decimal('order_pricing', 12, 3)->default(0);
            $table->decimal('experience_incentive', 12, 3)->default(0);
            $table->decimal('capacity_incentive', 12, 3)->default(0);
            $table->decimal('other_income', 12, 3)->default(0);
            $table->decimal('tips', 12, 3)->default(0);
            $table->decimal('deduction', 12, 3)->default(0);
            $table->decimal('food_compensation', 12, 3)->default(0);
            $table->decimal('other_adjustment', 12, 3)->default(0);
            $table->decimal('withholding', 12, 3)->default(0);
            $table->decimal('total_payable', 12, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('keeta_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('keeta_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('courier_id', 50)->nullable();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('transaction_type', 100)->nullable();
            $table->string('label')->nullable();
            $table->decimal('amount', 12, 3)->default(0);
            $table->string('note')->nullable();
            $table->string('ticket_id', 50)->nullable();
            $table->string('violation_id', 50)->nullable();
            $table->string('violation_type')->nullable();
            $table->string('punishment')->nullable();
            $table->timestamps();
        });

        Schema::create('keeta_level_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('taken_on');
            $table->string('original_filename')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['contract_id', 'year', 'month']);
        });

        Schema::create('keeta_level_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('keeta_level_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('courier_id', 50);
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('level', 5)->nullable();
            $table->decimal('reward', 10, 3)->default(0);
            $table->decimal('ontime_rate', 7, 4)->nullable();
            $table->decimal('completion_rate', 7, 4)->nullable();
            $table->decimal('utr', 8, 4)->nullable();
            $table->unsignedInteger('orders')->default(0);
            $table->decimal('acceptance_rate', 7, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keeta_level_rows');
        Schema::dropIfExists('keeta_level_snapshots');
        Schema::dropIfExists('keeta_invoice_lines');
        Schema::dropIfExists('keeta_invoice_riders');
        Schema::dropIfExists('keeta_invoices');

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('keeta_settlement_from');
        });
    }
};
