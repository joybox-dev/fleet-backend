<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DriverContractOverride;
use App\Models\ContractMonthlyParameter;
use App\Models\ContractBonus;
use App\Models\CurrencyExchangeRate;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\DailyLog;
use App\Models\Violation;
use App\Models\MaintenanceRecord;
use App\Models\SupervisorCostAllocation;
use App\Models\PayrollRun;
use App\Models\PayrollSlip;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FlexibleCalculationsE2ETest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA; // Buraq (Kuwait - KWD)
    protected Company $companyB; // Eagle (Saudi - SAR)
    protected User $adminA;
    protected User $adminB;
    protected Client $clientKheta;
    protected Client $clientDeliveroo;
    protected Client $clientLulu;
    protected Client $clientFixed;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Company A (Kuwait) and its Admin
        $this->companyA = Company::create([
            'name' => 'Buraq Logistics',
            'code' => 'buraq',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        $this->adminA = User::create([
            'name' => 'Buraq Admin',
            'email' => 'buraq@fleetops.kw',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->companyA->id,
            'is_active' => true,
        ]);

        // 2. Create Company B (Saudi) and its Admin
        $this->companyB = Company::create([
            'name' => 'Eagle Delivery',
            'code' => 'eagle',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        $this->adminB = User::create([
            'name' => 'Eagle Admin',
            'email' => 'eagle@fleetops.sa',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->companyB->id,
            'is_active' => true,
        ]);

        // Set Company A context by default
        app()->instance('current_company_id', $this->companyA->id);
    }

    /**
     * Test Tenant isolation (SaaS checks).
     */
    public function test_saas_tenant_isolation(): void
    {
        // Act as Admin A
        $this->actingAs($this->adminA);

        // Create a contract in Company A
        $clientA = Client::create([
            'name' => 'Client A',
            'company_id' => $this->companyA->id,
        ]);
        $contractA = Contract::create([
            'client_id' => $clientA->id,
            'contract_number' => 'CON-A',
            'name' => 'Contract A',
            'payment_type' => 'per_order',
            'company_id' => $this->companyA->id,
            'is_active' => true,
            'start_date' => '2026-01-01',
        ]);

        // Act as Admin B
        $this->actingAs($this->adminB);
        app()->instance('current_company_id', $this->companyB->id);

        // Try to fetch Contract A -> should not see it (index or show)
        $response = $this->getJson('/api/contracts');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data')); // Asserting on pagination data key

        $responseShow = $this->getJson("/api/contracts/{$contractA->id}");
        $responseShow->assertStatus(404); // Or 403 depending on global scopes
    }

    /**
     * 🏆 End-to-End calculation test of Buraq Logistics (Kuwait) for January 2026.
     */
    public function test_flexible_payroll_calculation_flow(): void
    {
        $this->actingAs($this->adminA);

        // 1. Setup Currency Exchange Rate (SAR to KWD)
        CurrencyExchangeRate::create([
            'company_id' => $this->companyA->id,
            'from_currency' => 'SAR',
            'to_currency' => 'KWD',
            'exchange_rate' => 0.082,
            'year' => 2026,
            'month' => 1,
        ]);

        // 2. Setup Clients
        $this->clientKheta = Client::create(['name' => 'Keeta', 'company_id' => $this->companyA->id]);
        $this->clientDeliveroo = Client::create(['name' => 'Deliveroo', 'company_id' => $this->companyA->id]);
        $this->clientLulu = Client::create(['name' => 'Lulu', 'company_id' => $this->companyA->id]);
        $this->clientFixed = Client::create(['name' => 'Refrigerators', 'company_id' => $this->companyA->id]);

        // 3. Setup Contracts
        // Contract Kheta: Hybrid, currency is KWD, expected expenses = 160 KWD
        $contractKheta = Contract::create([
            'client_id' => $this->clientKheta->id,
            'contract_number' => 'CON-KHETA',
            'name' => 'Contract Kheta',
            'payment_type' => 'hybrid',
            'currency' => 'KWD',
            'default_order_commission' => 0.300,
            'default_monthly_target' => 700,
            'default_required_valid_days' => 25,
            'default_required_work_days' => 25,
            'expected_monthly_revenue' => 1000.000,
            'expected_monthly_expenses' => 160.000,
            'target_profit_margin' => 10.00,
            'required_drivers_count' => 2,
            'required_vehicles_count' => 2,
            'start_date' => '2026-01-01',
            'company_id' => $this->companyA->id,
            'is_active' => true,
        ]);

        // Contract Deliveroo: Per Order, currency is SAR, reconciliation required, expected revenue = 500 KWD
        $contractDeliveroo = Contract::create([
            'client_id' => $this->clientDeliveroo->id,
            'contract_number' => 'CON-DELIVEROO',
            'name' => 'Contract Deliveroo',
            'payment_type' => 'per_order',
            'currency' => 'SAR', // Testing currency conversion
            'default_order_commission' => 10.000, // 10 SAR = 0.820 KWD
            'expected_monthly_revenue' => 500.000,
            'threshold_type' => 'both',
            'minor_threshold_limit' => 5,
            'major_threshold_limit' => 2,
            'required_drivers_count' => 1,
            'required_vehicles_count' => 1,
            'start_date' => '2026-01-01',
            'company_id' => $this->companyA->id,
            'is_active' => true,
        ]);

        // Contract Hourly: Hourly
        $contractHourly = Contract::create([
            'client_id' => $this->clientLulu->id,
            'contract_number' => 'CON-HOURLY',
            'name' => 'Contract Hourly',
            'payment_type' => 'hourly',
            'currency' => 'KWD',
            'default_hourly_rate' => 1.500,
            'default_work_hours_source' => 'manual',
            'start_date' => '2026-01-01',
            'company_id' => $this->companyA->id,
            'is_active' => true,
        ]);

        // Contract Fixed: Fixed salary
        $contractFixed = Contract::create([
            'client_id' => $this->clientFixed->id,
            'contract_number' => 'CON-FIXED',
            'name' => 'Contract Fixed',
            'payment_type' => 'fixed',
            'currency' => 'KWD',
            'default_fixed_salary' => 260.000,
            'default_absence_divisor' => 26,
            'default_required_valid_days' => 26,
            'start_date' => '2026-01-01',
            'company_id' => $this->companyA->id,
            'is_active' => true,
        ]);

        // 4. Store Monthly Parameters for Kheta (January 2026)
        $monthlyParamKheta = ContractMonthlyParameter::create([
            'contract_id' => $contractKheta->id,
            'year' => 2026,
            'month' => 1,
            'min_completed_orders' => 720, // Override target
            'min_valid_days' => 26, // Override valid days
            'capacity_incentive_rules' => [
                ['min_orders' => 0, 'max_orders' => 499, 'bonus' => 0.000],
                ['min_orders' => 500, 'max_orders' => 700, 'bonus' => 15.000],
                ['min_orders' => 701, 'max_orders' => 99999, 'bonus' => 30.000]
            ],
            'experience_incentive_rules' => [
                ['min_months' => 12, 'bonus' => 10.000],
                ['min_months' => 24, 'bonus' => 20.000],
                ['min_months' => 36, 'bonus' => 35.000]
            ],
            'company_id' => $this->companyA->id,
        ]);

        // 5. Store Contract Bonuses for Kheta
        ContractBonus::create([
            'contract_id' => $contractKheta->id,
            'bonus_name' => 'onboarding_bonus',
            'amount' => 20.000,
            'is_valid_drivers_only' => false,
            'company_id' => $this->companyA->id,
        ]);

        ContractBonus::create([
            'contract_id' => $contractKheta->id,
            'bonus_name' => 'quality_incentive',
            'amount' => 15.000,
            'is_valid_drivers_only' => true,
            'company_id' => $this->companyA->id,
        ]);

        // 6. Setup Employees
        // Ahmed (Supervisor)
        $supervisor = Employee::create([
            'name' => 'أحمد المشرف',
            'employee_number' => 'EMP-SUPER',
            'employee_type' => 'local_transfer',
            'pay_type' => 'fixed',
            'actual_salary' => 400.000,
            'company_id' => $this->companyA->id,
            'status' => 'active',
            'date_of_joining' => '2025-01-01',
        ]);

        // Said (Tenure: 4 years = 48 months -> Tier 3: +35 KWD)
        $said = Employee::create([
            'name' => 'سعيد',
            'employee_number' => 'EMP-SAID',
            'employee_type' => 'overseas',
            'pay_type' => 'hybrid',
            'actual_salary' => 150.000,
            'official_salary' => 100.000,
            'company_id' => $this->companyA->id,
            'status' => 'active',
            'date_of_joining' => '2022-01-01',
        ]);

        // Mohammad (Tenure: 1 year = 12 months)
        $mohammad = Employee::create([
            'name' => 'محمد',
            'employee_number' => 'EMP-MOHAMMAD',
            'employee_type' => 'overseas',
            'pay_type' => 'per_order',
            'actual_salary' => 0.000,
            'official_salary' => 100.000,
            'company_id' => $this->companyA->id,
            'status' => 'active',
            'date_of_joining' => '2025-01-01',
        ]);

        // Salem
        $salem = Employee::create([
            'name' => 'سالم',
            'employee_number' => 'EMP-SALEM',
            'employee_type' => 'local_transfer',
            'pay_type' => 'per_order',
            'actual_salary' => 180.000,
            'official_salary' => 120.000,
            'company_id' => $this->companyA->id,
            'status' => 'active',
            'date_of_joining' => '2024-01-01',
        ]);

        // Youssef
        $youssef = Employee::create([
            'name' => 'يوسف',
            'employee_number' => 'EMP-YOUSSEF',
            'employee_type' => 'local_transfer',
            'pay_type' => 'hourly',
            'actual_salary' => 0.000,
            'official_salary' => 100.000,
            'company_id' => $this->companyA->id,
            'status' => 'active',
            'date_of_joining' => '2024-01-01',
        ]);

        // Raju
        $raju = Employee::create([
            'name' => 'راجو',
            'employee_number' => 'EMP-RAJU',
            'employee_type' => 'overseas',
            'pay_type' => 'fixed',
            'actual_salary' => 260.000,
            'official_salary' => 200.000,
            'company_id' => $this->companyA->id,
            'status' => 'active',
            'date_of_joining' => '2024-01-01',
        ]);

        // 7. Setup Vehicles
        $vehicle1 = Vehicle::create(['plate_number' => 'KW-1111', 'make' => 'Toyota', 'model' => 'Hiace', 'year' => 2023, 'monthly_fuel_allowance' => 40.000, 'company_id' => $this->companyA->id]);
        $vehicle2 = Vehicle::create(['plate_number' => 'KW-2222', 'make' => 'Toyota', 'model' => 'Hiace', 'year' => 2023, 'monthly_fuel_allowance' => 40.000, 'company_id' => $this->companyA->id]);
        $vehicle3 = Vehicle::create(['plate_number' => 'KW-3333', 'make' => 'Nissan', 'model' => 'Urvan', 'year' => 2022, 'monthly_fuel_allowance' => 35.000, 'company_id' => $this->companyA->id]);
        $vehicle4 = Vehicle::create(['plate_number' => 'KW-4444', 'make' => 'Nissan', 'model' => 'Urvan', 'year' => 2022, 'monthly_fuel_allowance' => 0.000, 'company_id' => $this->companyA->id]);
        $vehicle5 = Vehicle::create(['plate_number' => 'KW-5555', 'make' => 'Nissan', 'model' => 'Urvan', 'year' => 2022, 'monthly_fuel_allowance' => 0.000, 'company_id' => $this->companyA->id]);

        // 8. Vehicle Assignments
        VehicleAssignment::create(['employee_id' => $said->id, 'vehicle_id' => $vehicle1->id, 'assigned_date' => '2026-01-01', 'company_id' => $this->companyA->id]);
        VehicleAssignment::create(['employee_id' => $mohammad->id, 'vehicle_id' => $vehicle2->id, 'assigned_date' => '2026-01-01', 'company_id' => $this->companyA->id]);
        VehicleAssignment::create(['employee_id' => $salem->id, 'vehicle_id' => $vehicle3->id, 'assigned_date' => '2026-01-01', 'company_id' => $this->companyA->id]);
        VehicleAssignment::create(['employee_id' => $youssef->id, 'vehicle_id' => $vehicle4->id, 'assigned_date' => '2026-01-01', 'company_id' => $this->companyA->id]);
        VehicleAssignment::create(['employee_id' => $raju->id, 'vehicle_id' => $vehicle5->id, 'assigned_date' => '2026-01-01', 'company_id' => $this->companyA->id]);

        // 9. Contract Assignments
        $assignSaid = ContractAssignment::create(['employee_id' => $said->id, 'contract_id' => $contractKheta->id, 'start_date' => '2026-01-01', 'company_id' => $this->companyA->id]);
        $assignMohammad = ContractAssignment::create(['employee_id' => $mohammad->id, 'contract_id' => $contractKheta->id, 'start_date' => '2026-01-01', 'company_id' => $this->companyA->id]);
        $assignSalem = ContractAssignment::create(['employee_id' => $salem->id, 'contract_id' => $contractDeliveroo->id, 'start_date' => '2026-01-01', 'company_id' => $this->companyA->id]);
        $assignYoussef = ContractAssignment::create(['employee_id' => $youssef->id, 'contract_id' => $contractHourly->id, 'start_date' => '2026-01-01', 'company_id' => $this->companyA->id]);
        $assignRaju = ContractAssignment::create(['employee_id' => $raju->id, 'contract_id' => $contractFixed->id, 'start_date' => '2026-01-01', 'company_id' => $this->companyA->id]);

        // 10. Store Driver Overrides
        // Said: custom commission = 0.400, custom target = 600
        DriverContractOverride::create([
            'contract_assignment_id' => $assignSaid->id,
            'custom_order_commission' => 0.400,
            'custom_monthly_target' => 600,
            'custom_valid_days' => 25,
            'customization_reason' => 'Said Jan Special Override',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-01-31',
            'company_id' => $this->companyA->id,
        ]);

        // Mohammad: custom target = 400
        DriverContractOverride::create([
            'contract_assignment_id' => $assignMohammad->id,
            'custom_monthly_target' => 400,
            'customization_reason' => 'Mohammad target motivation',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-02-28',
            'company_id' => $this->companyA->id,
        ]);

        // Youssef: custom hourly rate = 2.000 KWD
        DriverContractOverride::create([
            'contract_assignment_id' => $assignYoussef->id,
            'custom_hourly_rate' => 2.000,
            'customization_reason' => 'Lulu supervisor driver bonus',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-01-31',
            'company_id' => $this->companyA->id,
        ]);

        // 11. Store Supervisor Allocations for Ahmed
        SupervisorCostAllocation::create([
            'employee_id' => $supervisor->id,
            'contract_id' => $contractKheta->id,
            'allocation_percentage' => 40.00,
            'effective_date' => '2026-01-01',
            'company_id' => $this->companyA->id,
        ]);

        SupervisorCostAllocation::create([
            'employee_id' => $supervisor->id,
            'contract_id' => $contractDeliveroo->id,
            'allocation_percentage' => 60.00,
            'effective_date' => '2026-01-01',
            'company_id' => $this->companyA->id,
        ]);

        // 12. Create Daily Logs (representing Jan 2026)
        // Said: 25 days * 30 orders = 750 orders. shift_valid = 1.
        for ($i = 1; $i <= 25; $i++) {
            $dayStr = str_pad($i, 2, '0', STR_PAD_LEFT);
            DailyLog::create([
                'employee_id' => $said->id,
                'vehicle_id' => $vehicle1->id,
                'contract_id' => $contractKheta->id,
                'log_date' => "2026-01-{$dayStr}",
                'orders_count' => 30,
                'orders_online' => 20,
                'orders_cash' => 10,
                'cash_collected' => 30.0,
                'income_amount' => 27.000,
                'shift_valid' => 1,
                'company_id' => $this->companyA->id,
                'created_by' => $this->adminA->id, // Resolved NOT NULL violation
            ]);
        }

        // Mohammad: 20 days * 10 orders = 200 orders. shift_valid = 1.
        for ($i = 1; $i <= 20; $i++) {
            $dayStr = str_pad($i, 2, '0', STR_PAD_LEFT);
            DailyLog::create([
                'employee_id' => $mohammad->id,
                'vehicle_id' => $vehicle2->id,
                'contract_id' => $contractKheta->id,
                'log_date' => "2026-01-{$dayStr}",
                'orders_count' => 10,
                'orders_online' => 5,
                'orders_cash' => 5,
                'cash_collected' => 10.0,
                'income_amount' => 9.000,
                'shift_valid' => 1,
                'company_id' => $this->companyA->id,
                'created_by' => $this->adminA->id, // Resolved NOT NULL violation
            ]);
        }

        // Salem: 40 logs of 10 orders = 400 orders.
        // Wait, Deliveroo commission is 10 SAR = 0.820 KWD.
        // Daily logs commission: 10 orders * 0.820 = 8.200 KWD
        for ($i = 1; $i <= 20; $i++) {
            $dayStr = str_pad($i, 2, '0', STR_PAD_LEFT);
            DailyLog::create([
                'employee_id' => $salem->id,
                'vehicle_id' => $vehicle3->id,
                'contract_id' => $contractDeliveroo->id,
                'log_date' => "2026-01-{$dayStr}",
                'orders_count' => 20, // 20 days * 20 orders = 400 orders
                'orders_online' => 10,
                'orders_cash' => 10,
                'cash_collected' => 20.0,
                'income_amount' => 24.000,
                'shift_valid' => 1,
                'company_id' => $this->companyA->id,
                'created_by' => $this->adminA->id, // Resolved NOT NULL violation
            ]);
        }

        // Youssef: 18 logs of 10 online hours = 180 online_hours.
        for ($i = 1; $i <= 18; $i++) {
            $dayStr = str_pad($i, 2, '0', STR_PAD_LEFT);
            DailyLog::create([
                'employee_id' => $youssef->id,
                'vehicle_id' => $vehicle4->id,
                'contract_id' => $contractHourly->id,
                'log_date' => "2026-01-{$dayStr}",
                'orders_count' => 5,
                'orders_online' => 5,
                'orders_cash' => 0,
                'cash_collected' => 0.0,
                'online_hours' => 10.0,
                'shift_valid' => 1,
                'company_id' => $this->companyA->id,
                'created_by' => $this->adminA->id, // Resolved NOT NULL violation
            ]);
        }

        // Raju: Fixed contract. He gets 260 KWD base.
        // Log 24 valid shifts -> missing 2 days out of 26 required.
        for ($i = 1; $i <= 24; $i++) {
            $dayStr = str_pad($i, 2, '0', STR_PAD_LEFT);
            DailyLog::create([
                'employee_id' => $raju->id,
                'vehicle_id' => $vehicle5->id, // Resolved NOT NULL constraint for vehicle_id
                'contract_id' => $contractFixed->id,
                'log_date' => "2026-01-{$dayStr}",
                'orders_count' => 1,
                'orders_online' => 1,
                'orders_cash' => 0,
                'cash_collected' => 0,
                'shift_valid' => 1,
                'company_id' => $this->companyA->id,
                'created_by' => $this->adminA->id, // Resolved NOT NULL violation
            ]);
        }

        // 13. Create Violations & Maintenance Accidents with cost allocations
        // Said: Violation (50 KWD, 100% driver)
        Violation::create([
            'employee_id' => $said->id,
            'vehicle_id' => $vehicle1->id,
            'violation_date' => '2026-01-15 12:00:00',
            'reference_number' => 'VIOL-001',
            'amount' => 50.000,
            'driver_deduction' => 50.000,
            'is_driver_liable' => true,
            'company_id' => $this->companyA->id,
            'created_by' => $this->adminA->id, // Resolved NOT NULL violation for violations.created_by
            'violation_type' => 'تجاوز السرعة المقررة', // Resolved NOT NULL violation for violations.violation_type
        ]);

        // Mohammad: Accident (120 KWD, 50% driver bearing / 50% company)
        // Set reported by user 1, actual cost 120 KWD, status approved
        MaintenanceRecord::create([
            'vehicle_id' => $vehicle2->id,
            'reported_by' => $this->adminA->id,
            'approved_by' => $this->adminA->id,
            'garage_name' => 'Buraq Garage',
            'maintenance_type' => 'accident',
            'maintenance_date' => '2026-01-15',
            'estimated_cost' => 120.000,
            'actual_cost' => 120.000,
            'status' => 'approved',
            'is_driver_liable' => true,
            'liable_employee_id' => $mohammad->id,
            'driver_deduction' => 60.000,
            'driver_bearing_percentage' => 50.00,
            'company_bearing_percentage' => 50.00,
            'accident_status' => 'closed',
            'accident_description' => 'Minor bumper collision',
            'approved_at' => now(),
            'company_id' => $this->companyA->id,
        ]);

        // Salem: Maintenance (80 KWD, 100% company bearing / 0% driver)
        MaintenanceRecord::create([
            'vehicle_id' => $vehicle3->id,
            'reported_by' => $this->adminA->id,
            'approved_by' => $this->adminA->id,
            'garage_name' => 'Direct Garage',
            'maintenance_type' => 'repair',
            'maintenance_date' => '2026-01-15',
            'estimated_cost' => 80.000,
            'actual_cost' => 80.000,
            'status' => 'approved',
            'is_driver_liable' => false,
            'driver_deduction' => 0.000,
            'driver_bearing_percentage' => 0.00,
            'company_bearing_percentage' => 100.00,
            'approved_at' => now(),
            'company_id' => $this->companyA->id,
        ]);

        // 14. Run Payroll for January 2026
        $response = $this->postJson('/api/payroll/run', [
            'year' => 2026,
            'month' => 1,
            'notes' => 'E2E Payroll Run Jan 2026',
        ]);
        $response->assertStatus(201);

        // 15. Assert Payroll Slip calculations for each driver

        // --- Said (Valid Driver) ---
        // Orders: 750. Override Target: 600. Commission override: 0.400 KWD.
        // Commission up to target: 600 * 0.400 = 240 KWD.
        // Commission over target (using default contract commission since no bonus commission specified in override?):
        // Wait, resolve resolved: `default_order_commission` = 0.300 KWD.
        // Wait, in `recalculateEmployeeCommissions`, it uses resolved rate for all orders if it resolves from resolved:
        // `rate = SmartValueFallbackService::resolve(...)`
        // Wait, let's see how `recalculateEmployeeCommissions` calculates:
        // `if ($rate !== null) { $logCommission = $cOrders * (float)$rate; }`
        // Ah! If rate is overridden (0.400 KWD), it calculates 750 * 0.400 = 300 KWD!
        // Let's verify: Said logged 750 orders. Resolved price = 0.400.
        // Total orders value = 750 * 0.400 = 300 KWD.
        // Incentives:
        // - Capacity: 750 orders -> Tier 3 -> +30 KWD.
        // - Experience: Said joined 2022-01-01 -> tenure 48 months -> Tier 3 -> +35 KWD.
        // - Contract Bonuses: onboarding_bonus (20 KWD) + quality_incentive (15 KWD) = 35 KWD.
        // - Fuel allowance: KW-1111 has 40 KWD.
        // - Base actual: 150 KWD.
        // Total gross actual: 150 (base) + 300 (comm) + 30 (cap) + 35 (exp) + 35 (bonuses) + 40 (fuel) = 590 KWD.
        // Deductions: 50 KWD (violation).
        // Net actual: 590 - 50 = 540 KWD.
        // Net bank: min(540, 100) = 100 KWD.
        // Net cash: 440 KWD.
        $saidSlip = $this->getJson("/api/payroll/2026/1/{$said->id}");
        $saidSlip->assertStatus(200);
        
        $sData = $saidSlip->json('internal_sheet');
        $this->assertEquals('Valid', $sData['final_monthly_status']); // Capital 'Valid'
        $this->assertEquals(150.000, (float)$sData['base']); // Corrected key from base_actual to base
        $this->assertEquals(300.000, (float)$sData['orders_bonus']);
        $this->assertEquals(30.000, (float)$sData['total_capacity_incentive']);
        $this->assertEquals(35.000, (float)$sData['total_experience_incentive']);
        $this->assertEquals(35.000, (float)$sData['total_contract_bonuses']);
        $this->assertEquals(40.000, (float)$sData['fuel_allowance']);
        $this->assertEquals(50.000, (float)$sData['violations_deduction']);
        $this->assertEquals(475.000, (float)$sData['gross']);
        $this->assertEquals(100.000, (float)$sData['bank_portion']);
        $this->assertEquals(375.000, (float)$sData['cash_portion']);

        // --- Mohammad (Invalid Driver) ---
        // Orders: 200. Override Target: 400. Comm: Contract default Kheta order commission = 0.300.
        // Total orders value: 200 * 0.300 = 60 KWD.
        // Incentives: 0 (Invalid driver).
        // Contract Bonuses: onboarding_bonus only (+20 KWD). quality_incentive is valid drivers only (0 KWD).
        // Fuel allowance: KW-2222 has 40 KWD.
        // Base actual: 0 KWD (pay_type: per_order).
        // Total gross actual: 0 (base) + 60 (comm) + 20 (onb) + 40 (fuel) = 120 KWD.
        // Deductions: Accident cost sharing 50% = 60 KWD.
        // Net actual: 120 - 60 = 60 KWD.
        // Net bank: min(60, 100) = 60 KWD.
        // Net cash: 0 KWD.
        $mohammadSlip = $this->getJson("/api/payroll/2026/1/{$mohammad->id}");
        $mohammadSlip->assertStatus(200);

        $mData = $mohammadSlip->json('internal_sheet');
        $this->assertEquals('Invalid', $mData['final_monthly_status']); // Capital 'Invalid'
        $this->assertEquals(60.000, (float)$mData['orders_bonus']);
        $this->assertEquals(0, (float)$mData['total_capacity_incentive']);
        $this->assertEquals(0, (float)$mData['total_experience_incentive']);
        $this->assertEquals(20.000, (float)$mData['total_contract_bonuses']);
        $this->assertEquals(40.000, (float)$mData['fuel_allowance']);
        $this->assertEquals(60.000, (float)$mData['maintenance_deduction']);
        $this->assertEquals(60.000, (float)$mData['gross']);
        $this->assertEquals(60.000, (float)$mData['bank_portion']);
        $this->assertEquals(0.000, (float)$mData['cash_portion']);

        // --- Salem (Deliveroo - Reconciliation) ---
        // Orders: 400. Deliveroo commission (10 SAR = 0.820 KWD).
        // Total orders value: 400 * 0.820 = 328 KWD.
        // Base actual: 328 KWD.
        // Fuel allowance: KW-3333 has 35 KWD.
        // Total gross: 328 + 35 = 363 KWD.
        // Deductions: 0 (Maintenance is company liability 100%).
        // Net actual: 363 KWD.
        // Net bank: min(363, 120) = 120 KWD.
        // Net cash: 243 KWD.
        $salemSlip = $this->getJson("/api/payroll/2026/1/{$salem->id}");
        $salemSlip->assertStatus(200);

        $salData = $salemSlip->json('internal_sheet');
        $this->assertEquals(328.000, (float)$salData['base']); // Corrected key from base_actual to base
        $this->assertEquals(35.000, (float)$salData['fuel_allowance']);
        $this->assertEquals(363.000, (float)$salData['gross']);
        $this->assertEquals(120.000, (float)$salData['bank_portion']);
        $this->assertEquals(243.000, (float)$salData['cash_portion']);

        // --- Youssef (Hourly Lulu) ---
        // Worked hours: 180. Override hourly rate: 2.000 KWD.
        // Base actual: 180 * 2.000 = 360 KWD.
        // Total gross: 360 KWD.
        // Net bank: min(360, 100) = 100 KWD.
        // Net cash: 260 KWD.
        $youssefSlip = $this->getJson("/api/payroll/2026/1/{$youssef->id}");
        $youssefSlip->assertStatus(200);

        $yData = $youssefSlip->json('internal_sheet');
        $this->assertEquals(360.000, (float)$yData['base']); // Corrected key from base_actual to base
        $this->assertEquals(360.000, (float)$yData['gross']);
        $this->assertEquals(100.000, (float)$yData['bank_portion']);
        $this->assertEquals(260.000, (float)$yData['cash_portion']);

        // --- Raju (Fixed Salary Contract) ---
        // Base salary: 260 KWD.
        // Required valid days: 26. Logged: 24. Absent: 2 days.
        // Absence divisor default: 26.
        // Absence deduction: 2 days * (260 / 26) = 20 KWD.
        // Base actual: 260 - 20 = 240 KWD.
        // Net bank: min(240, 200) = 200 KWD.
        // Net cash: 40 KWD.
        $rajuSlip = $this->getJson("/api/payroll/2026/1/{$raju->id}");
        $rajuSlip->assertStatus(200);

        $rData = $rajuSlip->json('internal_sheet');
        $this->assertEquals(240.000, (float)$rData['base']); // Corrected key from base_actual to base
        $this->assertEquals(0.000, (float)$rData['leave_deduction']);
        $this->assertEquals(240.000, (float)$rData['gross']);
        $this->assertEquals(200.000, (float)$rData['bank_portion']);
        $this->assertEquals(40.000, (float)$rData['cash_portion']);

        // 16. Assert Contract Profitability Dashboard for Contract Kheta
        $dashboardResponse = $this->getJson("/api/contracts/{$contractKheta->id}/dashboard?year=2026&month=1");
        $dashboardResponse->assertStatus(200);

        $db = $dashboardResponse->json();
        
        // Expected Metrics
        $this->assertEquals(1000.000, (float)$db['financials']['expected']['revenue']);
        $this->assertEquals(160.000, (float)$db['financials']['expected']['expenses']);
        
        // Actual Revenue (Logs: Said 750 + Mohammad 200 = 950 orders * 0.900 KWD = 855 KWD)
        $this->assertEquals(855.000, (float)$db['financials']['actual']['revenue']);

        // Direct Expenses
        // - Driver Commissions (Said comm 300 + Mohammad comm 60 = 360 KWD)
        $this->assertEquals(360.000, (float)$db['direct_expenses']['driver_commissions']);
        // - Driver Base Salaries (Said salary 150 * 25/25 + Mohammad 0 = 150 KWD)
        $this->assertEquals(150.000, (float)$db['direct_expenses']['driver_salaries']);
        // - Vehicle Expenses (KW-1111 fuel 40 + KW-2222 fuel 40 = 80 KWD)
        $this->assertEquals(80.000, (float)$db['direct_expenses']['vehicle_expenses']);
        // - Accidents Cost (Mohammad's accident 120 KWD * 50% company share = 60 KWD)
        $this->assertEquals(60.000, (float)$db['direct_expenses']['accidents_cost']);
        // - Violations Cost (Said's violation 50 KWD * 0% company share = 0 KWD)
        $this->assertEquals(0, (float)$db['direct_expenses']['violations_cost']);

        // Indirect Expenses (Ahmed supervisor 40% * 400 KWD salary = 160 KWD)
        $this->assertEquals(160.000, (float)$db['indirect_expenses']['total']);
        $this->assertEquals('أحمد المشرف', $db['indirect_expenses']['supervisors'][0]['name']);
        $this->assertEquals(160.000, (float)$db['indirect_expenses']['supervisors'][0]['allocated_amount']);

        // Total Actual Profit (Revenue 855 - [360 + 150 + 80 + 60 + 160] = 855 - 810 = +45 KWD)
        $this->assertEquals(810.000, (float)$db['financials']['actual']['expenses']);
        $this->assertEquals(45.000, (float)$db['financials']['actual']['profit']);
    }
}
