<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\CustodyType;
use App\Models\CustodyItem;
use App\Models\SalaryAdvance;
use App\Models\DriverGuarantee;
use App\Models\DailyLog;
use App\Models\Violation;
use App\Models\MaintenanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SafeDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Employee $employee;
    private Vehicle $vehicle;
    private Client $client;
    private Contract $contract;
    private CustodyType $custodyType;

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

        $this->client = Client::create([
            'name' => 'Test Client',
            'company_id' => $this->company->id,
        ]);

        $this->contract = Contract::create([
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
            'company_id' => $this->company->id,
        ]);

        $this->custodyType = CustodyType::create([
            'name' => 'Phone',
            'icon' => '📱',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_employee_deletion_checks_and_blocks()
    {
        $this->actingAs($this->user);

        // 1. Success case: Deleting clean employee
        $cleanEmp = Employee::create([
            'name' => 'Clean Driver',
            'employee_number' => 'EMP-0002',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-05-01',
        ]);
        $response = $this->getJson("/api/employees/{$cleanEmp->id}/deletion-check");
        $response->assertStatus(200)->assertJson(['is_deletable' => true, 'blocks' => []]);

        $delResponse = $this->deleteJson("/api/employees/{$cleanEmp->id}");
        $delResponse->assertStatus(200);
        $this->assertSoftDeleted('employees', ['id' => $cleanEmp->id]);

        // 2. Block: Active vehicle assignment
        $assignment = VehicleAssignment::create([
            'vehicle_id' => $this->vehicle->id,
            'employee_id' => $this->employee->id,
            'contract_id' => $this->contract->id,
            'assigned_date' => '2026-05-01',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);
        
        $response = $this->getJson("/api/employees/{$this->employee->id}/deletion-check");
        $response->assertStatus(200)->assertJson(['is_deletable' => false]);
        $response->assertJsonFragment(['الموظف لديه سيارة معينة نشطة حالياً.']);

        $delResponse = $this->deleteJson("/api/employees/{$this->employee->id}");
        $delResponse->assertStatus(422);

        // Clean assignment
        $assignment->delete();

        // 3. Block: Unreturned custody item
        $custody = CustodyItem::create([
            'employee_id' => $this->employee->id,
            'custody_type_id' => $this->custodyType->id,
            'item_description' => 'iPhone 13',
            'issued_date' => '2026-05-01',
            'company_id' => $this->company->id,
            'issued_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/employees/{$this->employee->id}/deletion-check");
        $response->assertJsonFragment(['الموظف لديه عُهد غير مسترجعة في ذمته.']);
        $this->deleteJson("/api/employees/{$this->employee->id}")->assertStatus(422);

        // Clean custody
        $custody->delete();

        // 4. Block: Salary advance with balance
        $advance = SalaryAdvance::create([
            'employee_id' => $this->employee->id,
            'amount' => 150,
            'monthly_installment' => 50,
            'total_installments' => 3,
            'remaining_balance' => 150,
            'advance_date' => '2026-05-01',
            'status' => 'active',
            'company_id' => $this->company->id,
            'approved_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/employees/{$this->employee->id}/deletion-check");
        $response->assertJsonFragment(['الموظف لديه سلف مالية نشطة متبقي عليها أرصدة لم تسدد.']);
        $this->deleteJson("/api/employees/{$this->employee->id}")->assertStatus(422);

        // Clean advance
        $advance->delete();
    }

    public function test_vehicle_deletion_checks_and_blocks()
    {
        $this->actingAs($this->user);

        // 1. Success case: Deleting clean vehicle
        $cleanVehicle = Vehicle::create([
            'plate_number' => '54321-KUWAIT',
            'make' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2024,
            'color' => 'Black',
            'status' => 'available',
            'company_id' => $this->company->id,
        ]);
        $this->getJson("/api/vehicles/{$cleanVehicle->id}/deletion-check")
            ->assertStatus(200)->assertJson(['is_deletable' => true]);

        $this->deleteJson("/api/vehicles/{$cleanVehicle->id}")->assertStatus(200);

        // 2. Block: Active assignment
        $assignment = VehicleAssignment::create([
            'vehicle_id' => $this->vehicle->id,
            'employee_id' => $this->employee->id,
            'contract_id' => $this->contract->id,
            'assigned_date' => '2026-05-01',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $this->getJson("/api/vehicles/{$this->vehicle->id}/deletion-check")
            ->assertJsonFragment(['السيارة معينة لسائق نشط حالياً.']);
        $this->deleteJson("/api/vehicles/{$this->vehicle->id}")->assertStatus(422);

        $assignment->delete();

        // 3. Block: Pending maintenance
        $maintenance = MaintenanceRecord::create([
            'vehicle_id' => $this->vehicle->id,
            'reported_by' => $this->user->id,
            'maintenance_type' => 'repair',
            'maintenance_date' => '2026-05-01',
            'status' => 'pending',
            'company_id' => $this->company->id,
        ]);

        $this->getJson("/api/vehicles/{$this->vehicle->id}/deletion-check")
            ->assertJsonFragment(['السيارة لديها أعمال صيانة معلقة أو معتمدة قيد التنفيذ.']);
        $this->deleteJson("/api/vehicles/{$this->vehicle->id}")->assertStatus(422);

        $maintenance->delete();
    }

    public function test_client_deletion_checks_and_blocks()
    {
        $this->actingAs($this->user);

        // 1. Success case: Deleting clean client
        $cleanClient = Client::create([
            'name' => 'Clean Client',
            'company_id' => $this->company->id,
        ]);
        $this->getJson("/api/clients/{$cleanClient->id}/deletion-check")
            ->assertStatus(200)->assertJson(['is_deletable' => true]);
        $this->deleteJson("/api/clients/{$cleanClient->id}")->assertStatus(200);

        // 2. Block: Client with active contracts
        $this->getJson("/api/clients/{$this->client->id}/deletion-check")
            ->assertJsonFragment(['لا يمكن حذف العميل لوجود عقود نشطة مرتبطة به.']);
        $this->deleteJson("/api/clients/{$this->client->id}")->assertStatus(422);
    }

    public function test_contract_deletion_checks_and_blocks()
    {
        $this->actingAs($this->user);

        // 1. Success: Deleting clean contract
        $cleanContract = Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-CLEAN',
            'name' => 'Clean Contract',
            'payment_type' => 'fixed',
            'fixed_monthly' => 500,
            'start_date' => '2026-05-01',
            'is_active' => false,
            'company_id' => $this->company->id,
        ]);
        $this->getJson("/api/contracts/{$cleanContract->id}/deletion-check")
            ->assertStatus(200)->assertJson(['is_deletable' => true]);
        $this->deleteJson("/api/contracts/{$cleanContract->id}")->assertStatus(200);

        // 2. Block: Locked contract
        $lockedContract = Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-LOCKED',
            'name' => 'Locked Contract',
            'payment_type' => 'fixed',
            'fixed_monthly' => 500,
            'start_date' => '2026-05-01',
            'is_active' => true,
            'is_locked' => true,
            'company_id' => $this->company->id,
        ]);
        $this->getJson("/api/contracts/{$lockedContract->id}/deletion-check")
            ->assertJsonFragment(['لا يمكن حذف العقد لأنه مغلق ومحمي محاسبياً ضد التعديل.']);
        $this->deleteJson("/api/contracts/{$lockedContract->id}")->assertStatus(422);
    }
}
