<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * After the seeder has backfilled company_id on all rows,
 * this migration enforces NOT NULL + adds foreign key constraints.
 *
 * Run AFTER: php artisan db:seed --class=MigrateToMultiTenantSeeder
 */
return new class extends Migration
{
    private array $tables = [
        'clients',
        'contracts',
        'employees',
        'vehicles',
        'vehicle_assignments',
        'daily_logs',
        'violations',
        'maintenance_records',
        'custody_items',
        'custody_types',
        'cash_settlements',
        'payroll_runs',
        'payroll_slips',
        'leave_types',
        'employee_leaves',
        'settings',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            // Safety check: ensure no NULLs remain
            $nullCount = DB::table($table)->whereNull('company_id')->count();
            if ($nullCount > 0) {
                throw new \RuntimeException(
                    "Table [{$table}] still has {$nullCount} rows with NULL company_id. "
                    . "Run: php artisan db:seed --class=MigrateToMultiTenantSeeder first."
                );
            }

            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable(false)->change();
                $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign(["{$table}_company_id_foreign"]);
                $t->unsignedBigInteger('company_id')->nullable()->change();
            });
        }
    }
};
