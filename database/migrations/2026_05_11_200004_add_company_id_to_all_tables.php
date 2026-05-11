<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add company_id to every data table for multi-tenant isolation.
 *
 * IMPORTANT: This migration assumes a default company has been seeded
 * BEFORE running, or that tables are empty. The companion seeder
 * (MigrateToMultiTenantSeeder) handles backfilling existing data.
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
            if (!Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $t) {
                    // Nullable initially so existing rows don't break.
                    // The seeder will backfill, then we make it NOT NULL.
                    $t->unsignedBigInteger('company_id')->nullable()->after('id');
                    $t->index('company_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            if (Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropIndex(['company_id']);
                    $t->dropColumn('company_id');
                });
            }
        }
    }
};
