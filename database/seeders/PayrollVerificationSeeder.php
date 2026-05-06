<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;

class PayrollVerificationSeeder extends Seeder
{
    public function run(): void
    {
        $month = '2026-04'; // شهر أبريل 2026

        // 1. إنشاء مستخدم Admin للتسجيل وكـ created_by
        $adminId = DB::table('users')->insertGetId([
            'name' => 'Admin', 'email' => 'admin@fleetops.com',
            'password' => Hash::make('password'), 'role' => 'admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 2. إنشاء العميل والعقد (تسعيرة الشركة)
        $clientId = DB::table('clients')->insertGetId([
            'name' => 'Yalla Go (Test)',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $contractId = DB::table('contracts')->insertGetId([
            'client_id'      => $clientId,
            'name'           => 'Yalla Go April 2026',
            'contract_number'=> 'YG-2026-04',
            'payment_type'   => 'per_order',
            'rate_per_order'  => 1.500, // الشركة تقبض دينار ونصف عن كل طلب
            'start_date'     => '2026-04-01',
            'created_at'     => now(), 'updated_at' => now(),
        ]);

        // 3. إنشاء المركبة
        $vehicleId = DB::table('vehicles')->insertGetId([
            'plate_number' => 'KW-99999',
            'make' => 'Toyota', 'model' => 'Camry',
            'status' => 'working',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 4. إنشاء السائقين
        // ─── عمر الشامي: راتب ثابت + بونص ───
        $omarId = DB::table('employees')->insertGetId([
            'name'            => 'Omar Al-Shami',
            'name_ar'         => 'عمر الشامي',
            'pay_type'        => 'fixed',
            'official_salary' => 120.000,   // للبنك
            'actual_salary'   => 200.000,   // الفعلي الداخلي
            'rate_per_order'  => 0.100,     // بونص السائق (100 فلس)
            'status'          => 'active',
            'date_of_joining' => '2025-01-01',
            'created_at'      => now(), 'updated_at' => now(),
        ]);

        // ─── راجو كومار: بالطلب فقط ───
        $rajuId = DB::table('employees')->insertGetId([
            'name'            => 'Raju Kumar',
            'name_ar'         => 'راجو كومار',
            'pay_type'        => 'per_order',
            'official_salary' => 100.000,   // للبنك (شكلي)
            'actual_salary'   => 0.000,
            'rate_per_order'  => 0.500,     // 500 فلس لكل طلب
            'status'          => 'active',
            'date_of_joining' => '2025-01-01',
            'created_at'      => now(), 'updated_at' => now(),
        ]);

        // 5. سجلات يومية - شهر أبريل 2026
        // عمر: 400 طلب
        DB::table('daily_logs')->insert([
            'employee_id'   => $omarId,
            'vehicle_id'    => $vehicleId,
            'contract_id'   => $contractId,
            'created_by'    => $adminId,
            'log_date'      => $month . '-15',
            'orders_count'  => 400,
            'orders_online' => 200,
            'orders_cash'   => 200,
            'rate_per_order' => 1.500,
            'income_amount'  => 600.000,  // دخل الشركة: 400 × 1.5
            'created_at'    => now(), 'updated_at' => now(),
        ]);

        // راجو: 350 طلب
        DB::table('daily_logs')->insert([
            'employee_id'   => $rajuId,
            'vehicle_id'    => $vehicleId,
            'contract_id'   => $contractId,
            'created_by'    => $adminId,
            'log_date'      => $month . '-20',
            'orders_count'  => 350,
            'orders_online' => 175,
            'orders_cash'   => 175,
            'rate_per_order' => 1.500,
            'income_amount'  => 525.000,  // دخل الشركة: 350 × 1.5
            'created_at'    => now(), 'updated_at' => now(),
        ]);

        // 6. خصومات
        // مخالفة عمر: 45 د.ك
        DB::table('violations')->insert([
            'employee_id'     => $omarId,
            'vehicle_id'      => $vehicleId,
            'created_by'      => $adminId,
            'violation_date'  => $month . '-10',
            'violation_type'  => 'تجاوز سرعة',
            'amount'          => 45.000,
            'is_driver_liable'=> true,
            'is_deducted'     => false,
            'created_at'      => now(), 'updated_at' => now(),
        ]);

        // صيانة راجو: حادث، المشرف اعتمد خصم 40 د.ك من راتبه
        DB::table('maintenance_records')->insert([
            'vehicle_id'          => $vehicleId,
            'reported_by'         => $adminId,
            'approved_by'         => $adminId,
            'garage_name'         => 'ورشة الخبراء',
            'maintenance_type'    => 'accident',
            'maintenance_date'    => $month . '-12',
            'estimated_cost'      => 80.000,
            'actual_cost'         => 80.000,
            'status'              => 'approved',
            'approved_at'         => now(),
            'is_driver_liable'    => true,
            'liable_employee_id'  => $rajuId,
            'driver_deduction'    => 40.000,  // خصم الصيانة من راتب السائق
            'created_at'          => now(), 'updated_at' => now(),
        ]);

        $this->command->info('');
        $this->command->info('✅ Precision Payroll Data Seeded Successfully!');
        $this->command->info('');
        $this->command->info('👷 عمر الشامي (fixed): 400 طلب | بونص 0.100 | مخالفة 45');
        $this->command->info('   المتوقع: فعلي=195 | بنك=120 | كاش=75');
        $this->command->info('');
        $this->command->info('👷 راجو كومار (per_order): 350 طلب | تسعيرة 0.500 | صيانة 40');
        $this->command->info('   المتوقع: فعلي=135 | بنك=100 | كاش=35');
        $this->command->info('');
        $this->command->info('🔑 Login: admin@fleetops.com / password');
        $this->command->info('📊 Go test: Payroll → April 2026 → حساب الرواتب');
    }
}
