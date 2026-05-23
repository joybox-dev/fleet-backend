<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Company;
use App\Models\User;
use App\Models\LeaveType;
use App\Models\EvaluationCriterion;

/**
 * MersalCompanySeeder — A single-company clean slate seeder for Mersal Company
 * 
 * Sets up a completely clean company and a single administrator user account
 * so the user can test all features (drivers, contracts, vehicles) from scratch.
 */
class MersalCompanySeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('╔══════════════════════════════════════════╗');
        $this->command->info('║  Mersal Company — Clean Slate Seeder     ║');
        $this->command->info('║  Testing from Scratch (No Seed Data)     ║');
        $this->command->info('╚══════════════════════════════════════════╝');

        // 1. Seed Mersal Company
        $company = Company::firstOrCreate(['code' => 'mersal'], [
            'name'            => 'Mersal Company',
            'name_ar'         => 'شركة مرسال للتوصيل',
            'is_active'       => true,
            'currency'        => 'KWD',
            'enabled_modules' => Company::DEFAULT_MODULES,
        ]);

        $this->command->info("✓ Company: Mersal Company (#{$company->id})");

        // Set company context
        app()->instance('current_company_id', $company->id);

        // 2. Seed the single administrator user account
        $admin = User::firstOrCreate(['email' => 'mersal@fleetops.kw'], [
            'name'           => 'Mersal',
            'password'       => Hash::make('abuhadram'),
            'role'           => 'admin',
            'is_super_admin' => true,
            'company_id'     => $company->id,
        ]);
        $admin->update(['company_id' => $company->id, 'is_super_admin' => true]);

        $this->command->info('✓ Single Account Seeded: mersal@fleetops.kw');

        // 3. Seed basic settings (default leave types and evaluation criteria) so the system features are fully functional
        $leaveData = [
            ['Annual Leave', 'إجازة سنوية', true, 30, true, 1.0],
            ['Sick Leave', 'إجازة مرضية', true, 15, true, 1.0],
            ['Unpaid Leave', 'إجازة بدون راتب', false, null, true, 1.0],
            ['Emergency Leave', 'إجازة طارئة', true, 3, true, 1.0],
            ['Absence', 'غياب بدون إذن', false, null, false, 2.0],
        ];
        foreach ($leaveData as [$name, $nameAr, $paid, $max, $approval, $penalty]) {
            LeaveType::firstOrCreate(['name' => $name, 'company_id' => $company->id], [
                'name' => $name, 'name_ar' => $nameAr,
                'is_paid' => $paid, 'max_days_per_year' => $max,
                'requires_approval' => $approval, 'penalty_multiplier' => $penalty,
                'company_id' => $company->id,
            ]);
        }

        foreach (['Work Performance', 'Punctuality', 'Customer Service'] as $cName) {
            EvaluationCriterion::firstOrCreate(['name' => $cName, 'company_id' => $company->id], [
                'name' => $cName, 'name_ar' => $cName == 'Work Performance' ? 'أداء العمل' : ($cName == 'Punctuality' ? 'الالتزام بالمواعيد' : 'خدمة العملاء'),
                'weight' => $cName == 'Work Performance' ? 40 : 30, 'is_active' => true, 'company_id' => $company->id,
            ]);
        }
        
        $this->command->info('✓ Default leave types & evaluation criteria settings seeded');
        $this->command->info('🚀 Ready for testing from scratch!');
        $this->command->info('   Login: mersal@fleetops.kw / abuhadram');
    }
}
