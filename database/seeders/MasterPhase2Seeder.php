<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\User;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\EvaluationCriterion;

/**
 * Phase 2: Master Data — Clients, Contracts, Employees, Vehicles, Assignments, Eval Criteria
 */
class MasterPhase2Seeder extends Seeder
{
    public function run(): void
    {
        $eagle = Company::where('code', 'eagle')->first();
        $buraq = Company::where('code', 'buraq')->first();
        $admin = User::where('email', 'mersal@fleetops.kw')->first();

        // ═══════════════════════════════════════════════════
        // COMPANY 1: EAGLE — Clients + Contracts
        // ═══════════════════════════════════════════════════
        app()->instance('current_company_id', $eagle->id);

        $clTalabat = Client::firstOrCreate(['name' => 'Talabat Kuwait', 'company_id' => $eagle->id], [
            'name' => 'Talabat Kuwait', 'name_ar' => 'طلبات الكويت',
            'contact_person' => 'أحمد الشمري', 'phone' => '96599001100',
            'email' => 'ops@talabat.com', 'is_active' => true, 'company_id' => $eagle->id,
        ]);
        $clKaram = Client::firstOrCreate(['name' => 'Karam Al-Sham', 'company_id' => $eagle->id], [
            'name' => 'Karam Al-Sham', 'name_ar' => 'مطعم كرم الشام',
            'contact_person' => 'خالد كرم', 'phone' => '96599002200',
            'is_active' => true, 'company_id' => $eagle->id,
        ]);
        $clCarriage = Client::firstOrCreate(['name' => 'Carriage Kuwait', 'company_id' => $eagle->id], [
            'name' => 'Carriage Kuwait', 'name_ar' => 'كاريدج الكويت',
            'contact_person' => 'سارة العلي', 'phone' => '96599003300',
            'is_active' => true, 'company_id' => $eagle->id,
        ]);

        $ctTalabat = Contract::firstOrCreate(['contract_number' => 'TB-Q2-2026', 'company_id' => $eagle->id], [
            'client_id' => $clTalabat->id, 'name' => 'Talabat Q2',
            'payment_type' => 'per_order', 'rate_per_order' => 0.900,
            'start_date' => '2026-04-01', 'end_date' => '2026-06-30',
            'is_active' => true, 'is_locked' => true, 'company_id' => $eagle->id,
        ]);
        $ctKaram = Contract::firstOrCreate(['contract_number' => 'KR-MAY-2026', 'company_id' => $eagle->id], [
            'client_id' => $clKaram->id, 'name' => 'Karam May',
            'payment_type' => 'fixed', 'fixed_monthly' => 1500.000,
            'start_date' => '2026-04-01', 'end_date' => '2026-12-31',
            'is_active' => true, 'is_locked' => true, 'company_id' => $eagle->id,
        ]);
        $ctCarriage = Contract::firstOrCreate(['contract_number' => 'CR-MAY-2026', 'company_id' => $eagle->id], [
            'client_id' => $clCarriage->id, 'name' => 'Carriage May',
            'payment_type' => 'hybrid', 'rate_per_order' => 0.400, 'fixed_monthly' => 800.000,
            'start_date' => '2026-05-01', 'end_date' => '2026-12-31',
            'is_active' => true, 'company_id' => $eagle->id,
        ]);

        $this->command->info('✓ Eagle: 3 clients, 3 contracts (per_order + fixed + hybrid)');

        // ═══ Eagle Employees ═══
        $ahmed = Employee::firstOrCreate(['employee_number' => 'EG-001', 'company_id' => $eagle->id], [
            'name' => 'Ahmed Hassan', 'name_ar' => 'أحمد حسن',
            'employee_number' => 'EG-001', 'nationality' => 'Egyptian',
            'civil_id' => '280010001001', 'phone' => '96555001001',
            'gender' => 'male', 'date_of_birth' => '1993-05-10',
            'date_of_joining' => '2025-06-01', 'employee_type' => 'overseas',
            'status' => 'active', 'pay_type' => 'hybrid',
            'official_salary' => 100.000, 'actual_salary' => 150.000,
            'rate_per_order' => 0.250,
            'health_card_expiry' => '2026-06-15',
            'residence_expiry' => '2026-12-01',
            'driving_license_expiry' => '2027-03-01',
            'work_permit_expiry' => '2027-01-15',
            'stage_arrived' => true, 'stage_medical_done' => true,
            'stage_work_permit_done' => true, 'stage_driving_trial_done' => true,
            'stage_license_obtained' => true,
            'company_id' => $eagle->id,
        ]);
        $omar = Employee::firstOrCreate(['employee_number' => 'EG-002', 'company_id' => $eagle->id], [
            'name' => 'Omar Al-Rashid', 'name_ar' => 'عمر الراشد',
            'employee_number' => 'EG-002', 'nationality' => 'Kuwaiti',
            'civil_id' => '280010001002', 'phone' => '96555001002',
            'gender' => 'male', 'date_of_birth' => '1990-08-20',
            'date_of_joining' => '2025-03-01', 'employee_type' => 'local',
            'status' => 'active', 'pay_type' => 'fixed',
            'official_salary' => 120.000, 'actual_salary' => 200.000,
            'rate_per_order' => 0,
            'driving_license_expiry' => '2026-06-30',
            'company_id' => $eagle->id,
        ]);
        $raju = Employee::firstOrCreate(['employee_number' => 'EG-003', 'company_id' => $eagle->id], [
            'name' => 'Raju Kumar', 'name_ar' => 'راجو كومار',
            'employee_number' => 'EG-003', 'nationality' => 'Indian',
            'civil_id' => '280010001003', 'phone' => '96555001003',
            'gender' => 'male', 'date_of_birth' => '1995-03-15',
            'date_of_joining' => '2025-08-01', 'employee_type' => 'overseas',
            'status' => 'active', 'pay_type' => 'per_order',
            'official_salary' => 100.000, 'actual_salary' => 0,
            'rate_per_order' => 0.300,
            'work_permit_expiry' => '2026-06-25',
            'stage_arrived' => true, 'stage_medical_done' => true,
            'stage_work_permit_done' => true, 'stage_driving_trial_done' => true,
            'stage_license_obtained' => true,
            'company_id' => $eagle->id,
        ]);
        // Khaled joins in May — date_of_joining = 2026-05-01
        $khaled = Employee::firstOrCreate(['employee_number' => 'EG-004', 'company_id' => $eagle->id], [
            'name' => 'Khaled Al-Mutairi', 'name_ar' => 'خالد المطيري',
            'employee_number' => 'EG-004', 'nationality' => 'Kuwaiti',
            'civil_id' => '280010001004', 'phone' => '96555001004',
            'gender' => 'male', 'date_of_birth' => '1991-11-05',
            'date_of_joining' => '2026-05-01', 'employee_type' => 'local',
            'status' => 'active', 'pay_type' => 'hybrid',
            'official_salary' => 130.000, 'actual_salary' => 180.000,
            'rate_per_order' => 0.200,
            'health_card_expiry' => '2027-01-15',
            'company_id' => $eagle->id,
        ]);

        $this->command->info('✓ Eagle: 4 employees (Ahmed, Omar, Raju, Khaled)');

        // ═══ Eagle Vehicles ═══
        $vKW7777 = Vehicle::firstOrCreate(['plate_number' => 'KW-7777', 'company_id' => $eagle->id], [
            'make' => 'Toyota', 'model' => 'HiAce', 'year' => 2023, 'color' => 'White',
            'status' => 'working', 'odometer_km' => 45200,
            'monthly_fuel_allowance' => 40.000,
            'insurance_expiry' => '2026-07-01',
            'company_id' => $eagle->id,
        ]);
        $vKW8888 = Vehicle::firstOrCreate(['plate_number' => 'KW-8888', 'company_id' => $eagle->id], [
            'make' => 'Nissan', 'model' => 'Urvan', 'year' => 2022, 'color' => 'Silver',
            'status' => 'working', 'odometer_km' => 62000,
            'monthly_fuel_allowance' => 35.000,
            'food_authority_license_expiry' => '2026-08-15',
            'company_id' => $eagle->id,
        ]);
        $vKW9999 = Vehicle::firstOrCreate(['plate_number' => 'KW-9999', 'company_id' => $eagle->id], [
            'make' => 'Mitsubishi', 'model' => 'L300', 'year' => 2024, 'color' => 'White',
            'status' => 'working', 'odometer_km' => 15000,
            'monthly_fuel_allowance' => 45.000,
            'comprehensive_insurance_expiry' => '2027-02-01',
            'company_id' => $eagle->id,
        ]);

        $this->command->info('✓ Eagle: 3 vehicles (KW-7777, KW-8888, KW-9999)');

        // ═══ Eagle Assignments ═══
        VehicleAssignment::firstOrCreate(
            ['vehicle_id' => $vKW7777->id, 'employee_id' => $ahmed->id, 'is_active' => true],
            ['contract_id' => $ctTalabat->id, 'assigned_date' => '2026-03-01', 'is_active' => true, 'company_id' => $eagle->id]
        );
        VehicleAssignment::firstOrCreate(
            ['vehicle_id' => $vKW8888->id, 'employee_id' => $omar->id, 'is_active' => true],
            ['contract_id' => $ctKaram->id, 'assigned_date' => '2026-03-01', 'is_active' => true, 'company_id' => $eagle->id]
        );
        VehicleAssignment::firstOrCreate(
            ['vehicle_id' => $vKW9999->id, 'employee_id' => $khaled->id, 'is_active' => true],
            ['contract_id' => $ctCarriage->id, 'assigned_date' => '2026-05-01', 'is_active' => true, 'company_id' => $eagle->id]
        );

        $this->command->info('✓ Eagle: 3 vehicle assignments');

        // ═══════════════════════════════════════════════════
        // COMPANY 2: BURAQ — Clients + Contracts
        // ═══════════════════════════════════════════════════
        app()->instance('current_company_id', $buraq->id);

        $clDeliveroo = Client::firstOrCreate(['name' => 'Deliveroo Kuwait', 'company_id' => $buraq->id], [
            'name' => 'Deliveroo Kuwait', 'name_ar' => 'ديليفرو الكويت',
            'contact_person' => 'فهد الكندري', 'phone' => '96598001100',
            'is_active' => true, 'company_id' => $buraq->id,
        ]);
        $clSawan = Client::firstOrCreate(['name' => 'Al-Sawan Restaurant', 'company_id' => $buraq->id], [
            'name' => 'Al-Sawan Restaurant', 'name_ar' => 'مطعم الصوان',
            'contact_person' => 'علي الصوان', 'phone' => '96598002200',
            'is_active' => true, 'company_id' => $buraq->id,
        ]);

        $ctDeliveroo = Contract::firstOrCreate(['contract_number' => 'DL-Q2-2026', 'company_id' => $buraq->id], [
            'client_id' => $clDeliveroo->id, 'name' => 'Deliveroo Q2',
            'payment_type' => 'per_order', 'rate_per_order' => 1.100,
            'start_date' => '2026-04-01', 'end_date' => '2026-06-30',
            'is_active' => true, 'is_locked' => true, 'company_id' => $buraq->id,
        ]);
        $ctSawan = Contract::firstOrCreate(['contract_number' => 'SW-MAY-2026', 'company_id' => $buraq->id], [
            'client_id' => $clSawan->id, 'name' => 'Al-Sawan May',
            'payment_type' => 'fixed', 'fixed_monthly' => 2000.000,
            'start_date' => '2026-05-01', 'end_date' => '2026-12-31',
            'is_active' => true, 'company_id' => $buraq->id,
        ]);

        $this->command->info('✓ Buraq: 2 clients, 2 contracts');

        // ═══ Buraq Employees ═══
        $yousef = Employee::firstOrCreate(['employee_number' => 'BQ-001', 'company_id' => $buraq->id], [
            'name' => 'Yousef Al-Ali', 'name_ar' => 'يوسف العلي',
            'employee_number' => 'BQ-001', 'nationality' => 'Kuwaiti',
            'civil_id' => '280020001001', 'phone' => '96555002001',
            'gender' => 'male', 'date_of_birth' => '1988-02-14',
            'date_of_joining' => '2025-01-01', 'employee_type' => 'local',
            'status' => 'active', 'pay_type' => 'fixed',
            'official_salary' => 150.000, 'actual_salary' => 250.000,
            'rate_per_order' => 0,
            'driving_license_expiry' => '2027-03-01',
            'company_id' => $buraq->id,
        ]);
        $mohammad = Employee::firstOrCreate(['employee_number' => 'BQ-002', 'company_id' => $buraq->id], [
            'name' => 'Mohammad Reza', 'name_ar' => 'محمد رضا',
            'employee_number' => 'BQ-002', 'nationality' => 'Iranian',
            'civil_id' => '280020001002', 'phone' => '96555002002',
            'gender' => 'male', 'date_of_birth' => '1994-09-10',
            'date_of_joining' => '2025-06-01', 'employee_type' => 'overseas',
            'status' => 'active', 'pay_type' => 'per_order',
            'official_salary' => 90.000, 'actual_salary' => 0,
            'rate_per_order' => 0.350,
            'work_permit_expiry' => '2026-06-10',
            'health_card_expiry' => '2026-05-20',
            'stage_arrived' => true, 'stage_medical_done' => true,
            'stage_work_permit_done' => true, 'stage_driving_trial_done' => true,
            'stage_license_obtained' => true,
            'company_id' => $buraq->id,
        ]);
        $salem = Employee::firstOrCreate(['employee_number' => 'BQ-003', 'company_id' => $buraq->id], [
            'name' => 'Salem Al-Harbi', 'name_ar' => 'سالم الحربي',
            'employee_number' => 'BQ-003', 'nationality' => 'Kuwaiti',
            'civil_id' => '280020001003', 'phone' => '96555002003',
            'gender' => 'male', 'date_of_birth' => '1992-12-25',
            'date_of_joining' => '2025-04-01', 'employee_type' => 'local',
            'status' => 'active', 'pay_type' => 'hybrid',
            'official_salary' => 110.000, 'actual_salary' => 160.000,
            'rate_per_order' => 0.200,
            'residence_expiry' => '2026-07-10',
            'company_id' => $buraq->id,
        ]);

        $this->command->info('✓ Buraq: 3 employees (Yousef, Mohammad, Salem)');

        // ═══ Buraq Vehicles ═══
        $vKW1234 = Vehicle::firstOrCreate(['plate_number' => 'KW-1234', 'company_id' => $buraq->id], [
            'make' => 'Toyota', 'model' => 'Hilux', 'year' => 2024, 'color' => 'White',
            'status' => 'working', 'odometer_km' => 22000,
            'monthly_fuel_allowance' => 50.000,
            'food_authority_license_expiry' => '2027-01-01',
            'company_id' => $buraq->id,
        ]);
        $vKW5678 = Vehicle::firstOrCreate(['plate_number' => 'KW-5678', 'company_id' => $buraq->id], [
            'make' => 'Hyundai', 'model' => 'H100', 'year' => 2023, 'color' => 'Silver',
            'status' => 'working', 'odometer_km' => 38000,
            'monthly_fuel_allowance' => 40.000,
            'insurance_expiry' => '2026-06-05',
            'company_id' => $buraq->id,
        ]);
        $vKW4321 = Vehicle::firstOrCreate(['plate_number' => 'KW-4321', 'company_id' => $buraq->id], [
            'make' => 'Kia', 'model' => 'Bongo', 'year' => 2022, 'color' => 'Blue',
            'status' => 'available', 'odometer_km' => 55000,
            'monthly_fuel_allowance' => 35.000,
            'comprehensive_insurance_expiry' => '2026-05-18',
            'company_id' => $buraq->id,
        ]);

        $this->command->info('✓ Buraq: 3 vehicles (KW-1234, KW-5678, KW-4321)');

        // ═══ Buraq Assignments ═══
        VehicleAssignment::firstOrCreate(
            ['vehicle_id' => $vKW1234->id, 'employee_id' => $yousef->id, 'is_active' => true],
            ['contract_id' => $ctSawan->id, 'assigned_date' => '2026-03-01', 'is_active' => true, 'company_id' => $buraq->id]
        );
        VehicleAssignment::firstOrCreate(
            ['vehicle_id' => $vKW5678->id, 'employee_id' => $mohammad->id, 'is_active' => true],
            ['contract_id' => $ctDeliveroo->id, 'assigned_date' => '2026-04-01', 'is_active' => true, 'company_id' => $buraq->id]
        );

        $this->command->info('✓ Buraq: 2 vehicle assignments');

        // ═══ Evaluation Criteria (both companies) ═══
        foreach ([$eagle->id, $buraq->id] as $cId) {
            EvaluationCriterion::firstOrCreate(['name' => 'Work Performance', 'company_id' => $cId], [
                'name' => 'Work Performance', 'name_ar' => 'أداء العمل',
                'weight' => 40, 'is_active' => true, 'company_id' => $cId,
            ]);
            EvaluationCriterion::firstOrCreate(['name' => 'Punctuality', 'company_id' => $cId], [
                'name' => 'Punctuality', 'name_ar' => 'الالتزام بالمواعيد',
                'weight' => 30, 'is_active' => true, 'company_id' => $cId,
            ]);
            EvaluationCriterion::firstOrCreate(['name' => 'Customer Service', 'company_id' => $cId], [
                'name' => 'Customer Service', 'name_ar' => 'خدمة العملاء',
                'weight' => 30, 'is_active' => true, 'company_id' => $cId,
            ]);
        }

        $this->command->info('✓ 3 evaluation criteria per company');
    }
}
