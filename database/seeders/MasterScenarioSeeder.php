<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\DailyLog;
use App\Models\Violation;
use App\Models\LeaveType;

/**
 * ═══════════════════════════════════════════════════════════════════
 * FleetOps — Master Scenario Seeder (MAY 2026)
 * ═══════════════════════════════════════════════════════════════════
 *
 * Deterministic data — every number is pre-calculated.
 * Login: mersal@fleetops.kw / abuhadram
 *
 * EXPECTED PAYROLL RESULTS (May 2026):
 * ┌─────────┬───────┬─────────┬──────┬──────┐
 * │Employee │ Gross │ Net Act │ Bank │ Cash │
 * ├─────────┼───────┼─────────┼──────┼──────┤
 * │Ahmed    │  300  │   280   │  100 │  180 │
 * │Omar     │  200  │   200   │  120 │   80 │
 * │Raju     │   90  │    90   │   90 │    0 │
 * └─────────┴───────┴─────────┴──────┴──────┘
 *
 * EXPECTED VEHICLE PROFITABILITY:
 *   KW-7777: Revenue=810, DriverCost=390, Net=+420
 *   KW-8888: Revenue=1500, DriverCost=200, Net=+1300
 *
 * EXPECTED CONTRACT PROFITABILITY:
 *   Talabat Q2: Revenue=810, DriverCost=390, Net=+420
 *   Karam Rest: Revenue=1500, DriverCost=200, Net=+1300
 */
class MasterScenarioSeeder extends Seeder
{
    public function run(): void
    {
        // ════════════════════════════════════════════════════════════════
        // TRUNCATE — clean slate
        // ════════════════════════════════════════════════════════════════
        Schema::disableForeignKeyConstraints();
        DB::table('payroll_slips')->truncate();
        DB::table('payroll_runs')->truncate();
        DB::table('employee_leaves')->truncate();
        DB::table('violations')->truncate();
        DB::table('maintenance_records')->truncate();
        DB::table('custody_items')->truncate();
        DB::table('daily_logs')->truncate();
        DB::table('vehicle_assignments')->truncate();
        DB::table('vehicles')->truncate();
        DB::table('employees')->truncate();
        DB::table('contracts')->truncate();
        DB::table('clients')->truncate();
        DB::table('settings')->truncate();
        Schema::enableForeignKeyConstraints();

        $this->command->info('✓ Tables truncated');

        // ════════════════════════════════════════════════════════════════
        // 1. USER
        // ════════════════════════════════════════════════════════════════
        $admin = User::firstOrCreate(['email' => 'mersal@fleetops.kw'], [
            'name'     => 'Mersal',
            'password' => Hash::make('abuhadram'),
            'role'     => 'admin',
        ]);
        $this->command->info('✓ Admin: mersal@fleetops.kw / abuhadram');

        // ════════════════════════════════════════════════════════════════
        // 2. CLIENTS
        // ════════════════════════════════════════════════════════════════
        $clientTalabat = Client::create([
            'name' => 'Talabat', 'name_ar' => 'طلبات',
            'contact_person' => 'أحمد الشمري', 'phone' => '96599001100',
            'email' => 'ops@talabat.com', 'is_active' => true,
        ]);
        $clientKaram = Client::create([
            'name' => 'Karam Restaurant', 'name_ar' => 'مطعم كرم',
            'contact_person' => 'سارة العلي', 'phone' => '96599002200',
            'email' => 'fleet@karam.com', 'is_active' => true,
        ]);
        $this->command->info('✓ 2 clients');

        // ════════════════════════════════════════════════════════════════
        // 3. CONTRACTS
        // ════════════════════════════════════════════════════════════════
        $ctTalabat = Contract::create([
            'client_id' => $clientTalabat->id,
            'contract_number' => 'TB-2026-Q2',
            'name' => 'Talabat Q2',
            'payment_type' => 'per_order',       // Company earns 0.900 per order
            'rate_per_order' => 0.900,
            'fixed_monthly' => 0,
            'start_date' => '2026-04-01',
            'end_date' => '2026-06-30',
            'is_active' => true,
            'is_locked' => true,
        ]);
        $ctKaram = Contract::create([
            'client_id' => $clientKaram->id,
            'contract_number' => 'KR-2026-FX',
            'name' => 'Karam Rest',
            'payment_type' => 'fixed',            // Fixed 1500 KD/month
            'rate_per_order' => 0,
            'fixed_monthly' => 1500.000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
            'is_locked' => true,
        ]);
        $this->command->info('✓ 2 contracts (per_order: 0.900, fixed: 1500)');

        // ════════════════════════════════════════════════════════════════
        // 4. EMPLOYEES
        // ════════════════════════════════════════════════════════════════
        // Ahmed: hybrid — actual_salary 150 + rate_per_order 0.250
        $ahmed = Employee::create([
            'name' => 'Ahmed Hassan', 'name_ar' => 'أحمد حسن',
            'employee_number' => 'EMP-A01', 'nationality' => 'Egyptian',
            'civil_id' => '290020001111', 'phone' => '96555001001',
            'gender' => 'male', 'date_of_birth' => '1993-05-10',
            'date_of_joining' => '2025-06-01', 'employee_type' => 'overseas',
            'status' => 'active', 'pay_type' => 'hybrid',
            'official_salary' => 100.000,
            'actual_salary'   => 150.000,
            'rate_per_order'  => 0.250,
            'has_end_of_service' => true,
            'health_card_expiry' => '2027-01-01',
            'residence_expiry' => '2027-06-01',
            'driving_license_expiry' => '2027-12-01',
            'work_permit_expiry' => '2027-06-01',
            'stage_arrived' => true, 'stage_medical_done' => true,
            'stage_work_permit_done' => true, 'stage_driving_trial_done' => true,
            'stage_license_obtained' => true,
        ]);

        // Omar: fixed — actual_salary 200, no per-order bonus
        $omar = Employee::create([
            'name' => 'Omar Al-Rashid', 'name_ar' => 'عمر الراشد',
            'employee_number' => 'EMP-B02', 'nationality' => 'Kuwaiti',
            'civil_id' => '290020002222', 'phone' => '96555002002',
            'gender' => 'male', 'date_of_birth' => '1990-08-20',
            'date_of_joining' => '2025-03-01', 'employee_type' => 'local_transfer',
            'status' => 'active', 'pay_type' => 'fixed',
            'official_salary' => 120.000,
            'actual_salary'   => 200.000,
            'rate_per_order'  => 0,
            'has_end_of_service' => true,
            'health_card_expiry' => '2027-03-01',
            'residence_expiry' => '2027-09-01',
            'driving_license_expiry' => '2028-03-01',
            'work_permit_expiry' => '2027-09-01',
        ]);

        // Raju: per_order — actual_salary 0, earns 0.300 per order
        $raju = Employee::create([
            'name' => 'Raju Kumar', 'name_ar' => 'راجو كومار',
            'employee_number' => 'EMP-C03', 'nationality' => 'Indian',
            'civil_id' => '290020003333', 'phone' => '96555003003',
            'gender' => 'male', 'date_of_birth' => '1995-03-15',
            'date_of_joining' => '2025-08-15', 'employee_type' => 'overseas',
            'status' => 'active', 'pay_type' => 'per_order',
            'official_salary' => 100.000,
            'actual_salary'   => 0,
            'rate_per_order'  => 0.300,
            'has_end_of_service' => true,
            'health_card_expiry' => '2027-02-01',
            'residence_expiry' => '2027-07-01',
            'driving_license_expiry' => '2028-01-01',
            'work_permit_expiry' => '2027-07-01',
            'stage_arrived' => true, 'stage_medical_done' => true,
            'stage_work_permit_done' => true, 'stage_driving_trial_done' => true,
            'stage_license_obtained' => true,
        ]);
        $this->command->info('✓ 3 employees (Ahmed=hybrid 100/150/0.250, Omar=fixed 120/200, Raju=per_order 100/0/0.300)');

        // ════════════════════════════════════════════════════════════════
        // 5. VEHICLES
        // ════════════════════════════════════════════════════════════════
        $v1 = Vehicle::create([
            'plate_number' => 'KW-7777', 'make' => 'Toyota', 'model' => 'Hilux',
            'year' => 2024, 'color' => 'White', 'status' => 'working',
            'odometer_km' => 30000, 'monthly_fuel_allowance' => 0,
            'insurance_expiry' => '2027-06-01',
        ]);
        $v2 = Vehicle::create([
            'plate_number' => 'KW-8888', 'make' => 'Hyundai', 'model' => 'Accent',
            'year' => 2023, 'color' => 'Silver', 'status' => 'working',
            'odometer_km' => 50000, 'monthly_fuel_allowance' => 0,
            'insurance_expiry' => '2027-06-01',
        ]);
        $this->command->info('✓ 2 vehicles (fuel_allowance=0 to isolate salary math)');

        // ════════════════════════════════════════════════════════════════
        // 6. VEHICLE ASSIGNMENTS
        // ════════════════════════════════════════════════════════════════
        VehicleAssignment::create([
            'vehicle_id' => $v1->id, 'employee_id' => $ahmed->id,
            'contract_id' => $ctTalabat->id,
            'assigned_date' => '2026-04-01', 'is_active' => true,
        ]);
        VehicleAssignment::create([
            'vehicle_id' => $v1->id, 'employee_id' => $raju->id,
            'contract_id' => $ctTalabat->id,
            'assigned_date' => '2026-04-01', 'is_active' => true,
        ]);
        VehicleAssignment::create([
            'vehicle_id' => $v2->id, 'employee_id' => $omar->id,
            'contract_id' => $ctKaram->id,
            'assigned_date' => '2026-01-01', 'is_active' => true,
        ]);
        $this->command->info('✓ 3 assignments (Ahmed+Raju→KW-7777, Omar→KW-8888)');

        // ════════════════════════════════════════════════════════════════
        // 7. DAILY LOGS — MAY 2026
        // ════════════════════════════════════════════════════════════════
        // Ahmed: 600 orders on KW-7777 for Talabat Q2, 30 KD cash
        //   5 logs × 120 orders = 600. income = 120 × 0.900 = 108 per log
        $ahmedDays = ['2026-05-04', '2026-05-08', '2026-05-14', '2026-05-19', '2026-05-25'];
        foreach ($ahmedDays as $day) {
            DailyLog::create([
                'employee_id' => $ahmed->id, 'vehicle_id' => $v1->id,
                'contract_id' => $ctTalabat->id, 'created_by' => $admin->id,
                'log_date' => $day,
                'orders_count' => 120, 'orders_online' => 100, 'orders_cash' => 20,
                'cash_collected' => 6.000, 'cash_settled' => 0, 'cash_pending' => 6.000,
                'rate_per_order' => 0.900,
                'income_amount' => 108.000,   // 120 × 0.900
            ]);
        }
        // Verify: 5 × 120 = 600 orders, 5 × 108 = 540 income, 5 × 6 = 30 cash ✓

        // Raju: 300 orders on KW-7777 for Talabat Q2, 0 cash
        //   5 logs × 60 orders = 300. income = 60 × 0.900 = 54 per log
        $rajuDays = ['2026-05-04', '2026-05-08', '2026-05-14', '2026-05-19', '2026-05-25'];
        foreach ($rajuDays as $day) {
            DailyLog::create([
                'employee_id' => $raju->id, 'vehicle_id' => $v1->id,
                'contract_id' => $ctTalabat->id, 'created_by' => $admin->id,
                'log_date' => $day,
                'orders_count' => 60, 'orders_online' => 55, 'orders_cash' => 5,
                'cash_collected' => 0, 'cash_settled' => 0, 'cash_pending' => 0,
                'rate_per_order' => 0.900,
                'income_amount' => 54.000,    // 60 × 0.900
            ]);
        }
        // Verify: 5 × 60 = 300 orders, 5 × 54 = 270 income ✓

        // Omar: 100 orders on KW-8888 for Karam Rest (fixed), 0 cash
        //   5 logs × 20 orders = 100. income = 0 (fixed contract, rate=0)
        $omarDays = ['2026-05-04', '2026-05-08', '2026-05-14', '2026-05-19', '2026-05-25'];
        foreach ($omarDays as $day) {
            DailyLog::create([
                'employee_id' => $omar->id, 'vehicle_id' => $v2->id,
                'contract_id' => $ctKaram->id, 'created_by' => $admin->id,
                'log_date' => $day,
                'orders_count' => 20, 'orders_online' => 20, 'orders_cash' => 0,
                'cash_collected' => 0, 'cash_settled' => 0, 'cash_pending' => 0,
                'rate_per_order' => 0,         // Fixed contract
                'income_amount' => 0,          // Revenue comes from fixed_monthly
            ]);
        }
        // Verify: 5 × 20 = 100 orders, income = 0 (fixed = 1500) ✓

        $this->command->info('✓ 15 daily logs (Ahmed=600ord/540inc, Raju=300ord/270inc, Omar=100ord/0inc)');

        // ════════════════════════════════════════════════════════════════
        // 8. VIOLATIONS — Ahmed only, 20 KD, driver-liable
        // ════════════════════════════════════════════════════════════════
        Violation::create([
            'employee_id' => $ahmed->id, 'vehicle_id' => $v1->id,
            'created_by' => $admin->id,
            'violation_date' => '2026-05-10',
            'violation_type' => 'تجاوز سرعة',
            'reference_number' => 'VIO-MS-001',
            'amount' => 20.000,
            'is_driver_liable' => true,
            'is_deducted' => false,
            'notes' => 'Master Scenario — deducted from cash portion',
        ]);
        $this->command->info('✓ 1 violation (Ahmed, 20 KD, driver-liable)');

        // ════════════════════════════════════════════════════════════════
        // 9. LEAVE TYPES (standard)
        // ════════════════════════════════════════════════════════════════
        LeaveType::firstOrCreate(['name' => 'Annual Leave'], [
            'name_ar' => 'إجازة سنوية', 'is_paid' => true,
            'max_days_per_year' => 30, 'requires_approval' => true,
            'penalty_multiplier' => 1.0, 'is_active' => true,
        ]);
        LeaveType::firstOrCreate(['name' => 'Sick Leave'], [
            'name_ar' => 'إجازة مرضية', 'is_paid' => true,
            'max_days_per_year' => 15, 'requires_approval' => true,
            'penalty_multiplier' => 1.0, 'is_active' => true,
        ]);
        LeaveType::firstOrCreate(['name' => 'Unpaid Leave'], [
            'name_ar' => 'إجازة بدون راتب', 'is_paid' => false,
            'max_days_per_year' => null, 'requires_approval' => true,
            'penalty_multiplier' => 1.0, 'is_active' => true,
        ]);
        LeaveType::firstOrCreate(['name' => 'Absence'], [
            'name_ar' => 'غياب بدون إذن', 'is_paid' => false,
            'max_days_per_year' => null, 'requires_approval' => false,
            'penalty_multiplier' => 2.0, 'is_active' => true,
        ]);
        $this->command->info('✓ Leave types seeded');

        // ════════════════════════════════════════════════════════════════
        $this->command->newLine();
        $this->command->info('══════════════════════════════════════════');
        $this->command->info('🚀 Master Scenario Seeded — MAY 2026');
        $this->command->info('   Login: mersal@fleetops.kw / abuhadram');
        $this->command->info('══════════════════════════════════════════');
        $this->command->info('');
        $this->command->info('NEXT: Run payroll for 2026-05 then verify:');
        $this->command->info('  Ahmed → Bank:100 Cash:180 Net:280');
        $this->command->info('  Omar  → Bank:120 Cash:80  Net:200');
        $this->command->info('  Raju  → Bank:90  Cash:0   Net:90');
        $this->command->info('');
        $this->command->info('  KW-7777 → Rev:810  Cost:390 Net:+420');
        $this->command->info('  KW-8888 → Rev:1500 Cost:200 Net:+1300');
    }
}
