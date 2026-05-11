<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Backfills company_id on all existing data rows.
 *
 * Run this AFTER migration 200004 (adds nullable company_id)
 * and BEFORE migration 200005 (enforces NOT NULL).
 *
 * Usage: php artisan db:seed --class=MigrateToMultiTenantSeeder
 */
class MigrateToMultiTenantSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🏢 Multi-Tenant Data Migration — Starting...');

        // ── Step 1: Create default company ──
        $company = Company::firstOrCreate(
            ['code' => 'default'],
            [
                'name'            => 'الشركة الافتراضية',
                'name_ar'         => 'الشركة الافتراضية',
                'is_active'       => true,
                'currency'        => 'KWD',
                'enabled_modules' => Company::DEFAULT_MODULES,
            ]
        );
        $this->command->info("  ✅ Default company: {$company->name} (ID: {$company->id})");

        // ── Step 2: Backfill company_id on all data tables ──
        $tables = [
            'clients', 'contracts', 'employees', 'vehicles',
            'vehicle_assignments', 'daily_logs', 'violations',
            'maintenance_records', 'custody_items', 'custody_types',
            'cash_settlements', 'payroll_runs', 'payroll_slips',
            'leave_types', 'employee_leaves', 'settings',
        ];

        foreach ($tables as $table) {
            $updated = DB::table($table)
                ->whereNull('company_id')
                ->update(['company_id' => $company->id]);

            if ($updated > 0) {
                $this->command->info("  📦 {$table}: backfilled {$updated} rows");
            }
        }

        // ── Step 3: Promote first admin to super_admin ──
        $firstAdmin = User::where('role', 'admin')->first();
        if ($firstAdmin && !$firstAdmin->is_super_admin) {
            $firstAdmin->update(['is_super_admin' => true]);
            $this->command->info("  👑 Super admin: {$firstAdmin->name} ({$firstAdmin->email})");
        }

        // ── Step 4: Assign all users to default company ──
        $users = User::all();
        $attached = 0;
        foreach ($users as $user) {
            if (!$user->companies()->where('companies.id', $company->id)->exists()) {
                $user->companies()->attach($company->id, [
                    'role'       => $user->role ?? 'operator',
                    'is_default' => true,
                ]);
                $attached++;
            }
        }
        $this->command->info("  👥 Assigned {$attached} users to default company");

        // ── Verify: check for any remaining NULLs ──
        $hasNulls = false;
        foreach ($tables as $table) {
            $nullCount = DB::table($table)->whereNull('company_id')->count();
            if ($nullCount > 0) {
                $this->command->error("  ❌ {$table} still has {$nullCount} NULL company_id rows!");
                $hasNulls = true;
            }
        }

        if (!$hasNulls) {
            $this->command->info('');
            $this->command->info('✅ All data backfilled successfully!');
            $this->command->info('   You can now run: php artisan migrate');
            $this->command->info('   (to enforce NOT NULL on company_id via migration 200005)');
        } else {
            $this->command->error('');
            $this->command->error('❌ Some tables still have NULL company_id. Fix them before running migration 200005.');
        }
    }
}
