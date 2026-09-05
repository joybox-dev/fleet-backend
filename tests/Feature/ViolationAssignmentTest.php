<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\Violation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViolationAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Vehicle $vehicle;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup company
        $this->company = Company::create([
            'name' => 'Test Company',
            'code' => 'TESTC',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        // Bind Company immediately after creating it
        app()->instance('current_company_id', $this->company->id);

        // Setup user (admin)
        $this->user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        // Setup client & contract
        $client = Client::create([
            'name' => 'Test Client',
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-12345',
            'name' => 'Test Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'status' => 'active',
            'target_orders_monthly' => 400,
            'base_commission_rate' => 0.250,
            'premium_commission_rate' => 0.500,
        ]);

        // Setup driver
        $this->driver = Employee::create([
            'name' => 'John Driver',
            'role' => 'driver',
            'status' => 'active',
            'date_of_joining' => '2026-05-01',
            'salary_monthly' => 200,
        ]);

        // Setup vehicle
        $this->vehicle = Vehicle::create([
            'plate_number' => '12345-KUWAIT',
            'make' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2024,
            'color' => 'White',
            'status' => 'active',
            'odometer_km' => 1000,
        ]);

        // Create assignment (active from May 10 to May 20)
        VehicleAssignment::create([
            'vehicle_id' => $this->vehicle->id,
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'assigned_date' => '2026-05-10',
            'unassigned_date' => '2026-05-20',
            'is_active' => false, // historically unassigned on May 20
        ]);
    }

    public function test_it_can_resolve_driver_for_a_given_datetime()
    {
        // Act as the authenticated admin user
        $this->actingAs($this->user);

        // Date falls within assignment period (May 10 to May 20)
        $response = $this->getJson("/api/violations/resolve-driver?vehicle_id={$this->vehicle->id}&violation_date=2026-05-15%2014:30:00");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'employee' => [
                'id' => $this->driver->id,
                'name' => 'John Driver',
            ],
        ]);

        // Date falls outside assignment period (before May 10)
        $responseOutsideBefore = $this->getJson("/api/violations/resolve-driver?vehicle_id={$this->vehicle->id}&violation_date=2026-05-05%2014:30:00");
        $responseOutsideBefore->assertStatus(404);
        $responseOutsideBefore->assertJson([
            'success' => false,
            'message' => 'No active driver found for this vehicle on the specified date/time.',
        ]);

        // Date falls outside assignment period (after May 20)
        $responseOutsideAfter = $this->getJson("/api/violations/resolve-driver?vehicle_id={$this->vehicle->id}&violation_date=2026-05-25%2014:30:00");
        $responseOutsideAfter->assertStatus(404);
    }

    public function test_it_automatically_assigns_driver_on_violation_creation()
    {
        $this->actingAs($this->user);

        // Create violation at datetime within assignment (May 15)
        $response = $this->postJson('/api/violations', [
            'vehicle_id' => $this->vehicle->id,
            'violation_date' => '2026-05-15 11:20:00',
            'violation_type' => 'تجاوز السرعة',
            'amount' => 50,
            'photo_path' => 'violations/ticket.jpg',
            'is_driver_liable' => true,
            'reference_number' => 'TX-98765',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'violation_date' => '2026-05-15 11:20:00',
            'amount' => '50.000',
        ]);

        // Verify violation was stored in database
        $this->assertDatabaseHas('violations', [
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'violation_date' => '2026-05-15 11:20:00',
            'amount' => 50.000,
        ]);
    }

    public function test_it_fails_creation_if_no_driver_is_assigned_at_violation_datetime()
    {
        $this->actingAs($this->user);

        // Attempt to create violation at datetime outside assignment (May 25)
        $response = $this->postJson('/api/violations', [
            'vehicle_id' => $this->vehicle->id,
            'violation_date' => '2026-05-25 10:00:00',
            'violation_type' => 'إشارة حمراء',
            'amount' => 100,
            'photo_path' => 'violations/ticket.jpg',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'No active driver was assigned to this vehicle at the specified date/time.',
        ]);
    }
}
