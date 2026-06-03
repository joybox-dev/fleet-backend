<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\DailyLog;
use App\Models\VehicleHandover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase13ExtraFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Employee $employee;
    private Vehicle $vehicle;
    private \App\Models\Client $client;
    private \App\Models\Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Mersal Test',
            'code' => 'mersal_test',
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

        $this->client = \App\Models\Client::create([
            'name' => 'Test Client',
            'company_id' => $this->company->id,
        ]);

        $this->contract = \App\Models\Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-TEST',
            'name' => 'Test Contract',
            'payment_type' => 'fixed',
            'fixed_monthly' => 1000,
            'start_date' => '2026-05-01',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $this->employee = Employee::create([
            'name' => 'Test Driver',
            'employee_number' => 'EMP-0001',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-05-01',
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => '12345-KUWAIT',
            'make' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2024,
            'color' => 'White',
            'status' => 'available',
            'odometer_km' => 1000,
            'company_id' => $this->company->id,
        ]);
    }

    /**
     * Test vehicle odometer updates automatically on DailyLog creation/update.
     */
    public function test_vehicle_odometer_syncs_from_daily_log()
    {
        $this->actingAs($this->user);

        // 1. Create a daily log with odometer_end. Odometer KM of vehicle should increase.
        $log = DailyLog::create([
            'vehicle_id' => $this->vehicle->id,
            'employee_id' => $this->employee->id,
            'contract_id' => $this->contract->id,
            'created_by' => $this->user->id,
            'log_date' => '2026-06-01',
            'orders_count' => 10,
            'orders_online' => 5,
            'orders_cash' => 5,
            'cash_collected' => 50,
            'odometer_start' => 1000,
            'odometer_end' => 1200,
            'odometer_photo_path' => 'photos/odometer_end.jpg',
            'company_id' => $this->company->id,
        ]);

        // Refresh vehicle
        $this->vehicle->refresh();
        $this->assertEquals(1200, $this->vehicle->odometer_km);

        // 2. Update daily log to higher odometer_end. Vehicle odometer should update.
        $log->update(['odometer_end' => 1350]);
        $this->vehicle->refresh();
        $this->assertEquals(1350, $this->vehicle->odometer_km);

        // 3. Update to lower odometer_end. Vehicle odometer should NOT decrease (failsafe).
        $log->update(['odometer_end' => 1100]);
        $this->vehicle->refresh();
        $this->assertEquals(1350, $this->vehicle->odometer_km);
    }

    /**
     * Test validation fails when odometer_end is present but odometer_photo_path is absent.
     */
    public function test_daily_log_validation_mandates_odometer_photo()
    {
        $this->actingAs($this->user);

        // Try creating daily log with odometer_end but NO odometer_photo_path. Should fail validation.
        $response = $this->postJson('/api/daily-logs', [
            'vehicle_id' => $this->vehicle->id,
            'employee_id' => $this->employee->id,
            'contract_id' => $this->contract->id,
            'log_date' => '2026-06-01',
            'orders_count' => 10,
            'orders_online' => 5,
            'orders_cash' => 5,
            'cash_collected' => 50,
            'odometer_start' => 1000,
            'odometer_end' => 1200,
            // 'odometer_photo_path' missing
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['odometer_photo_path']);

        // Try creating with empty string. Should also fail validation.
        $response = $this->postJson('/api/daily-logs', [
            'vehicle_id' => $this->vehicle->id,
            'employee_id' => $this->employee->id,
            'contract_id' => $this->contract->id,
            'log_date' => '2026-06-01',
            'orders_count' => 10,
            'orders_online' => 5,
            'orders_cash' => 5,
            'cash_collected' => 50,
            'odometer_start' => 1000,
            'odometer_end' => 1200,
            'odometer_photo_path' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['odometer_photo_path']);

        // Create with photo path. Should succeed.
        $response = $this->postJson('/api/daily-logs', [
            'vehicle_id' => $this->vehicle->id,
            'employee_id' => $this->employee->id,
            'contract_id' => $this->contract->id,
            'log_date' => '2026-06-01',
            'orders_count' => 10,
            'orders_online' => 5,
            'orders_cash' => 5,
            'cash_collected' => 50,
            'odometer_start' => 1000,
            'odometer_end' => 1200,
            'odometer_photo_path' => 'photos/odo.jpg',
        ]);

        $response->assertStatus(201);
    }

    /**
     * Test vehicle handover protocol endpoint validation and behavior.
     */
    public function test_vehicle_handover_protocol()
    {
        $this->actingAs($this->user);

        // 1. Create a handover protocol
        $response = $this->postJson('/api/vehicle-handovers', [
            'vehicle_id' => $this->vehicle->id,
            'employee_id' => $this->employee->id,
            'handover_date' => '2026-06-01',
            'type' => 'handover',
            'odometer_reading' => 1500,
            'photo_front' => 'handovers/front.jpg',
            'photo_back' => 'handovers/back.jpg',
            'photo_left' => 'handovers/left.jpg',
            'photo_right' => 'handovers/right.jpg',
            'scratches_details' => 'Scratch on left door',
            'notes' => 'Handed over clean',
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['scratches_details' => 'Scratch on left door']);

        // Check vehicle odometer updated
        $this->vehicle->refresh();
        $this->assertEquals(1500, $this->vehicle->odometer_km);

        $handoverId = $response->json('id');

        // 2. Fetch handover list
        $response = $this->getJson("/api/vehicle-handovers?employee_id={$this->employee->id}");
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());

        // 3. Fetch single handover details
        $response = $this->getJson("/api/vehicle-handovers/{$handoverId}");
        $response->assertStatus(200);
        $response->assertJsonFragment(['notes' => 'Handed over clean']);

        // 4. Delete handover (soft delete check)
        $response = $this->deleteJson("/api/vehicle-handovers/{$handoverId}");
        $response->assertStatus(200);

        $this->assertSoftDeleted('vehicle_handovers', ['id' => $handoverId]);
    }
}
