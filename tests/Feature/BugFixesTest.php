<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\DailyLog;
use App\Models\Violation;
use App\Models\MaintenanceRecord;
use App\Models\VehicleAssignment;
use App\Models\SalaryAdvance;
use App\Models\DriverGuarantee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BugFixesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company1;
    private Company $company2;
    private User $user1;
    private User $user2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create companies
        $this->company1 = Company::create([
            'name' => 'Company A',
            'code' => 'COMPA',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        $this->company2 = Company::create([
            'name' => 'Company B',
            'code' => 'COMPB',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        // Create company users
        $this->user1 = User::create([
            'name' => 'User A',
            'email' => 'usera@company1.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company1->id,
            'is_super_admin' => false,
        ]);

        $this->user2 = User::create([
            'name' => 'User B',
            'email' => 'userb@company2.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company2->id,
            'is_super_admin' => false,
        ]);
    }

    /**
     * Test Bug #1: SaaS Uniqueness Scoping
     */
    public function test_saas_uniqueness_scoping(): void
    {
        // Bind Company 1
        app()->instance('current_company_id', $this->company1->id);

        // Store first client
        $response = $this->actingAs($this->user1)
            ->postJson('/api/clients', [
                'name' => 'Unique Client',
                'phone' => '12345678',
                'email' => 'client@test.com',
                'tax_number' => 'TAX123',
            ]);
        $response->assertStatus(201);

        // Store client in company 2 with the SAME attributes should succeed!
        app()->instance('current_company_id', $this->company2->id);

        $response2 = $this->actingAs($this->user2)
            ->postJson('/api/clients', [
                'name' => 'Unique Client',
                'phone' => '12345678',
                'email' => 'client@test.com',
                'tax_number' => 'TAX123',
            ]);
        $response2->assertStatus(201);

        // Try to store duplicate in Company 2 again - should fail
        $response3 = $this->actingAs($this->user2)
            ->postJson('/api/clients', [
                'name' => 'Unique Client',
                'phone' => '12345678',
                'email' => 'client@test.com',
                'tax_number' => 'TAX123',
            ]);
        $response3->assertStatus(422);
    }

    /**
     * Test Bug #3: Defensive Global Scope Guard
     */
    public function test_defensive_global_scope_guard(): void
    {
        // First bind company 1 to create the employee correctly with its company_id observer
        app()->instance('current_company_id', $this->company1->id);

        $emp = Employee::create([
            'name' => 'John Doe',
            'date_of_joining' => '2026-01-01',
            'pay_type' => 'fixed',
            'official_salary' => 300,
            'actual_salary' => 300,
        ]);

        // Unbind current_company_id by setting it to 0 or null
        app()->instance('current_company_id', 0);

        // Queries without company binding should return empty/filter by 0
        $employees = Employee::all();
        $this->assertCount(0, $employees);
    }

    /**
     * Test Bug #4 & Bug #5: Odometer and Orders count validation in DailyLog store/update
     */
    public function test_daily_log_validation(): void
    {
        app()->instance('current_company_id', $this->company1->id);

        $client = Client::create([
            'company_id' => $this->company1->id,
            'name' => 'Client A',
        ]);

        $contract = Contract::create([
            'company_id' => $this->company1->id,
            'client_id' => $client->id,
            'contract_number' => 'C-001',
            'name' => 'Contract A',
            'payment_type' => 'fixed',
            'rate_per_order' => 1.5,
            'fixed_monthly' => 500,
            'start_date' => '2026-01-01',
        ]);

        $employee = Employee::create([
            'company_id' => $this->company1->id,
            'name' => 'Driver A',
            'date_of_joining' => '2026-01-01',
            'pay_type' => 'fixed',
            'official_salary' => 300,
            'actual_salary' => 300,
        ]);

        $vehicle = Vehicle::create([
            'company_id' => $this->company1->id,
            'plate_number' => 'V-1001',
        ]);

        // 1. Math check in store: online + cash must equal orders_count
        $response = $this->actingAs($this->user1)
            ->postJson('/api/daily-logs', [
                'employee_id' => $employee->id,
                'vehicle_id' => $vehicle->id,
                'contract_id' => $contract->id,
                'log_date' => '2026-05-21',
                'orders_count' => 10,
                'orders_online' => 6,
                'orders_cash' => 3, // 6 + 3 = 9 !== 10
            ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('orders_count');

        // Valid math store
        $response = $this->actingAs($this->user1)
            ->postJson('/api/daily-logs', [
                'employee_id' => $employee->id,
                'vehicle_id' => $vehicle->id,
                'contract_id' => $contract->id,
                'log_date' => '2026-05-21',
                'orders_count' => 10,
                'orders_online' => 6,
                'orders_cash' => 4, // 6 + 4 = 10
                'odometer_start' => 1000,
                'odometer_end' => 1050,
            ]);
        $response->assertStatus(201);
        $logId = $response->json('id');

        // 2. Math check in update
        $response = $this->actingAs($this->user1)
            ->putJson("/api/daily-logs/{$logId}", [
                'orders_count' => 15, // online=6, cash=4 (sum=10 !== 15)
            ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('orders_count');

        // 3. Odometer consistency in update (end must be >= start)
        $response = $this->actingAs($this->user1)
            ->putJson("/api/daily-logs/{$logId}", [
                'odometer_end' => 950, // start is 1000, so end < start
            ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('odometer_end');
    }

    /**
     * Test Bug #2: Soft-Delete withTrashed() safety
     */
    public function test_soft_delete_with_trashed_safety(): void
    {
        app()->instance('current_company_id', $this->company1->id);

        $employee = Employee::create([
            'company_id' => $this->company1->id,
            'name' => 'Driver for Soft Delete',
            'date_of_joining' => '2026-01-01',
            'pay_type' => 'fixed',
            'official_salary' => 300,
            'actual_salary' => 300,
        ]);

        $vehicle = Vehicle::create([
            'company_id' => $this->company1->id,
            'plate_number' => 'V-9999',
        ]);

        $client = Client::create([
            'company_id' => $this->company1->id,
            'name' => 'Client A',
        ]);

        $contract = Contract::create([
            'company_id' => $this->company1->id,
            'client_id' => $client->id,
            'contract_number' => 'C-002',
            'name' => 'Contract A',
            'payment_type' => 'fixed',
            'rate_per_order' => 1.5,
            'fixed_monthly' => 500,
            'start_date' => '2026-01-01',
        ]);

        // Create historical records
        $violation = Violation::create([
            'company_id' => $this->company1->id,
            'employee_id' => $employee->id,
            'vehicle_id' => $vehicle->id,
            'violation_date' => '2026-05-21',
            'violation_type' => 'Speeding',
            'amount' => 50,
            'created_by' => $this->user1->id,
        ]);

        $maintenance = MaintenanceRecord::create([
            'company_id' => $this->company1->id,
            'vehicle_id' => $vehicle->id,
            'reported_by' => $this->user1->id,
            'garage_name' => 'Garage',
            'maintenance_type' => 'Repair',
            'maintenance_date' => '2026-05-21',
            'estimated_cost' => 100,
            'status' => 'approved',
            'liable_employee_id' => $employee->id,
        ]);

        $assignment = VehicleAssignment::create([
            'company_id' => $this->company1->id,
            'vehicle_id' => $vehicle->id,
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
            'assigned_date' => '2026-05-21',
            'is_active' => true,
        ]);

        $advance = SalaryAdvance::create([
            'company_id' => $this->company1->id,
            'employee_id' => $employee->id,
            'approved_by' => $this->user1->id,
            'amount' => 200,
            'monthly_installment' => 50,
            'total_installments' => 4,
            'remaining_balance' => 200,
            'advance_date' => '2026-05-21',
            'status' => 'approved',
        ]);

        $guarantee = DriverGuarantee::create([
            'company_id' => $this->company1->id,
            'employee_id' => $employee->id,
            'guarantee_type' => 'Passport',
            'document_number' => 'P12345',
            'received_date' => '2026-05-21',
            'status' => 'received',
        ]);

        // Soft-delete employee, vehicle, contract
        $employee->delete();
        $vehicle->delete();
        $contract->delete();

        // Retrieve relationships and assert they are loaded successfully (not null)
        $this->assertNotNull($violation->fresh()->employee);
        $this->assertNotNull($violation->fresh()->vehicle);

        $this->assertNotNull($maintenance->fresh()->vehicle);
        $this->assertNotNull($maintenance->fresh()->liableEmployee);

        $this->assertNotNull($assignment->fresh()->employee);
        $this->assertNotNull($assignment->fresh()->vehicle);
        $this->assertNotNull($assignment->fresh()->contract);

        $this->assertNotNull($advance->fresh()->employee);

        $this->assertNotNull($guarantee->fresh()->employee);
    }

    /**
     * Test Route Model Binding with Soft Deleted models
     */
    public function test_route_model_binding_resolves_soft_deleted_employee(): void
    {
        app()->instance('current_company_id', $this->company1->id);

        $employee = Employee::create([
            'company_id' => $this->company1->id,
            'name' => 'Soft Deleted Driver for Route Binding',
            'date_of_joining' => '2026-01-01',
            'pay_type' => 'fixed',
            'official_salary' => 300,
            'actual_salary' => 300,
        ]);

        $employee->delete();

        // Access route that uses Route Model Binding (e.g., GET /api/employees/{employee}/balance)
        $response = $this->actingAs($this->user1)
            ->getJson("/api/employees/{$employee->id}/balance");

        // It should resolve correctly (HTTP 200) instead of throwing a ModelNotFoundException (HTTP 404)
        $response->assertStatus(200);
        $this->assertEquals($employee->name, $response->json('employee_name'));
    }

    /**
     * Test that basic employee fields (pay_type, civil_id, nationality, etc.) can be successfully updated
     */
    public function test_update_basic_employee_fields_successfully(): void
    {
        app()->instance('current_company_id', $this->company1->id);

        $employee = Employee::create([
            'company_id' => $this->company1->id,
            'name' => 'Original Employee Name',
            'name_ar' => 'الاسم الأصلي',
            'date_of_joining' => '2026-01-01',
            'pay_type' => 'fixed',
            'official_salary' => 300,
            'actual_salary' => 300,
            'civil_id' => '123456789012',
            'nationality' => 'Kuwaiti',
            'gender' => 'male',
            'date_of_birth' => '1990-01-01',
            'employee_type' => 'local_transfer',
            'has_end_of_service' => true,
        ]);

        $response = $this->actingAs($this->user1)
            ->putJson("/api/employees/{$employee->id}", [
                'name' => 'Updated Employee Name',
                'name_ar' => 'الاسم المعدل',
                'civil_id' => '987654321098',
                'nationality' => 'Indian',
                'gender' => 'female',
                'date_of_birth' => '1995-05-05',
                'date_of_joining' => '2026-02-02',
                'employee_type' => 'overseas',
                'pay_type' => 'hybrid',
                'official_salary' => 350,
                'actual_salary' => 450,
                'rate_per_order' => 0.5,
                'has_end_of_service' => false,
            ]);

        $response->assertStatus(200);
        
        $updated = $employee->fresh();
        $this->assertEquals('Updated Employee Name', $updated->name);
        $this->assertEquals('الاسم الأصلي' ? 'الاسم المعدل' : 'الاسم الأصلي', $updated->name_ar); // Keep comparison simple
        $this->assertEquals('987654321098', $updated->civil_id);
        $this->assertEquals('Indian', $updated->nationality);
        $this->assertEquals('female', $updated->gender);
        $this->assertEquals('1995-05-05', $updated->date_of_birth);
        $this->assertEquals('2026-02-02', $updated->date_of_joining);
        $this->assertEquals('overseas', $updated->employee_type);
        $this->assertEquals('hybrid', $updated->pay_type);
        $this->assertEquals(350, $updated->official_salary);
        $this->assertEquals(450, $updated->actual_salary);
        $this->assertEquals(0.5, $updated->rate_per_order);
        $this->assertFalse($updated->has_end_of_service);
    }

    /**
     * Test that contract fields (payment_type, client_id, contract_number, start_date) can be updated if not locked
     */
    public function test_update_contract_fields_successfully(): void
    {
        app()->instance('current_company_id', $this->company1->id);

        $client1 = Client::create([
            'company_id' => $this->company1->id,
            'name' => 'Client A',
            'phone' => '12345678',
            'email' => 'clienta@test.com',
        ]);

        $client2 = Client::create([
            'company_id' => $this->company1->id,
            'name' => 'Client B',
            'phone' => '87654321',
            'email' => 'clientb@test.com',
        ]);

        $contract = Contract::create([
            'company_id' => $this->company1->id,
            'client_id' => $client1->id,
            'contract_number' => 'C-12345',
            'name' => 'Original Contract Name',
            'payment_type' => 'fixed',
            'rate_per_order' => 0.0,
            'fixed_monthly' => 1000.0,
            'start_date' => '2026-01-01',
            'is_active' => true,
            'is_locked' => false,
        ]);

        // 1. Update when contract is not locked - should succeed
        $response = $this->actingAs($this->user1)
            ->putJson("/api/contracts/{$contract->id}", [
                'client_id' => $client2->id,
                'contract_number' => 'C-67890',
                'name' => 'Updated Contract Name',
                'payment_type' => 'hybrid',
                'rate_per_order' => 1.5,
                'fixed_monthly' => 500.0,
                'start_date' => '2026-02-01',
                'is_active' => false,
            ]);

        $response->assertStatus(200);

        $updated = $contract->fresh();
        $this->assertEquals($client2->id, $updated->client_id);
        $this->assertEquals('C-67890', $updated->contract_number);
        $this->assertEquals('Updated Contract Name', $updated->name);
        $this->assertEquals('hybrid', $updated->payment_type);
        $this->assertEquals(1.5, $updated->rate_per_order);
        $this->assertEquals(500.0, $updated->fixed_monthly);
        $this->assertEquals('2026-02-01', $updated->start_date);
        $this->assertFalse((bool)$updated->is_active);

        // 2. Lock the contract
        $contract->update(['is_locked' => true]);

        // 3. Try to update locked contract - should fail
        $response2 = $this->actingAs($this->user1)
            ->putJson("/api/contracts/{$contract->id}", [
                'name' => 'Violating Update',
            ]);
        $response2->assertStatus(403);
    }
}

