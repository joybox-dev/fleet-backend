<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every expense, every fine paid and every maintenance is paid out of an administrative employee's
 * operational custody (owner, 2026-10-26: «طريقة سداد المصاريف والصيانة هية من خلال الموظفين
 * الاداريين يلي عندهم عهدة تشغيلية»). The custody a record was paid from is written on the record
 * itself; a fine and a maintenance are recorded first and paid later, so they also carry the day
 * they were paid. Records from before this stay without a custody, as they were.
 */
return new class extends Migration
{
    private const PAID = ['driver_expenses', 'vehicle_expenses', 'company_expenses', 'violations', 'maintenance_records'];

    private const PAID_LATER = ['violations', 'maintenance_records'];

    public function up(): void
    {
        foreach (self::PAID as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->foreignId('operational_advance_id')->nullable()->after('company_id')
                    ->constrained('operational_advances')->nullOnDelete();
                if (in_array($name, self::PAID_LATER, true)) {
                    $table->date('paid_at')->nullable()->after('operational_advance_id');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::PAID as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropConstrainedForeignId('operational_advance_id');
                if (in_array($name, self::PAID_LATER, true)) {
                    $table->dropColumn('paid_at');
                }
            });
        }
    }
};
