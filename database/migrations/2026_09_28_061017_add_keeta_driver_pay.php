<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeta drivers paid by their Keeta level (the owner's scenario, from August 2026).
 *
 * - `contracts.keeta_pay_rules`: the scenario's figures (salary per level, target, the rates, the
 *   validity thresholds, the achievement bonuses and the incentive that names each level). Null is
 *   a contract whose drivers are paid the ordinary way.
 * - `keeta_invoice_riders.invalid_days_override`: room for the owner to set a rider's invalid days
 *   by hand; left empty, they are the month's days less Keeta's valid days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->json('keeta_pay_rules')->nullable()->after('keeta_settlement_from');
        });

        Schema::table('keeta_invoice_riders', function (Blueprint $table) {
            $table->decimal('invalid_days_override', 5, 2)->nullable()->after('valid_days');
        });
    }

    public function down(): void
    {
        Schema::table('keeta_invoice_riders', function (Blueprint $table) {
            $table->dropColumn('invalid_days_override');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('keeta_pay_rules');
        });
    }
};
