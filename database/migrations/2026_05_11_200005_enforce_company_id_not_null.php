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
        // Auto-backfill: if any rows have NULL company_id, assign them to the
        // default company (code='mersal') or the first company in the table.
        // This makes `migrate:fresh` work without needing a manual seeder step,
        // since some migrations (e.g. create_custody_types) insert seed data
        // before the company_id column exists.
        $defaultCompanyId = DB::table('companies')->where('code', 'mersal')->value('id')
            ?? DB::table('companies')->orderBy('id')->value('id');

        if (!$defaultCompanyId) {
            // Create a default company if none exists
            $defaultCompanyId = DB::table('companies')->insertGetId([
                'name'            => 'Mersal Company',
                'name_ar'         => 'شركة مرسال للتوصيل',
                'code'            => 'mersal',
                'is_active'       => true,
                'currency'        => 'KWD',
                'enabled_modules' => json_encode([
                    'dashboard', 'clients', 'contracts', 'employees', 'vehicles',
                    'daily_logs', 'violations', 'maintenance', 'cash',
                    'custody', 'leaves', 'payroll', 'reports', 'settings',
                ]),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        foreach ($this->tables as $table) {
            // Auto-backfill NULL rows
            $nullCount = DB::table($table)->whereNull('company_id')->count();
            if ($nullCount > 0) {
                DB::table($table)->whereNull('company_id')->update(['company_id' => $defaultCompanyId]);
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
