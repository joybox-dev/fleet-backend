<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Employee;

class Phase1Seeder extends Seeder
{
    public function run(): void
    {
        // ── Users (5 + admin + accountant) ──
        $users = [
            ['name' => 'Admin', 'email' => 'admin@fleetops.com', 'role' => 'admin'],
            ['name' => 'المحاسب أحمد', 'email' => 'accountant@fleetops.com', 'role' => 'accountant'],
            ['name' => 'مشغّل ١ - خالد', 'email' => 'op1@fleetops.com', 'role' => 'operator'],
            ['name' => 'مشغّل ٢ - سالم', 'email' => 'op2@fleetops.com', 'role' => 'operator'],
            ['name' => 'مشغّل ٣ - فهد', 'email' => 'op3@fleetops.com', 'role' => 'operator'],
            ['name' => 'مشرف - عبدالله', 'email' => 'supervisor@fleetops.com', 'role' => 'operator'],
            ['name' => 'مشغّل ٤ - ناصر', 'email' => 'op4@fleetops.com', 'role' => 'operator'],
        ];
        foreach ($users as $u) {
            User::firstOrCreate(['email' => $u['email']], array_merge($u, ['password' => Hash::make('password')]));
        }
        $this->command->info('✓ 7 users seeded');

        // ── Clients (delivery brands) ──
        $clients = [
            ['name' => 'Yalla Go', 'name_ar' => 'يلا قو', 'contact_person' => 'محمد العلي', 'phone' => '96599001122', 'is_active' => true],
            ['name' => 'Keeta', 'name_ar' => 'كيتا', 'contact_person' => 'سارة أحمد', 'phone' => '96599003344', 'is_active' => true],
            ['name' => 'Talabat', 'name_ar' => 'طلبات', 'contact_person' => 'علي حسن', 'phone' => '96599005566', 'is_active' => true],
        ];
        foreach ($clients as $c) {
            Client::firstOrCreate(['name' => $c['name']], $c);
        }
        $this->command->info('✓ 3 clients seeded');

        // ── Contracts ──
        $yalla = Client::where('name', 'Yalla Go')->first();
        $keeta = Client::where('name', 'Keeta')->first();
        $talabat = Client::where('name', 'Talabat')->first();

        $contracts = [
            ['client_id' => $yalla->id, 'contract_number' => 'YG-2026-Q1', 'name' => 'Yalla Go Q1 2026', 'payment_type' => 'per_order', 'rate_per_order' => 1.350, 'start_date' => '2026-01-01', 'end_date' => '2026-06-30', 'is_active' => true],
            ['client_id' => $keeta->id, 'contract_number' => 'KT-2026-H1', 'name' => 'Keeta H1 2026', 'payment_type' => 'per_order', 'rate_per_order' => 1.000, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true],
            ['client_id' => $talabat->id, 'contract_number' => 'TB-2026-FX', 'name' => 'Talabat Fixed 2026', 'payment_type' => 'fixed', 'fixed_monthly' => 180.000, 'start_date' => '2026-01-01', 'is_active' => true],
            ['client_id' => $yalla->id, 'contract_number' => 'YG-2026-Q2', 'name' => 'Yalla Go Q2 2026', 'payment_type' => 'hybrid', 'rate_per_order' => 0.750, 'fixed_monthly' => 80.000, 'start_date' => '2026-04-01', 'is_active' => true],
        ];
        foreach ($contracts as $c) {
            Contract::firstOrCreate(['contract_number' => $c['contract_number']], $c);
        }
        $this->command->info('✓ 4 contracts seeded');

        // ── Employees (7 drivers) ──
        $employees = [
            ['name' => 'Raju Kumar', 'name_ar' => 'راجو كومار', 'employee_number' => 'EMP-001', 'nationality' => 'Indian', 'civil_id' => '290010001234', 'phone' => '96555001001', 'gender' => 'male', 'date_of_birth' => '1995-03-15', 'date_of_joining' => '2025-06-01', 'employee_type' => 'overseas', 'status' => 'active', 'pay_type' => 'fixed', 'official_salary' => 120.000, 'actual_salary' => 250.000, 'has_end_of_service' => true, 'health_card_expiry' => '2026-08-15', 'residence_expiry' => '2026-12-01', 'driving_license_expiry' => '2027-06-01', 'work_permit_expiry' => '2026-11-30', 'stage_arrived' => true, 'stage_medical_done' => true, 'stage_work_permit_done' => true, 'stage_driving_trial_done' => true, 'stage_license_obtained' => true],
            ['name' => 'Arjun Singh', 'name_ar' => 'أرجون سينغ', 'employee_number' => 'EMP-002', 'nationality' => 'Indian', 'civil_id' => '290010005678', 'phone' => '96555002002', 'gender' => 'male', 'date_of_birth' => '1992-07-20', 'date_of_joining' => '2025-08-15', 'employee_type' => 'overseas', 'status' => 'active', 'pay_type' => 'per_order', 'official_salary' => 100.000, 'actual_salary' => 0, 'rate_per_order' => 0.500, 'health_card_expiry' => '2026-06-10', 'residence_expiry' => '2026-09-15', 'driving_license_expiry' => '2027-08-15', 'work_permit_expiry' => '2026-09-14', 'stage_arrived' => true, 'stage_medical_done' => true, 'stage_work_permit_done' => true, 'stage_driving_trial_done' => true, 'stage_license_obtained' => true],
            ['name' => 'Sunil Thapa', 'name_ar' => 'سونيل ثابا', 'employee_number' => 'EMP-003', 'nationality' => 'Nepalese', 'civil_id' => '290010009012', 'phone' => '96555003003', 'gender' => 'male', 'date_of_birth' => '1998-01-10', 'date_of_joining' => '2026-01-10', 'employee_type' => 'overseas', 'status' => 'active', 'pay_type' => 'hybrid', 'official_salary' => 110.000, 'actual_salary' => 200.000, 'rate_per_order' => 0.350, 'health_card_expiry' => '2027-01-10', 'residence_expiry' => '2027-01-10', 'driving_license_expiry' => '2028-01-10', 'work_permit_expiry' => '2027-01-09', 'stage_arrived' => true, 'stage_medical_done' => true, 'stage_work_permit_done' => true, 'stage_driving_trial_done' => true, 'stage_license_obtained' => true],
            ['name' => 'Amir Hassan', 'name_ar' => 'أمير حسن', 'employee_number' => 'EMP-004', 'nationality' => 'Egyptian', 'civil_id' => '290020001111', 'phone' => '96555004004', 'gender' => 'male', 'date_of_birth' => '1990-11-05', 'date_of_joining' => '2025-03-01', 'employee_type' => 'local_transfer', 'status' => 'active', 'pay_type' => 'fixed', 'official_salary' => 130.000, 'actual_salary' => 280.000, 'has_end_of_service' => true, 'health_card_expiry' => '2026-05-20', 'residence_expiry' => '2026-06-01', 'driving_license_expiry' => '2027-03-01', 'work_permit_expiry' => '2026-05-31', 'stage_arrived' => true, 'stage_medical_done' => true, 'stage_work_permit_done' => true, 'stage_driving_trial_done' => true, 'stage_license_obtained' => true],
            ['name' => 'Mohammed Ali', 'name_ar' => 'محمد علي', 'employee_number' => 'EMP-005', 'nationality' => 'Bangladeshi', 'civil_id' => '290030002222', 'phone' => '96555005005', 'gender' => 'male', 'date_of_birth' => '1997-04-22', 'date_of_joining' => '2026-03-15', 'employee_type' => 'overseas', 'status' => 'probation', 'probation_end_date' => '2026-06-15', 'pay_type' => 'fixed', 'official_salary' => 100.000, 'actual_salary' => 220.000, 'health_card_expiry' => '2027-03-15', 'residence_expiry' => '2027-03-15', 'driving_license_expiry' => '2028-03-15', 'work_permit_expiry' => '2027-03-14', 'stage_arrived' => true, 'stage_medical_done' => true, 'stage_work_permit_done' => true, 'stage_driving_trial_done' => false, 'stage_license_obtained' => false],
            ['name' => 'Deepak Rai', 'name_ar' => 'ديباك راي', 'employee_number' => 'EMP-006', 'nationality' => 'Indian', 'civil_id' => '290010003333', 'phone' => '96555006006', 'gender' => 'male', 'date_of_birth' => '1993-09-18', 'date_of_joining' => '2025-01-01', 'employee_type' => 'overseas', 'status' => 'on_leave', 'status_reason' => 'إجازة سنوية - سفر للهند', 'pay_type' => 'fixed', 'official_salary' => 120.000, 'actual_salary' => 250.000, 'health_card_expiry' => '2026-07-01', 'residence_expiry' => '2026-12-31', 'driving_license_expiry' => '2027-01-01', 'work_permit_expiry' => '2026-12-30', 'stage_arrived' => true, 'stage_medical_done' => true, 'stage_work_permit_done' => true, 'stage_driving_trial_done' => true, 'stage_license_obtained' => true],
            ['name' => 'Kamal Prasad', 'name_ar' => 'كمال براساد', 'employee_number' => 'EMP-007', 'nationality' => 'Nepalese', 'civil_id' => '290010004444', 'phone' => '96555007007', 'gender' => 'male', 'date_of_birth' => '1996-12-03', 'date_of_joining' => '2024-11-01', 'employee_type' => 'overseas', 'status' => 'inactive', 'status_reason' => 'استقالة', 'pay_type' => 'fixed', 'official_salary' => 100.000, 'actual_salary' => 200.000, 'health_card_expiry' => '2025-11-01', 'residence_expiry' => '2025-10-31', 'driving_license_expiry' => '2026-11-01', 'work_permit_expiry' => '2025-10-30', 'stage_arrived' => true, 'stage_medical_done' => true, 'stage_work_permit_done' => true, 'stage_driving_trial_done' => true, 'stage_license_obtained' => true],
        ];
        foreach ($employees as $e) {
            Employee::firstOrCreate(['employee_number' => $e['employee_number']], $e);
        }
        $this->command->info('✓ 7 employees seeded (4 active, 1 probation, 1 on_leave, 1 inactive)');
    }
}
