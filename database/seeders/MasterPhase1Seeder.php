<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Company;
use App\Models\User;
use App\Models\LeaveType;

/**
 * Phase 1: Companies + Users + Leave Types
 */
class MasterPhase1Seeder extends Seeder
{
    public function run(): void
    {
        // ══ Company 1: Eagle Delivery ══
        $eagle = Company::firstOrCreate(['code' => 'eagle'], [
            'name'            => 'Eagle Delivery',
            'name_ar'         => 'شركة النسر للتوصيل',
            'is_active'       => true,
            'currency'        => 'KWD',
            'enabled_modules' => Company::DEFAULT_MODULES,
        ]);

        // ══ Company 2: Al-Buraq Logistics ══
        $buraq = Company::firstOrCreate(['code' => 'buraq'], [
            'name'            => 'Al-Buraq Logistics',
            'name_ar'         => 'شركة البراق اللوجستية',
            'is_active'       => true,
            'currency'        => 'KWD',
            'enabled_modules' => Company::DEFAULT_MODULES,
        ]);

        $this->command->info("✓ 2 companies: Eagle (#{$eagle->id}), Buraq (#{$buraq->id})");

        // ══ Users ══
        $admin = User::firstOrCreate(['email' => 'mersal@fleetops.kw'], [
            'name'           => 'Mersal',
            'password'       => Hash::make('abuhadram'),
            'role'           => 'admin',
            'is_super_admin' => true,
            'company_id'     => $eagle->id,
        ]);
        $admin->update(['company_id' => $eagle->id, 'is_super_admin' => true]);

        User::firstOrCreate(['email' => 'op-eagle@fleetops.kw'], [
            'name'       => 'مشغّل النسر',
            'password'   => Hash::make('abuhadram'),
            'role'       => 'operator',
            'company_id' => $eagle->id,
        ]);

        User::firstOrCreate(['email' => 'admin-buraq@fleetops.kw'], [
            'name'       => 'مدير البراق',
            'password'   => Hash::make('abuhadram'),
            'role'       => 'admin',
            'company_id' => $buraq->id,
        ]);

        User::firstOrCreate(['email' => 'op-buraq@fleetops.kw'], [
            'name'       => 'مشغّل البراق',
            'password'   => Hash::make('abuhadram'),
            'role'       => 'operator',
            'company_id' => $buraq->id,
        ]);

        $this->command->info('✓ 4 users (mersal = super admin)');

        // ══ Leave Types (per company) ══
        $leaveData = [
            ['Annual Leave', 'إجازة سنوية', true, 30, true, 1.0],
            ['Sick Leave', 'إجازة مرضية', true, 15, true, 1.0],
            ['Unpaid Leave', 'إجازة بدون راتب', false, null, true, 1.0],
            ['Emergency Leave', 'إجازة طارئة', true, 3, true, 1.0],
            ['Absence', 'غياب بدون إذن', false, null, false, 2.0],
        ];
        foreach ([$eagle->id, $buraq->id] as $cId) {
            foreach ($leaveData as [$name, $nameAr, $paid, $max, $approval, $penalty]) {
                LeaveType::firstOrCreate(['name' => $name, 'company_id' => $cId], [
                    'name' => $name, 'name_ar' => $nameAr,
                    'is_paid' => $paid, 'max_days_per_year' => $max,
                    'requires_approval' => $approval, 'penalty_multiplier' => $penalty,
                    'company_id' => $cId,
                ]);
            }
        }
        $this->command->info('✓ 5 leave types');
    }
}
