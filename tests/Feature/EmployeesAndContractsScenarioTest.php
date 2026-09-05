<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
            'default_required_work_days' => 26,
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
            'client_pricing_rules' => ['1' => ['payment_method' => 'zones', 'zones' => [['id' => 'Z1', 'name' => 'شمال', 'price' => 0.300]]]],
            'driver_payment_method' => 'zones',
            'driver_pricing_rules' => ['1' => ['payment_method' => 'zones', 'zones' => [['id' => 'Z1', 'name' => 'شمال', 'price' => 0.200]]]],
            'default_required_work_days' => 26,
            'currency' => 'KWD',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('contracts', [
            'contract_number' => 'CON-1',
            'driver_payment_method' => 'zones',
            'client_payment_method' => 'zones',
        ]);
    }

    /**
     * How a contract bills and how it pays are the two figures every other number is built from,
     * and both used to be optional. A contract could be saved with neither, and the engines then
     * guessed: a client rule with no stated method was read as «zones», so a rule carrying a
     * perfectly good monthly amount priced nothing and said nothing about why.
     */
    private function contractPayload(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-REQ',
            'name' => 'عقد',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'default_required_work_days' => 26,
            'currency' => 'KWD',
            'client_payment_method' => 'fixed',
            'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 900]],
            'driver_payment_method' => 'fixed',
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260]],
        ], $overrides);
    }

    public function test_a_contract_cannot_be_saved_without_saying_how_it_bills_and_pays(): void
    {
        $this->actingAs($this->user);

        foreach ([
            'client_payment_method',
            'client_pricing_rules',
            'driver_payment_method',
            'driver_pricing_rules',
        ] as $field) {
            $payload = $this->contractPayload();
            unset($payload[$field]);

            $this->postJson('/api/contracts', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->postJson('/api/contracts', $this->contractPayload())->assertStatus(201);
    }

    public function test_a_pricing_rule_that_does_not_say_how_it_bills_is_refused(): void
    {
        $this->actingAs($this->user);

        // The exact shape that used to be read as zones and silently billed nothing.
        $this->postJson('/api/contracts', $this->contractPayload([
            'client_pricing_rules' => ['1' => ['fixed_amount' => 900]],
        ]))->assertStatus(422)->assertJsonValidationErrors('client_pricing_rules');
    }

    public function test_a_rule_that_contradicts_the_contracts_own_method_is_refused(): void
    {
        $this->actingAs($this->user);

        // The screen showed the column and the money followed the rule, with nothing to say they
        // disagreed.
        $this->postJson('/api/contracts', $this->contractPayload([
            'client_payment_method' => 'fixed',
            'client_pricing_rules' => ['1' => [
                'payment_method' => 'zones',
                'zones' => [['id' => 'Z1', 'name' => 'شمال', 'price' => 0.300]],
            ]],
        ]))->assertStatus(422)->assertJsonValidationErrors('client_pricing_rules');
    }

    public function test_a_rule_missing_the_figures_its_method_needs_is_refused(): void
    {
        $this->actingAs($this->user);

        // Says tiers, carries no band — bills nothing, every month, in silence.
        $this->postJson('/api/contracts', $this->contractPayload([
            'client_payment_method' => 'tiers',
            'client_pricing_rules' => ['1' => ['payment_method' => 'tiers', 'fixed_amount' => 900]],
        ]))->assertStatus(422)->assertJsonValidationErrors('client_pricing_rules');

        // Says zones, and one of its zones has no price.
        $this->postJson('/api/contracts', $this->contractPayload([
            'client_payment_method' => 'zones',
            'client_pricing_rules' => ['1' => ['payment_method' => 'zones', 'zones' => [
                ['id' => 'Z1', 'name' => 'شمال', 'price' => 0.300],
                ['id' => 'Z2', 'name' => 'جنوب'],
            ]]],
            'driver_payment_method' => 'zones',
            'driver_pricing_rules' => ['1' => ['payment_method' => 'zones', 'zones' => [
                ['id' => 'Z1', 'name' => 'شمال', 'price' => 0.200],
                ['id' => 'Z2', 'name' => 'جنوب', 'price' => 0.150],
            ]]],
        ]))->assertStatus(422)->assertJsonValidationErrors('client_pricing_rules');
    }

    public function test_editing_an_unrelated_field_does_not_demand_pricing_that_is_already_there(): void
    {
        $this->actingAs($this->user);

        $id = $this->postJson('/api/contracts', $this->contractPayload())
            ->assertStatus(201)->json('id');

        $this->putJson("/api/contracts/{$id}", ['name' => 'اسم جديد'])->assertStatus(200);
    }

    public function test_a_contract_that_never_stated_its_pricing_must_state_it_when_next_edited(): void
    {
        $this->actingAs($this->user);

        // What the live contracts look like: saved before either field was demanded.
        $contract = Contract::create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'contract_number' => 'CON-OLD',
            'name' => 'عقد قديم',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'default_required_work_days' => 26,
        ]);

        $this->putJson("/api/contracts/{$contract->id}", ['name' => 'اسم جديد'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_payment_method', 'client_pricing_rules']);

        $this->putJson("/api/contracts/{$contract->id}", [
            'name' => 'اسم جديد',
            'client_payment_method' => 'fixed',
            'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 900]],
            'driver_payment_method' => 'fixed',
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260]],
        ])->assertStatus(200);
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
                '1' => ['payment_method' => 'fixed', 'fixed_amount' => 500], // vehicle_type_id = 1
            ],
            'driver_pricing_rules' => [
                '1' => ['payment_method' => 'fixed', 'fixed_amount' => 200],
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
        $assignment = ContractAssignment::create([
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
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['override_type']);
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

        ContractAssignment::create([
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
