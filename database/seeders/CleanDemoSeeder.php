<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\DailyLog;
use App\Models\Violation;
use App\Models\MaintenanceRecord;
use App\Models\CustodyItem;
use App\Models\LeaveType;
use App\Models\EmployeeLeave;

/**
 * FleetOps — Clean Demo Seeder
 * 
 * Minimal realistic data for presentation/testing.
 * Login: mersal@fleetops.kw / abuhadram
 */
class CleanDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ════════════════════════════════════════════════════════════════
        // 0. COMPANY CONTEXT — All records will be scoped to this company
        // ════════════════════════════════════════════════════════════════
        $company = \App\Models\Company::firstOrCreate(
            ['code' => 'default'],
            [
                'name'            => 'الشركة الافتراضية',
                'name_ar'         => 'الشركة الافتراضية',
                'is_active'       => true,
                'currency'        => 'KWD',
                'enabled_modules' => \App\Models\Company::DEFAULT_MODULES,
            ]
        );
        app()->instance('current_company_id', $company->id);

        // ════════════════════════════════════════════════════════════════
        // 1. USERS
        // ════════════════════════════════════════════════════════════════
        $admin = User::firstOrCreate(['email' => 'mersal@fleetops.kw'], [
            'name'           => 'Mersal',
            'email'          => 'mersal@fleetops.kw',
            'password'       => Hash::make('abuhadram'),
            'role'           => 'admin',
            'is_super_admin' => true,
            'company_id'     => $company->id,
        ]);

        $operator = User::firstOrCreate(['email' => 'op@fleetops.kw'], [
            'name'       => 'المشغّل',
            'email'      => 'op@fleetops.kw',
            'password'   => Hash::make('abuhadram'),
            'role'       => 'operator',
            'company_id' => $company->id,
        ]);

        // Ensure company_id is set (in case users already existed)
        $admin->update(['company_id' => $company->id]);
        $operator->update(['company_id' => $company->id]);

        $this->command->info('✓ Users: mersal@fleetops.kw / abuhadram (👑 super admin)');

        // ════════════════════════════════════════════════════════════════
        // 2. CLIENTS (3)
        // ════════════════════════════════════════════════════════════════
        $c1 = Client::firstOrCreate(['name' => 'Talabat'], [
            'name' => 'Talabat', 'name_ar' => 'طلبات',
            'contact_person' => 'أحمد الشمري', 'phone' => '96599001100',
            'email' => 'ops@talabat.com', 'is_active' => true,
        ]);
        $c2 = Client::firstOrCreate(['name' => 'Keeta'], [
            'name' => 'Keeta', 'name_ar' => 'كيتا',
            'contact_person' => 'سارة العلي', 'phone' => '96599002200',
            'email' => 'fleet@keeta.com', 'is_active' => true,
        ]);
        $c3 = Client::firstOrCreate(['name' => 'Yalla Go'], [
            'name' => 'Yalla Go', 'name_ar' => 'يلا قو',
            'contact_person' => 'محمد الراشد', 'phone' => '96599003300',
            'email' => 'fleet@yallago.com', 'is_active' => true,
        ]);
        $this->command->info('✓ 3 clients');

        // ════════════════════════════════════════════════════════════════
        // 3. CONTRACTS (3 — one per client)
        // ════════════════════════════════════════════════════════════════
        $ct1 = Contract::firstOrCreate(['contract_number' => 'TB-2026-01'], [
            'client_id' => $c1->id, 'contract_number' => 'TB-2026-01',
            'name' => 'Talabat Q1', 'payment_type' => 'per_order',
            'rate_per_order' => 1.250, 'start_date' => '2026-01-01',
            'end_date' => '2026-06-30', 'is_active' => true, 'is_locked' => true,
        ]);
        $ct2 = Contract::firstOrCreate(['contract_number' => 'KT-2026-01'], [
            'client_id' => $c2->id, 'contract_number' => 'KT-2026-01',
            'name' => 'Keeta H1', 'payment_type' => 'per_order',
            'rate_per_order' => 1.000, 'start_date' => '2026-01-01',
            'end_date' => '2026-12-31', 'is_active' => true, 'is_locked' => true,
        ]);
        $ct3 = Contract::firstOrCreate(['contract_number' => 'YG-2026-FX'], [
            'client_id' => $c3->id, 'contract_number' => 'YG-2026-FX',
            'name' => 'Yalla Go Fixed', 'payment_type' => 'fixed',
            'fixed_monthly' => 200.000, 'start_date' => '2026-01-01',
            'is_active' => true, 'is_locked' => false,
        ]);
        $this->command->info('✓ 3 contracts');

        // ════════════════════════════════════════════════════════════════
        // 4. EMPLOYEES (2)
        // ════════════════════════════════════════════════════════════════
        $e1 = Employee::firstOrCreate(['employee_number' => 'EMP-001'], [
            'name' => 'Raju Kumar', 'name_ar' => 'راجو كومار',
            'employee_number' => 'EMP-001', 'nationality' => 'Indian',
            'civil_id' => '290010001234', 'phone' => '96555001001',
            'gender' => 'male', 'date_of_birth' => '1995-03-15',
            'date_of_joining' => '2025-06-01', 'employee_type' => 'overseas',
            'status' => 'active', 'pay_type' => 'fixed',
            'official_salary' => 120.000, 'actual_salary' => 250.000,
            'has_end_of_service' => true,
            'health_card_expiry' => '2026-08-15',
            'residence_expiry' => '2026-12-01',
            'driving_license_expiry' => '2027-06-01',
            'work_permit_expiry' => '2026-11-30',
            'stage_arrived' => true, 'stage_medical_done' => true,
            'stage_work_permit_done' => true, 'stage_driving_trial_done' => true,
            'stage_license_obtained' => true,
        ]);
        $e2 = Employee::firstOrCreate(['employee_number' => 'EMP-002'], [
            'name' => 'Arjun Singh', 'name_ar' => 'أرجون سينغ',
            'employee_number' => 'EMP-002', 'nationality' => 'Indian',
            'civil_id' => '290010005678', 'phone' => '96555002002',
            'gender' => 'male', 'date_of_birth' => '1992-07-20',
            'date_of_joining' => '2025-08-15', 'employee_type' => 'overseas',
            'status' => 'active', 'pay_type' => 'per_order',
            'official_salary' => 100.000, 'actual_salary' => 0,
            'rate_per_order' => 0.500,
            'health_card_expiry' => '2026-06-10',
            'residence_expiry' => '2026-09-15',
            'driving_license_expiry' => '2027-08-15',
            'work_permit_expiry' => '2026-09-14',
            'stage_arrived' => true, 'stage_medical_done' => true,
            'stage_work_permit_done' => true, 'stage_driving_trial_done' => true,
            'stage_license_obtained' => true,
        ]);
        $this->command->info('✓ 2 employees');

        // ════════════════════════════════════════════════════════════════
        // 5. VEHICLES (3)
        // ════════════════════════════════════════════════════════════════
        $v1 = Vehicle::firstOrCreate(['plate_number' => 'KW-11234'], [
            'plate_number' => 'KW-11234', 'make' => 'Toyota', 'model' => 'Hilux',
            'year' => 2024, 'color' => 'White', 'status' => 'working',
            'odometer_km' => 45200, 'last_oil_change_km' => 44000,
            'oil_change_interval_km' => 4000, 'monthly_fuel_allowance' => 40.000,
            'insurance_expiry' => '2026-10-15',
            'comprehensive_insurance_expiry' => '2026-10-15',
            'food_authority_license_expiry' => '2026-08-20',
            'next_service_due' => '2026-06-01',
        ]);
        $v2 = Vehicle::firstOrCreate(['plate_number' => 'KW-22001'], [
            'plate_number' => 'KW-22001', 'make' => 'Hyundai', 'model' => 'Accent',
            'year' => 2023, 'color' => 'Silver', 'status' => 'working',
            'odometer_km' => 62100, 'last_oil_change_km' => 60000,
            'oil_change_interval_km' => 5000, 'monthly_fuel_allowance' => 35.000,
            'insurance_expiry' => '2026-09-01',
            'food_authority_license_expiry' => '2026-07-10',
            'next_service_due' => '2026-05-25',
        ]);
        $v3 = Vehicle::firstOrCreate(['plate_number' => 'KW-33010'], [
            'plate_number' => 'KW-33010', 'make' => 'Nissan', 'model' => 'Sunny',
            'year' => 2025, 'color' => 'White', 'status' => 'available',
            'odometer_km' => 8200, 'monthly_fuel_allowance' => 30.000,
            'insurance_expiry' => '2027-01-15',
        ]);
        $this->command->info('✓ 3 vehicles');

        // ════════════════════════════════════════════════════════════════
        // 6. VEHICLE ASSIGNMENTS (2 active)
        // ════════════════════════════════════════════════════════════════
        VehicleAssignment::firstOrCreate(
            ['vehicle_id' => $v1->id, 'employee_id' => $e1->id, 'is_active' => true],
            ['contract_id' => $ct1->id, 'assigned_date' => '2026-01-15', 'is_active' => true]
        );
        VehicleAssignment::firstOrCreate(
            ['vehicle_id' => $v2->id, 'employee_id' => $e2->id, 'is_active' => true],
            ['contract_id' => $ct2->id, 'assigned_date' => '2026-02-01', 'is_active' => true]
        );
        $this->command->info('✓ 2 vehicle assignments');

        // ════════════════════════════════════════════════════════════════
        // 7. DAILY LOGS (4 — last few days)
        // ════════════════════════════════════════════════════════════════
        $logs = [
            ['employee_id' => $e1->id, 'vehicle_id' => $v1->id, 'contract_id' => $ct1->id,
             'created_by' => $admin->id, 'log_date' => '2026-05-05',
             'orders_count' => 22, 'orders_online' => 18, 'orders_cash' => 4,
             'cash_collected' => 12.500, 'cash_settled' => 0, 'cash_pending' => 12.500,
             'rate_per_order' => 1.250, 'income_amount' => 27.500,
             'odometer_start' => 44800, 'odometer_end' => 44920],

            ['employee_id' => $e1->id, 'vehicle_id' => $v1->id, 'contract_id' => $ct1->id,
             'created_by' => $admin->id, 'log_date' => '2026-05-06',
             'orders_count' => 19, 'orders_online' => 15, 'orders_cash' => 4,
             'cash_collected' => 10.000, 'cash_settled' => 0, 'cash_pending' => 10.000,
             'rate_per_order' => 1.250, 'income_amount' => 23.750,
             'odometer_start' => 44920, 'odometer_end' => 45050],

            ['employee_id' => $e2->id, 'vehicle_id' => $v2->id, 'contract_id' => $ct2->id,
             'created_by' => $admin->id, 'log_date' => '2026-05-05',
             'orders_count' => 28, 'orders_online' => 25, 'orders_cash' => 3,
             'cash_collected' => 8.000, 'cash_settled' => 0, 'cash_pending' => 8.000,
             'rate_per_order' => 1.000, 'income_amount' => 28.000,
             'odometer_start' => 61800, 'odometer_end' => 61950],

            ['employee_id' => $e2->id, 'vehicle_id' => $v2->id, 'contract_id' => $ct2->id,
             'created_by' => $admin->id, 'log_date' => '2026-05-06',
             'orders_count' => 31, 'orders_online' => 28, 'orders_cash' => 3,
             'cash_collected' => 9.500, 'cash_settled' => 0, 'cash_pending' => 9.500,
             'rate_per_order' => 1.000, 'income_amount' => 31.000,
             'odometer_start' => 61950, 'odometer_end' => 62100],
        ];
        foreach ($logs as $l) {
            DailyLog::firstOrCreate(
                ['employee_id' => $l['employee_id'], 'log_date' => $l['log_date']],
                $l
            );
        }
        $this->command->info('✓ 4 daily logs');

        // ════════════════════════════════════════════════════════════════
        // 8. VIOLATIONS (2)
        // ════════════════════════════════════════════════════════════════
        Violation::firstOrCreate(['reference_number' => 'VIO-2026-001'], [
            'employee_id' => $e1->id, 'vehicle_id' => $v1->id,
            'created_by' => $admin->id, 'violation_date' => '2026-04-20',
            'violation_type' => 'تجاوز سرعة', 'reference_number' => 'VIO-2026-001',
            'amount' => 15.000, 'is_driver_liable' => true, 'is_deducted' => false,
            'notes' => 'تجاوز السرعة المحددة 120 كم/س على الدائري السابع',
        ]);
        Violation::firstOrCreate(['reference_number' => 'VIO-2026-002'], [
            'employee_id' => $e2->id, 'vehicle_id' => $v2->id,
            'created_by' => $admin->id, 'violation_date' => '2026-05-01',
            'violation_type' => 'وقوف خاطئ', 'reference_number' => 'VIO-2026-002',
            'amount' => 10.000, 'is_driver_liable' => true, 'is_deducted' => false,
        ]);
        $this->command->info('✓ 2 violations');

        // ════════════════════════════════════════════════════════════════
        // 9. MAINTENANCE (2 — 1 pending, 1 approved)
        // ════════════════════════════════════════════════════════════════
        MaintenanceRecord::firstOrCreate(
            ['vehicle_id' => $v1->id, 'maintenance_date' => '2026-05-03'],
            [
                'vehicle_id' => $v1->id, 'reported_by' => $admin->id,
                'garage_name' => 'ورشة النور', 'maintenance_type' => 'periodic',
                'maintenance_date' => '2026-05-03', 'estimated_cost' => 25.000,
                'status' => 'pending', 'odometer_km' => 45050,
                'notes' => 'صيانة دورية 45 ألف',
            ]
        );
        MaintenanceRecord::firstOrCreate(
            ['vehicle_id' => $v2->id, 'maintenance_date' => '2026-04-28'],
            [
                'vehicle_id' => $v2->id, 'reported_by' => $admin->id,
                'approved_by' => $admin->id, 'approved_at' => '2026-04-29',
                'garage_name' => 'مركز الخليج للصيانة', 'maintenance_type' => 'repair',
                'maintenance_date' => '2026-04-28', 'estimated_cost' => 35.000,
                'actual_cost' => 42.500, 'status' => 'approved',
                'odometer_km' => 61500,
                'notes' => 'تبديل فلتر هواء وزيت',
            ]
        );
        $this->command->info('✓ 2 maintenance records');

        // ════════════════════════════════════════════════════════════════
        // 10. CUSTODY (3)
        // ════════════════════════════════════════════════════════════════
        CustodyItem::firstOrCreate(
            ['serial_number' => 'IMEI-001122334455'],
            [
                'employee_id' => $e1->id, 'issued_by' => $admin->id,
                'item_type' => 'phone', 'item_description' => 'iPhone 13 - Black',
                'serial_number' => 'IMEI-001122334455', 'value' => 75.000,
                'issued_date' => '2025-06-05', 'is_returned' => false,
            ]
        );
        CustodyItem::firstOrCreate(
            ['serial_number' => 'SIM-99001122'],
            [
                'employee_id' => $e1->id, 'issued_by' => $admin->id,
                'item_type' => 'sim', 'item_description' => 'Zain SIM - Data Plan',
                'serial_number' => 'SIM-99001122', 'value' => 5.000,
                'issued_date' => '2025-06-05', 'is_returned' => false,
            ]
        );
        CustodyItem::firstOrCreate(
            ['employee_id' => $e2->id, 'item_type' => 'clothing', 'item_description' => 'زي موحد - مقاس L'],
            [
                'employee_id' => $e2->id, 'issued_by' => $admin->id,
                'item_type' => 'clothing', 'item_description' => 'زي موحد - مقاس L',
                'value' => 14.000, 'issued_date' => '2025-08-20',
                'is_returned' => false,
            ]
        );
        $this->command->info('✓ 3 custody items');

        // ════════════════════════════════════════════════════════════════
        // 11. LEAVE TYPES (5)
        // ════════════════════════════════════════════════════════════════
        $lt1 = LeaveType::firstOrCreate(['name' => 'Annual Leave'], [
            'name' => 'Annual Leave', 'name_ar' => 'إجازة سنوية',
            'is_paid' => true, 'max_days_per_year' => 30,
            'requires_approval' => true, 'penalty_multiplier' => 1.0,
        ]);
        $lt2 = LeaveType::firstOrCreate(['name' => 'Sick Leave'], [
            'name' => 'Sick Leave', 'name_ar' => 'إجازة مرضية',
            'is_paid' => true, 'max_days_per_year' => 15,
            'requires_approval' => true, 'penalty_multiplier' => 1.0,
        ]);
        $lt3 = LeaveType::firstOrCreate(['name' => 'Unpaid Leave'], [
            'name' => 'Unpaid Leave', 'name_ar' => 'إجازة بدون راتب',
            'is_paid' => false, 'max_days_per_year' => null,
            'requires_approval' => true, 'penalty_multiplier' => 1.0,
        ]);
        $lt4 = LeaveType::firstOrCreate(['name' => 'Emergency Leave'], [
            'name' => 'Emergency Leave', 'name_ar' => 'إجازة طارئة',
            'is_paid' => true, 'max_days_per_year' => 3,
            'requires_approval' => true, 'penalty_multiplier' => 1.0,
        ]);
        $lt5 = LeaveType::firstOrCreate(['name' => 'Absence'], [
            'name' => 'Absence', 'name_ar' => 'غياب بدون إذن',
            'is_paid' => false, 'max_days_per_year' => null,
            'requires_approval' => false, 'penalty_multiplier' => 2.0,
        ]);
        $this->command->info('✓ 5 leave types');

        // ════════════════════════════════════════════════════════════════
        // 12. EMPLOYEE LEAVES (3 — sample records)
        // ════════════════════════════════════════════════════════════════
        EmployeeLeave::firstOrCreate(
            ['employee_id' => $e1->id, 'start_date' => '2026-04-10'],
            [
                'employee_id' => $e1->id, 'leave_type_id' => $lt1->id,
                'start_date' => '2026-04-10', 'end_date' => '2026-04-12',
                'days_count' => 3, 'status' => 'approved',
                'is_paid' => true, 'daily_rate' => 8.333,
                'penalty_multiplier' => 1.0, 'formula_version' => 'v1_actual_div_30',
                'total_deduction' => 0,
                'approved_by' => $admin->id, 'approved_at' => '2026-04-09',
                'reason' => 'إجازة شخصية',
            ]
        );
        EmployeeLeave::firstOrCreate(
            ['employee_id' => $e1->id, 'start_date' => '2026-05-15'],
            [
                'employee_id' => $e1->id, 'leave_type_id' => $lt3->id,
                'start_date' => '2026-05-15', 'end_date' => '2026-05-17',
                'days_count' => 3, 'status' => 'approved',
                'is_paid' => false, 'daily_rate' => 8.333,
                'penalty_multiplier' => 1.0, 'formula_version' => 'v1_actual_div_30',
                'total_deduction' => 24.999,
                'approved_by' => $admin->id, 'approved_at' => '2026-05-14',
                'reason' => 'ظروف عائلية',
            ]
        );
        EmployeeLeave::firstOrCreate(
            ['employee_id' => $e2->id, 'start_date' => '2026-05-20'],
            [
                'employee_id' => $e2->id, 'leave_type_id' => $lt2->id,
                'start_date' => '2026-05-20', 'end_date' => '2026-05-21',
                'days_count' => 2, 'status' => 'pending',
                'is_paid' => true, 'daily_rate' => 0,
                'penalty_multiplier' => 1.0, 'formula_version' => 'v1_actual_div_30',
                'total_deduction' => 0,
                'reason' => 'زيارة طبيب',
            ]
        );
        $this->command->info('✓ 3 employee leaves');

        // ════════════════════════════════════════════════════════════════
        $this->command->newLine();
        $this->command->info('🚀 FleetOps Clean Demo seeded successfully!');
        $this->command->info('   Login: mersal@fleetops.kw / abuhadram');
    }
}
