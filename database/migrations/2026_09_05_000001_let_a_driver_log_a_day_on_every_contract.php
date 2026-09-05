<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A driver works several contracts on the same day. The table did not allow it.
 *
 * `unique(employee_id, vehicle_id, log_date)` says a driver has ONE day, identified by the vehicle
 * he drove — so a second contract on the same date collided, and the controller's rescue path
 * treated the earlier contract's row as a duplicate and overwrote it. A driver assigned to five
 * contracts could hold exactly one log per day in the whole system: of 3,953 logs in the owner's
 * database, not one pair of (driver, day) had a second row, across sixteen multi-contract drivers.
 *
 * The day belongs to the CONTRACT, not to the vehicle: one log per driver per contract per day,
 * and as many contracts in a day as he actually works. The same vehicle may now appear on more
 * than one of them, which is what happens in the yard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_logs', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'vehicle_id', 'log_date']);
            $table->unique(['employee_id', 'contract_id', 'log_date'], 'daily_logs_driver_contract_day_unique');
        });
    }

    public function down(): void
    {
        // Reversing this can fail on purpose: once a driver has logged two contracts on one day,
        // the old constraint has no way to hold them both. Clear the extra rows first.
        Schema::table('daily_logs', function (Blueprint $table) {
            $table->dropUnique('daily_logs_driver_contract_day_unique');
            $table->unique(['employee_id', 'vehicle_id', 'log_date']);
        });
    }
};
