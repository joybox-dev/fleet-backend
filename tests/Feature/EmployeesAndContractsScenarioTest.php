<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\DriverContractOverride;
use App\Models\DailyLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class EmployeesAndContractsScenarioTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Scenario Test Company',
            'code' => 'scenarioc',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'Test Client',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_cannot_create_contract_with_zones_driver_payment_method_if_client_pricing_is_not_zones()
    {
        $this->actingAs($this->user);

        // Try to create contract with driver payment method = 'zones' but client_payment_method = 'fixed'
        $response = $this->postJson('/api/contracts', [
            'client_id' => $this->client->id,
            'contract_number' => 'CON-1',
            'name' => 'Contract 1',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'client_payment_method' => 'fixed',
            'driver_payment_method' => 'zones',
            'currency' => 'KWD',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['driver_payment_method']);
    }

    public function test_can_create_contract_with_zones_driver_payment_method_if_client_pricing_is_zones()
    {
        $this->actingAs($this->user);

        // Create contract with driver payment method = 'zones' and client_payment_method = 'zones'
        $response = $this->postJson('/api/contracts', [
            'client_id' => $this->client->id,
            'contract_number' => 'CON-1',
            'name' => 'Contract 1',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'client_payment_method' => 'zones',
            'driver_payment_method' => 'zones',
            'currency' => 'KWD',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('contracts', [
            'contract_number' => 'CON-1',
            'driver_payment_method' => 'zones',
            'client_payment_method' => 'zones',
        ]);
    }

    public function test_cannot_create_override_with_zones_or_zones_tiers_if_client_pricing_is_not_zones()
    {
        $this->actingAs($this->user);

        // 1. Create a contract where client_payment_method is 'fixed' (NOT zones)
        $contract = Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-2',
            'name' => 'Contract 2',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'client_payment_method' => 'fixed',
            'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id,
            'client_pricing_rules' => [
                '1' => ['payment_method' => 'fixed', 'fixed_amount' => 500] // vehicle_type_id = 1
            ],
            'driver_pricing_rules' => [
                '1' => ['payment_method' => 'fixed', 'fixed_amount' => 200]
            ],
        ]);

        // 2. Create driver (Employee) and Vehicle type id = 1
        $driver = Employee::create([
            'name' => 'Driver 1',
            'employee_number' => 'EMP-001',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-07-01',
        ]);

        $vehicle = Vehicle::create([
            'plate_number' => 'V-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        // 3. Active vehicle assignment
        VehicleAssignment::create([
            'employee_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'is_active' => true,
            'assigned_date' => '2026-07-01',
            'company_id' => $this->company->id,
        ]);

        // 4. Contract Assignment
        $assignment = \App\Models\ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $contract->id,
            'start_date' => '2026-07-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        // 5. Try to create a driver override with 'zones_tiers' pricing method (should fail since client_payment_method is 'fixed')
        $response = $this->postJson("/api/contract-assignments/{$assignment->id}/overrides", [
            'override_type' => 'zones_tiers',
            'customization_reason' => 'Testing validation constraints',
            'effective_from' => '2026-07-01',
            'zones_tiers' => [
                [
                    'id' => 'z-1',
                    'name' => 'Zone A',
                    'tiers' => [
                        ['min' => 1, 'max' => 50, 'price' => 1.5],
                        ['min' => 51, 'max' => 999, 'price' => 2.0],
                    ]
                ]
            ]
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['override_type']);
    }

    public function test_zones_tiers_payroll_calculation_with_proration_for_mid_month_joiner()
    {
        $this->actingAs($this->user);

        // 1. Create contract with client_payment_method = 'zones'
        $contract = Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-3',
            'name' => 'Contract 3',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'client_payment_method' => 'zones',
            'driver_payment_method' => 'zones_tiers',
            'company_id' => $this->company->id,
            'client_pricing_rules' => [
                '1' => [
                    'payment_method' => 'zones',
                    'zones' => [
                        ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 5.0]
                    ]
                ]
            ],
            'driver_pricing_rules' => [
                '1' => [
                    'payment_method' => 'zones_tiers',
                    'zones_tiers' => [
                        [
                            'id' => 'zone-a',
                            'name' => 'Zone A',
                            'tiers' => [
                                ['min' => 1, 'max' => 10, 'price' => 1.0],  // Original limit min=1, max=10
                                ['min' => 11, 'max' => 20, 'price' => 1.5], // Original limit min=11, max=20
                                ['min' => 21, 'max' => 999, 'price' => 2.0],
                            ]
                        ]
                    ]
                ]
            ],
        ]);

        // 2. Driver joins mid-month (July 16th). Remaining days in July (31 days) is 31 - 16 + 1 = 16 days.
        // Ratio R = 16 / 31 = 0.516129
        // Prorated limits:
        // Tier 1: min = round(1 * R) = 1, max = round(10 * R) = 5
        // Tier 2: min = round(11 * R) = 6, max = round(20 * R) = 10
        // Tier 3: min = round(21 * R) = 11, max = 999
        $driver = Employee::create([
            'name' => 'Mid Month Driver',
            'employee_number' => 'EMP-002',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-07-16',
        ]);

        $vehicle = Vehicle::create([
            'plate_number' => 'V-2',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        VehicleAssignment::create([
            'employee_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'is_active' => true,
            'assigned_date' => '2026-07-16',
            'company_id' => $this->company->id,
        ]);

        $assignment = \App\Models\ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $contract->id,
            'start_date' => '2026-07-16',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        // 3. Create daily logs for July 17th, 18th, 19th with orders in Zone A
        // Total orders in Zone A = 9
        // Under normal limits, 9 orders would fall in Tier 1 (price = 1.0).
        // Under prorated limits, 9 orders falls in Tier 2 (min=6, max=10, price = 1.5)!
        // Payout should be: 9 * 1.5 = 13.5 KWD
        DailyLog::create([
            'company_id' => $this->company->id,
            'employee_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'contract_id' => $contract->id,
            'log_date' => '2026-07-17',
            'orders_count' => 3,
            'zone' => 'Zone A',
            'is_valid' => true,
            'created_by' => $this->user->id,
        ]);

        DailyLog::create([
            'company_id' => $this->company->id,
            'employee_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'contract_id' => $contract->id,
            'log_date' => '2026-07-18',
            'orders_count' => 3,
            'zone' => 'Zone A',
            'is_valid' => true,
            'created_by' => $this->user->id,
        ]);

        DailyLog::create([
            'company_id' => $this->company->id,
            'employee_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'contract_id' => $contract->id,
            'log_date' => '2026-07-19',
            'orders_count' => 3,
            'zone' => 'Zone A',
            'is_valid' => true,
            'created_by' => $this->user->id,
        ]);

        // Run payroll for July 2026
        $response = $this->postJson('/api/payroll/run', [
            'year' => 2026,
            'month' => 7,
        ]);

        $response->assertStatus(201);
        
        // Assert that the payroll slip shows the prorated base salary payout of 13.5 KWD
        $this->assertDatabaseHas('payroll_slips', [
            'employee_id' => $driver->id,
            'base_actual' => 13.500, // 9 orders * 1.5 KWD
            'gross_actual' => 13.500,
        ]);
    }

    public function test_contract_deletion_safeguards()
    {
        $this->actingAs($this->user);

        // 1. Assign a driver to a contract and try to delete it (should fail)
        $unlockedContract = Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-UNLOCKED',
            'name' => 'Unlocked Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'company_id' => $this->company->id,
        ]);

        $driver = Employee::create([
            'name' => 'Driver 2',
            'employee_number' => 'EMP-003',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-07-01',
        ]);

        \App\Models\ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $unlockedContract->id,
            'start_date' => '2026-07-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        $response = $this->deleteJson("/api/contracts/{$unlockedContract->id}");
        $response->assertStatus(422); // Validation block on deletion
        $this->assertDatabaseHas('contracts', ['id' => $unlockedContract->id]);
    }

    public function test_employee_deletion_safeguards()
    {
        $this->actingAs($this->user);

        $driver = Employee::create([
            'name' => 'Driver 3',
            'employee_number' => 'EMP-004',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-07-01',
        ]);

        $vehicle = Vehicle::create([
            'plate_number' => 'V-3',
            'make' => 'Nissan',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        // Assign vehicle to driver
        VehicleAssignment::create([
            'employee_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'is_active' => true,
            'assigned_date' => '2026-07-01',
            'company_id' => $this->company->id,
        ]);

        // Try to delete employee (should fail due to active assignment)
        $response = $this->deleteJson("/api/employees/{$driver->id}");
        $response->assertStatus(422);
        $this->assertDatabaseHas('employees', ['id' => $driver->id]);
    }
}
