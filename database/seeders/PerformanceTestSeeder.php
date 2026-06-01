<?php
 
namespace Database\Seeders;
 
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Company;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\DailyLog;
use App\Models\SalaryAdvance;
use App\Models\AdvanceDeduction;
use App\Models\Violation;
use App\Models\CustodyItem;
use App\Models\CustodyType;
use App\Models\EmployeeLeave;
use App\Models\LeaveType;
use App\Models\MaintenanceRecord;
use App\Models\EvaluationCriterion;
use App\Models\EmployeeEvaluation;
use App\Models\EvaluationScore;
use App\Models\PayrollRun;
use App\Models\PayrollSlip;
 
class PerformanceTestSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🚀 Starting Giant Performance Test Seeder (1,000 Employees & Vehicles)...');
        
        // 1. Resolve Mersal Company
        $company = Company::where('code', 'mersal')->first();
        if (!$company) {
            $company = Company::create([
                'code' => 'mersal',
                'name' => 'Mersal Company',
                'name_ar' => 'شركة مرسال للتوصيل',
                'is_active' => true,
                'currency' => 'KWD',
                'enabled_modules' => Company::DEFAULT_MODULES,
            ]);
        }
        $companyId = $company->id;
        app()->instance('current_company_id', $companyId);

        // Resolve Admin User ID dynamically to prevent FOREIGN KEY constraint violations
        $adminUser = \App\Models\User::where('email', 'mersal@fleetops.kw')->first() 
            ?: \App\Models\User::where('company_id', $companyId)->first() 
            ?: \App\Models\User::first();
            
        if (!$adminUser) {
            $adminUser = \App\Models\User::create([
                'name' => 'Mersal',
                'email' => 'mersal@fleetops.kw',
                'password' => Hash::make('abuhadram'),
                'role' => 'admin',
                'is_super_admin' => true,
                'company_id' => $companyId,
            ]);
        }
        $adminUserId = $adminUser->id;
 
        // Wrap everything in a single transaction for maximum SQLite write speed (seconds instead of minutes)
        DB::beginTransaction();
 
        try {
            // 2. Seed 10 Clients and 10 Contracts
            $this->command->info('→ Seeding 10 Contracts...');
            $clientNames = ['Deliveroo', 'Talabat', 'Keeta', 'Movo', 'Jahez', 'Cari', 'HungerStation', 'Snoonu', 'Waza', 'Deliveroo Express'];
            $contracts = [];
 
            foreach ($clientNames as $index => $cName) {
                $client = Client::firstOrCreate([
                    'name' => $cName,
                    'company_id' => $companyId
                ], [
                    'name_ar' => $cName === 'Deliveroo' ? 'دليفري' : ($cName === 'Talabat' ? 'طلبات' : $cName),
                    'phone' => '9659000000' . $index,
                    'is_active' => true,
                    'company_id' => $companyId,
                ]);
 
                $contract = Contract::firstOrCreate([
                    'contract_number' => 'CON-PERF-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT),
                    'company_id' => $companyId
                ], [
                    'client_id' => $client->id,
                    'name' => $cName . ' Contract',
                    'payment_type' => 'per_order',
                    'rate_per_order' => 1.250,
                    'start_date' => '2026-05-01',
                    'end_date' => '2027-05-01',
                    'is_active' => true,
                    'required_drivers' => 120,
                    'daily_target' => 500,
                    'monthly_target' => 15000,
                    'expected_monthly_revenue' => 18750.000,
                    'target_driver_count' => 120,
                    'company_id' => $companyId,
                ]);
 
                $contracts[] = $contract;
            }
 
            // 3. Prepare Arabic Names pool to generate realistic drivers
            $firstNames = ['احمد', 'محمد', 'علي', 'عبدالله', 'خالد', 'فهد', 'عبدالرحمن', 'صالح', 'يوسف', 'سعد', 'سليمان', 'فيصل', 'حسين', 'حسن', 'عمر', 'ابراهيم', 'ماجد', 'سلطان', 'بدر', 'ناصر'];
            $lastNames = ['الحربي', 'المطيري', 'العتيبي', 'الرشيدي', 'العجمي', 'الشمري', 'العنزي', 'الدوسري', 'القحطاني', 'السهلي', 'الخالدي', 'الهاجري', 'الفضلي', 'العازمي', 'الميموني', 'الظفيري', 'السبيعي', 'الشلاحي', 'المطوع', 'الغانم'];
 
            // 4. Seeding 1,000 Vehicles & 1,000 Employees (Drivers)
            $this->command->info('→ Seeding 1,000 Vehicles and 1,000 Employees...');
            
            $vehicleModels = [
                ['make' => 'Toyota', 'model' => 'Corolla'],
                ['make' => 'Nissan', 'model' => 'Sunny'],
                ['make' => 'Hyundai', 'model' => 'Elantra'],
                ['make' => 'Kia', 'model' => 'Cerato'],
                ['make' => 'Honda', 'model' => 'Civic']
            ];
 
            $employeesToInsert = [];
            $vehiclesToInsert = [];
            
            for ($i = 1; $i <= 1000; $i++) {
                // Generate Employee fields
                $fName = $firstNames[array_rand($firstNames)];
                $lName = $lastNames[array_rand($lastNames)];
                $fullNameAr = $fName . ' ' . $lName . ' ' . $i;
                $fullNameEn = 'Driver ' . $i;
                $empNum = 'EMP-' . str_pad($i + 1000, 4, '0', STR_PAD_LEFT);
                $civilId = '296' . str_pad($i, 9, '0', STR_PAD_LEFT);
                $phone = '965' . str_pad(50000000 + $i, 8, '0', STR_PAD_LEFT);
 
                $employeesToInsert[] = [
                    'company_id' => $companyId,
                    'name' => $fullNameEn,
                    'name_ar' => $fullNameAr,
                    'employee_number' => $empNum,
                    'nationality' => 'Indian',
                    'civil_id' => $civilId,
                    'phone' => $phone,
                    'gender' => 'male',
                    'date_of_birth' => '1996-05-15',
                    'date_of_joining' => '2026-05-01',
                    'employee_type' => 'overseas',
                    'status' => 'active',
                    'pay_type' => 'hybrid',
                    'official_salary' => 100.000,
                    'actual_salary' => 150.000,
                    'rate_per_order' => 0.250,
                    'target_orders_monthly' => 41,
                    'base_commission_rate' => 0.250,
                    'premium_commission_rate' => 0.500,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
 
                // Generate Vehicle fields
                $vModel = $vehicleModels[$i % count($vehicleModels)];
                $plateNum = str_pad($i + 20000, 5, '0', STR_PAD_LEFT) . '/4';
                $vin = 'VIN-PERF-' . str_pad($i, 8, '0', STR_PAD_LEFT);
                
                // Set odometer readings realistically
                $odometer = rand(15000, 60000);
                // Make some vehicles exceed their oil change limit (4,000 km) for alerts!
                // If $i is divisible by 7, the distance since last oil change is 4150 km (Needs oil warning)
                // Otherwise, it is 1200 km (Clean state)
                $lastOilChange = ($i % 7 === 0) ? ($odometer - 4150) : ($odometer - 1200);
 
                $vehiclesToInsert[] = [
                    'company_id' => $companyId,
                    'plate_number' => $plateNum,
                    'make' => $vModel['make'],
                    'model' => $vModel['model'],
                    'year' => rand(2022, 2025),
                    'color' => ['White', 'Silver', 'Grey', 'Black'][$i % 4],
                    'vin' => $vin,
                    'status' => 'working',
                    'odometer_km' => $odometer,
                    'last_oil_change_km' => $lastOilChange,
                    'oil_change_interval_km' => 4000,
                    'monthly_fuel_allowance' => 0.000,
                    'ownership_type' => 'owned',
                    'insurance_expiry' => '2027-05-01',
                    'comprehensive_insurance_expiry' => '2027-05-01',
                    'food_authority_license_expiry' => '2027-05-01',
                    'next_service_due' => '2026-12-01',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
 
            // Bulk insert in chunks to avoid SQLite's 999 SQL variables limit
            foreach (array_chunk($employeesToInsert, 40) as $chunk) {
                Employee::insert($chunk);
            }
            foreach (array_chunk($vehiclesToInsert, 50) as $chunk) {
                Vehicle::insert($chunk);
            }
 
            // Retrieve seeded IDs
            $seededEmployees = Employee::where('company_id', $companyId)->orderBy('id')->get();
            $seededVehicles = Vehicle::where('company_id', $companyId)->orderBy('id')->get();
 
            // 5. Create active assignments and daily logs
            $this->command->info('→ Seeding active Assignments, 2,000 Daily Logs and details...');
            $assignmentsToInsert = [];
            $logsToInsert = [];
 
            $advancesToInsert = [];
            $violationsToInsert = [];
            $custodiesToInsert = [];
            $leavesToInsert = [];
            $maintenancesToInsert = [];
 
            // Get a default LeaveType and CustodyType
            $leaveType = LeaveType::where('name', 'Absence')->where('company_id', $companyId)->first();
            if (!$leaveType) {
                $leaveType = LeaveType::create([
                    'name' => 'Absence',
                    'name_ar' => 'غياب بدون إذن',
                    'is_paid' => false,
                    'penalty_multiplier' => 2.0,
                    'requires_approval' => false,
                    'company_id' => $companyId,
                    'is_active' => true,
                ]);
            }
 
            $custodyType = CustodyType::where('name', 'Mobile Phone')->where('company_id', $companyId)->first();
            if (!$custodyType) {
                $custodyType = CustodyType::create([
                    'name' => 'Mobile Phone',
                    'icon' => '📱',
                    'company_id' => $companyId,
                ]);
            }
 
            foreach ($seededEmployees as $index => $emp) {
                $veh = $seededVehicles[$index];
                $contract = $contracts[$index % count($contracts)];
 
                // Assignment
                $assignmentsToInsert[] = [
                    'company_id' => $companyId,
                    'vehicle_id' => $veh->id,
                    'employee_id' => $emp->id,
                    'contract_id' => $contract->id,
                    'assigned_date' => '2026-05-01',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
 
                // Daily Log 1: May 15, 2026 (20 orders, 12 online, 8 cash, 40 KWD cash collected)
                // Odometer Start: $veh->odometer_km - 100
                // Odometer End: $veh->odometer_km - 50
                // Commision: under target (40 orders limit not reached yet), so 20 * 0.25 = 5 KWD
                $logsToInsert[] = [
                    'company_id' => $companyId,
                    'employee_id' => $emp->id,
                    'vehicle_id' => $veh->id,
                    'contract_id' => $contract->id,
                    'created_by' => $adminUserId,
                    'log_date' => '2026-05-15',
                    'orders_count' => 20,
                    'orders_online' => 12,
                    'orders_cash' => 8,
                    'cash_collected' => 40.000,
                    'cash_settled' => 0.000,
                    'cash_pending' => 40.000,
                    'rate_per_order' => 1.250,
                    'income_amount' => 25.000,
                    'odometer_start' => $veh->odometer_km - 100,
                    'odometer_end' => $veh->odometer_km - 50,
                    'driver_commission' => 5.000, // 20 * 0.25
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
 
                // Daily Log 2: May 16, 2026 (25 orders, 15 online, 10 cash, 50 KWD cash collected)
                // Odometer Start: $veh->odometer_km - 50
                // Odometer End: $veh->odometer_km
                // Commission: Driver cumulative orders = 45 (threshold 41 reached on order #41).
                // 20 orders from Day 1 + 20 orders from Day 2 = 40 orders under target (* 0.25 = 10 KWD)
                // 5 orders above target (* 0.50 = 2.50 KWD)
                // Commission for Log 2 = (20 * 0.25) + (5 * 0.50) = 7.50 KWD.
                $logsToInsert[] = [
                    'company_id' => $companyId,
                    'employee_id' => $emp->id,
                    'vehicle_id' => $veh->id,
                    'contract_id' => $contract->id,
                    'created_by' => $adminUserId,
                    'log_date' => '2026-05-16',
                    'orders_count' => 25,
                    'orders_online' => 15,
                    'orders_cash' => 10,
                    'cash_collected' => 50.000,
                    'cash_settled' => 0.000,
                    'cash_pending' => 50.000,
                    'rate_per_order' => 1.250,
                    'income_amount' => 31.250,
                    'odometer_start' => $veh->odometer_km - 50,
                    'odometer_end' => $veh->odometer_km,
                    'driver_commission' => 7.500, // (20 * 0.25) + (5 * 0.5)
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
 
                // ── Distribute financial parameters across ~10% subset of drivers ──
                
                // 10% of drivers have an active salary advance
                if ($index % 10 === 0) {
                    $advancesToInsert[] = [
                        'company_id' => $companyId,
                        'employee_id' => $emp->id,
                        'approved_by' => $adminUserId,
                        'amount' => 100.000,
                        'monthly_installment' => 25.000,
                        'total_installments' => 4,
                        'paid_installments' => 0,
                        'remaining_balance' => 100.000,
                        'advance_date' => '2026-05-10',
                        'reason' => 'سلفة زواج وإيجار سكن',
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
 
                // 10% of drivers have a traffic violation on May 15
                if (($index + 1) % 10 === 0) {
                    $violationsToInsert[] = [
                        'company_id' => $companyId,
                        'employee_id' => $emp->id,
                        'vehicle_id' => $veh->id,
                        'created_by' => $adminUserId,
                        'violation_date' => '2026-05-15 14:30:00',
                        'violation_type' => 'تجاوز السرعة المقررة',
                        'reference_number' => 'REF-PERF-' . str_pad($index, 5, '0', STR_PAD_LEFT),
                        'amount' => 5.000,
                        'is_driver_liable' => true,
                        'is_deducted' => false,
                        'notes' => 'التقطت بواسطة كاميرا الدائري الرابع',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
 
                // 10% of drivers have a returned damaged custody item (damages cost 10 KWD)
                if (($index + 2) % 10 === 0) {
                    $custodiesToInsert[] = [
                        'company_id' => $companyId,
                        'employee_id' => $emp->id,
                        'issued_by' => $adminUserId,
                        'custody_type_id' => $custodyType->id,
                        'item_type' => 'هاتف',
                        'item_description' => 'Samsung Galaxy A15',
                        'serial_number' => 'SN-PERF-' . str_pad($index, 5, '0', STR_PAD_LEFT),
                        'value' => 50.000,
                        'issued_date' => '2026-05-01',
                        'returned_date' => '2026-05-20',
                        'return_condition' => 'damaged',
                        'status' => 'returned',
                        'deduction_amount' => 10.000, // 10 KWD penalty
                        'notes' => 'شاشة مكسورة بالكامل',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
 
                // 10% of drivers have a 2-day absence leave without permission (penalty = 20 KWD)
                if (($index + 3) % 10 === 0) {
                    $leavesToInsert[] = [
                        'company_id' => $companyId,
                        'employee_id' => $emp->id,
                        'leave_type_id' => $leaveType->id,
                        'start_date' => '2026-05-10',
                        'end_date' => '2026-05-11',
                        'days_count' => 2,
                        'status' => 'approved',
                        'is_paid' => false,
                        'daily_rate' => 5.000,
                        'penalty_multiplier' => 2.00,
                        'total_deduction' => 20.000, // 2 days * 5.0 * 2.0
                        'approved_by' => $adminUserId,
                        'approved_at' => now(),
                        'reason' => 'انقطاع مفاجئ بدون إخطار مسبق',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
 
                // 10% of drivers have a maintenance record liable for 15 KWD
                if (($index + 4) % 10 === 0) {
                    $maintenancesToInsert[] = [
                        'company_id' => $companyId,
                        'vehicle_id' => $veh->id,
                        'reported_by' => $adminUserId,
                        'approved_by' => $adminUserId,
                        'garage_name' => 'كراج الوفاء للتصليح',
                        'maintenance_type' => 'تصليح مرآة جانبية مكسورة',
                        'maintenance_date' => '2026-05-18',
                        'estimated_cost' => 15.000,
                        'actual_cost' => 15.000,
                        'status' => 'approved',
                        'approved_at' => now(),
                        'is_driver_liable' => true,
                        'liable_employee_id' => $emp->id,
                        'driver_deduction' => 15.000,
                        'notes' => 'حادث تشغيلي نتيجة الإهمال التام',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
 
            // Bulk insert relations in chunks to avoid SQLite's 999 SQL variables limit
            foreach (array_chunk($assignmentsToInsert, 100) as $chunk) {
                VehicleAssignment::insert($chunk);
            }
            foreach (array_chunk($logsToInsert, 45) as $chunk) {
                DailyLog::insert($chunk);
            }
            foreach (array_chunk($advancesToInsert, 50) as $chunk) {
                SalaryAdvance::insert($chunk);
            }
            foreach (array_chunk($violationsToInsert, 50) as $chunk) {
                Violation::insert($chunk);
            }
            foreach (array_chunk($custodiesToInsert, 50) as $chunk) {
                CustodyItem::insert($chunk);
            }
            foreach (array_chunk($leavesToInsert, 50) as $chunk) {
                EmployeeLeave::insert($chunk);
            }
            foreach (array_chunk($maintenancesToInsert, 50) as $chunk) {
                MaintenanceRecord::insert($chunk);
            }
 
            // 6. Create Draft Payroll Run for May 2026 to let the user review it in real-time
            $this->command->info('→ Seeding Draft Payroll Run for May 2026...');
            
            PayrollRun::firstOrCreate([
                'year' => 2026,
                'month' => 5,
                'company_id' => $companyId
            ], [
                'created_by' => $adminUserId,
                'status' => 'draft',
                'total_official' => 0.000,
                'total_actual' => 0.000,
                'total_cash_diff' => 0.000,
                'notes' => 'مسير رواتب تجريبي لـ 1,000 سائق لاختبار كفاءة وسرعة المنصة',
                'company_id' => $companyId,
            ]);
 
            DB::commit();
            
            $this->command->info('🏁 Success! Seeding Completed perfectly!');
            $this->command->info('   - 10 Contracts Active');
            $this->command->info('   - 1,000 Vehicles Active');
            $this->command->info('   - 1,000 Drivers Active');
            $this->command->info('   - 2,000 Daily Logs Seeded');
            $this->command->info('   - Advances, Violations, Custodies, Leaves & Maintenance Seeded');
            $this->command->info('   - Draft Payroll Run Created for May 2026');
            $this->command->info('🚀 Ready for elite performance testing!');
 
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('❌ Seeding failed, rolled back! Error: ' . $e->getMessage());
            throw $e;
        }
    }
}
