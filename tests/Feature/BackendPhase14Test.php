<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackendPhase14Test extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Client $client;

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

        $this->client = Client::create([
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
                'default_required_work_days' => 26,
                'client_payment_method' => 'fixed',
                'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 900]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260]],
                'is_validity_enabled' => true,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('contracts', [
            'contract_number' => 'CON-KPI-1',
            'is_validity_enabled' => true,
        ]);

        $contractId = $response->json('id');

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/contracts/{$contractId}", [
                'is_validity_enabled' => false,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('contracts', [
            'id' => $contractId,
            'is_validity_enabled' => false,
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
            'company_id' => $this->company->id,
        ]);

        $vehicle = Vehicle::create([
            'plate_number' => '12345',
            'make' => 'Honda',
            'model' => 'Unassigned',
            'company_id' => $this->company->id,
        ]);

        // 1. Contract with is_validity_enabled = true
        $kpiContract = Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-KPI-2',
            'name' => 'KPI Active Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'company_id' => $this->company->id,
            'is_validity_enabled' => true,
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
            'is_valid' => false,
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
            'is_valid' => true,
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
                'is_valid' => false, // manual override
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('daily_logs', [
            'employee_id' => $driver->id,
            'log_date' => '2026-07-03',
            'is_valid' => false,
        ]);

        // 2. Contract with is_validity_enabled = false
        $nonKpiContract = Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-KPI-3',
            'name' => 'Non KPI Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'company_id' => $this->company->id,
            'is_validity_enabled' => false,
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
            'is_valid' => true,
        ]);
    }
}
