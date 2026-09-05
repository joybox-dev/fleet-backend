<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two of the deduction types had nowhere to put their evidence at all.
 *
 * A custody item's value and the amount charged for losing it were a number typed at handover with
 * nothing signed and no photograph of the damage; a salary advance is cash leaving the company on
 * the strength of a row the same person created. Both are now able to carry proof, which the
 * controllers require wherever the money reaches a driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custody_items', function (Blueprint $table) {
            // Signed at handover, and photographed when it comes back damaged or does not come back.
            $table->string('handover_proof_path')->nullable()->after('notes');
            $table->string('return_proof_path')->nullable()->after('handover_proof_path');
        });

        Schema::table('salary_advances', function (Blueprint $table) {
            // The voucher the driver signed for the cash.
            $table->string('voucher_path')->nullable()->after('reason');
        });

        Schema::table('maintenance_records', function (Blueprint $table) {
            // Charging a repair to a driver who was not holding that vehicle on that date is
            // sometimes right — a replacement for a day, a shift nobody logged — but it has to be
            // said out loud. Violations already work this way; maintenance had no check at all.
            $table->string('assignment_override_reason')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('custody_items', function (Blueprint $table) {
            $table->dropColumn(['handover_proof_path', 'return_proof_path']);
        });

        Schema::table('salary_advances', function (Blueprint $table) {
            $table->dropColumn('voucher_path');
        });

        Schema::table('maintenance_records', function (Blueprint $table) {
            $table->dropColumn('assignment_override_reason');
        });
    }
};
