<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\DailyLog;
use App\Models\PayrollSlip;
use App\Models\PayrollRun;
use App\Models\ContractMonthlyParameter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackendPhase14Test extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $adminUser;
    protected \App\Models\Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant company
        $this->company = Company::create([
            'name' => 'Test Company',
            'subdomain' => 'test',
            'code' => 'TESTC',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        // Set tenant context
        app()->instance('current_company_id', $this->company->id);

        $this->client = \App\Models\Client::create([
            'name' => 'Test Client',
            'company_id' => $this->company->id,
        ]);

        // Create Admin User
        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_it_can_toggle_kpi_validity_enabled_on_contract()
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/contracts', [
                'client_id' => $this->client->id,
                'contract_number' => 'CON-KPI-1',
                'name' => 'KPI Contract',
                'payment_type' => 'hybrid',
                'start_date' => '2026-07-01',
                'is_validity_enabled' => true
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('contracts', [
            'contract_number' => 'CON-KPI-1',
            'is_validity_enabled' => true
        ]);

        $contractId = $response->json('id');

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/contracts/{$contractId}", [
                'is_validity_enabled' => false
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('contracts', [
            'id' => $contractId,
            'is_validity_enabled' => false
        ]);
    }

    public function test_it_auto_calculates_daily_log_validity_based_on_kpi_toggle()
    {
        $driver = Employee::create([
            'name' => 'Driver 1',
            'date_of_joining' => '2026-07-01',
            'pay_type' => 'fixed',
            'official_salary' => 120,
            'actual_salary' => 150,
            'company_id' => $this->company->id
        ]);

        $vehicle = Vehicle::create([
            'plate_number' => '12345',
            'make' => 'Honda',
            'model' => 'Unassigned',
            'company_id' => $this->company->id
        ]);

        // 1. Contract with is_validity_enabled = true
        $kpiContract = Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-KPI-2',
            'name' => 'KPI Active Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'company_id' => $this->company->id,
            'is_validity_enabled' => true
        ]);

        // A log that fails KPI criteria (online_hours < 10)
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/daily-logs', [
                'employee_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'contract_id' => $kpiContract->id,
                'log_date' => '2026-07-01',
                'orders_count' => 5,
                'orders_online' => 5,
                'orders_cash' => 0,
                'online_hours' => 8, // less than 10
                'ontime_rate' => 95,
                'late_login' => false,
                'early_logout' => false,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('daily_logs', [
            'employee_id' => $driver->id,
            'log_date' => '2026-07-01',
            'is_valid' => false
        ]);

        // A log that passes KPI criteria
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/daily-logs', [
                'employee_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'contract_id' => $kpiContract->id,
                'log_date' => '2026-07-02',
                'orders_count' => 5,
                'orders_online' => 5,
                'orders_cash' => 0,
                'online_hours' => 10,
                'ontime_rate' => 95,
                'late_login' => false,
                'early_logout' => false,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('daily_logs', [
            'employee_id' => $driver->id,
            'log_date' => '2026-07-02',
            'is_valid' => true
        ]);

        // A log with manual override to is_valid = false
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/daily-logs', [
                'employee_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'contract_id' => $kpiContract->id,
                'log_date' => '2026-07-03',
                'orders_count' => 5,
                'orders_online' => 5,
                'orders_cash' => 0,
                'online_hours' => 12,
                'ontime_rate' => 95,
                'late_login' => false,
                'early_logout' => false,
                'is_valid' => false // manual override
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('daily_logs', [
            'employee_id' => $driver->id,
            'log_date' => '2026-07-03',
            'is_valid' => false
        ]);

        // 2. Contract with is_validity_enabled = false
        $nonKpiContract = Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-KPI-3',
            'name' => 'Non KPI Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'company_id' => $this->company->id,
            'is_validity_enabled' => false
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/daily-logs', [
                'employee_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'contract_id' => $nonKpiContract->id,
                'log_date' => '2026-07-04',
                'orders_count' => 1,
                'orders_online' => 1,
                'orders_cash' => 0,
                'online_hours' => 4, // less than 10, but toggle is false
                'ontime_rate' => 80,
                'late_login' => true,
                'early_logout' => true,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('daily_logs', [
            'employee_id' => $driver->id,
            'log_date' => '2026-07-04',
            'is_valid' => true
        ]);
    }

    public function test_it_calculates_capacity_and_experience_incentives_only_for_valid_da_and_excludes_them_from_salary()
    {
        // Driver tenure: joined 2026-01-01 -> tenure in 2026-07 is 6 months
        $driver = Employee::create([
            'name' => 'Rider Valid DA',
            'date_of_joining' => '2026-01-01',
            'pay_type' => 'fixed',
            'official_salary' => 120,
            'actual_salary' => 150,
            'company_id' => $this->company->id
        ]);

        $vehicle = Vehicle::create([
            'plate_number' => '11111',
            'make' => 'Honda',
            'model' => 'Unassigned',
            'company_id' => $this->company->id
        ]);

        $contract = Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-KPI-4',
            'name' => 'KPI Incentives Contract',
            'payment_type' => 'fixed',
            'start_date' => '2026-07-01',
            'company_id' => $this->company->id,
            'is_validity_enabled' => true
        ]);

        // Assign driver to contract
        ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $contract->id,
            'start_date' => '2026-07-01',
            'status' => 'active',
            'company_id' => $this->company->id
        ]);

        // Create Monthly Parameters with incentive rules
        // Capacity: if orders >= 100, reward = 50 KWD
        // Experience: if tenure >= 6 months, reward = 30 KWD, reward_per_order = 0.1 KWD
        $param = ContractMonthlyParameter::create([
            'contract_id' => $contract->id,
            'year' => 2026,
            'month' => 7,
            'company_id' => $this->company->id,
            'capacity_incentive_rules' => [
                ['min_orders' => 100, 'max_orders' => 1000, 'bonus' => 50]
            ],
            'experience_incentive_rules' => [
                ['min_months' => 6, 'bonus' => 30, 'bonus_per_order' => 0.1]
            ]
        ]);

        // Create daily logs for July 2026
        // Denominator: 31 - 4 = 27 days.
        // Let's create 25 logs with is_valid = true -> R = 25/27 = 0.9259 (>= 90%) -> Valid DA!
        // Total orders = 100
        for ($day = 1; $day <= 25; $day++) {
            DailyLog::create([
                'employee_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'contract_id' => $contract->id,
                'log_date' => "2026-07-" . str_pad($day, 2, '0', STR_PAD_LEFT),
                'orders_count' => 4,
                'orders_online' => 4,
                'orders_cash' => 0,
                'online_hours' => 10,
                'ontime_rate' => 95,
                'is_valid' => true,
                'shift_valid' => true,
                'created_by' => $this->adminUser->id,
                'company_id' => $this->company->id
            ]);
        }

        // Run payroll for July 2026
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/payroll/run', [
                'year' => 2026,
                'month' => 7
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('payroll_slips', [
            'employee_id' => $driver->id,
            'final_monthly_status' => 'Valid'
        ]);

        $slip = PayrollSlip::where('employee_id', $driver->id)->first();

        // 1. Capacity Incentive: 50
        $this->assertEquals(50.0, (float)$slip->total_capacity_incentive);

        // 2. Experience Incentive: (30 + 0.1 * 100) * R = 40 * (25 / 27) = 37.037
        $expectedExperience = round(40.0 * (25 / 27), 3);
        $this->assertEquals($expectedExperience, round((float)$slip->total_experience_incentive, 3));

        // 3. Confirm these are EXCLUDED from driver payout (gross_actual & cash_portion)
        // Since driver is fixed salary = 150 (actual), official = 120, total worked days = 25.
        // There is minor absence deduction (worked 25, required 26 -> 1 day absent):
        // daily_rate = 150 / 26 = 5.769. absence_deduction = 5.769. base_actual = 144.231.
        // Gross Actual should equal base_actual = 144.231 (i.e. capacity & experience incentives must not be added to driver payout)
        // If they were added, gross actual would be ~231.268.
        $this->assertTrue((float)$slip->gross_actual < 150.0);
        $this->assertEquals(round($slip->base_actual, 3), round($slip->gross_actual, 3));
    }
}
