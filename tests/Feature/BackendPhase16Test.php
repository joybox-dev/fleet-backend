<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\Violation;
use App\Models\PayrollSlip;
use App\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackendPhase16Test extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $adminUser;
    protected User $operatorUser;
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

        // Create Operator User
        $this->operatorUser = User::create([
            'name' => 'Operator User',
            'email' => 'operator@test.com',
            'password' => bcrypt('password'),
            'role' => 'operator',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_it_can_manage_custom_roles()
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/roles', [
                'name' => 'موظف عقود',
                'allowed_modules' => ['contracts', 'clients']
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('roles', [
            'name' => 'موظف عقود',
            'company_id' => $this->company->id
        ]);

        $roleId = $response->json('id');

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/roles');

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'موظف عقود']);
    }

    public function test_it_creates_linked_user_when_onboarding_admin_employee()
    {
        $role = Role::create([
            'name' => 'محاسب',
            'company_id' => $this->company->id,
            'allowed_modules' => ['payroll']
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/employees', [
                'name' => 'Admin Emp',
                'date_of_joining' => '2026-07-01',
                'pay_type' => 'fixed',
                'official_salary' => 300,
                'actual_salary' => 350,
                'role_category' => 'admin',
                'admin_role_id' => $role->id,
                'email' => 'adminemp@test.com',
                'password' => 'password123'
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('employees', [
            'name' => 'Admin Emp',
            'role_category' => 'admin',
            'admin_role_id' => $role->id
        ]);

        $employee = Employee::where('name', 'Admin Emp')->first();
        $this->assertNotNull($employee->user_id);

        $this->assertDatabaseHas('users', [
            'id' => $employee->user_id,
            'email' => 'adminemp@test.com',
            'role' => 'محاسب'
        ]);
    }

    public function test_it_validates_operational_advances_and_repayment_limits()
    {
        $employee = Employee::create([
            'name' => 'Test Admin Employee',
            'date_of_joining' => '2026-07-01',
            'pay_type' => 'fixed',
            'official_salary' => 400,
            'actual_salary' => 400,
            'company_id' => $this->company->id,
            'role_category' => 'admin'
        ]);

        // Request as operator -> status should be pending
        $response = $this->actingAs($this->operatorUser)
            ->postJson('/api/operational-advances', [
                'employee_id' => $employee->id,
                'amount' => 500,
                'date' => '2026-07-04',
                'reason' => 'عهدة ميدانية'
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('operational_advances', [
            'employee_id' => $employee->id,
            'amount' => '500.000',
            'status' => 'pending'
        ]);

        $advanceId = $response->json('id');

        // Approve as admin
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/operational-advances/{$advanceId}/approve");

        $response->assertStatus(200);
        $this->assertDatabaseHas('operational_advances', [
            'id' => $advanceId,
            'status' => 'active',
            'approved_by' => $this->adminUser->id
        ]);

        // Register expense exceeding remaining
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/operational-advances/{$advanceId}/expense", [
                'amount' => 600,
                'date' => '2026-07-05',
                'description' => 'شراء وقود'
            ]);
        $response->assertStatus(422);

        // Register valid expense
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/operational-advances/{$advanceId}/expense", [
                'amount' => 300,
                'date' => '2026-07-05',
                'description' => 'شراء وقود'
            ]);
        $response->assertStatus(201);

        // Register returned cash closing the advance
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/operational-advances/{$advanceId}/return", [
                'amount' => 200,
                'date' => '2026-07-06'
            ]);
        $response->assertStatus(201);

        // Advance should be auto-completed
        $this->assertDatabaseHas('operational_advances', [
            'id' => $advanceId,
            'status' => 'completed'
        ]);
    }

    public function test_it_validates_violation_splits_and_audit_override_reason()
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

        $contract = Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-11',
            'name' => 'Delivery Contract',
            'client_name' => 'Keta',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'company_id' => $this->company->id
        ]);

        // Assign vehicle to driver
        VehicleAssignment::create([
            'employee_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'assigned_date' => '2026-07-01',
            'is_active' => true,
            'company_id' => $this->company->id
        ]);

        // Assign driver to contract
        ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $contract->id,
            'start_date' => '2026-07-01',
            'status' => 'active',
            'company_id' => $this->company->id
        ]);

        // 1. Create split violation with mismatching sums -> fail
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/violations', [
                'vehicle_id' => $vehicle->id,
                'violation_date' => '2026-07-04 10:00:00',
                'violation_type' => 'سرعة زائدة',
                'amount' => 100,
                'driver_share' => 30,
                'contract_share' => 50 // sum = 80 != 100
            ]);
        $response->assertStatus(422);

        // 2. Override driver without audit reason -> fail
        $anotherDriver = Employee::create([
            'name' => 'Driver 2',
            'date_of_joining' => '2026-07-01',
            'pay_type' => 'fixed',
            'official_salary' => 120,
            'actual_salary' => 150,
            'company_id' => $this->company->id
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/violations', [
                'vehicle_id' => $vehicle->id,
                'violation_date' => '2026-07-04 10:00:00',
                'violation_type' => 'سرعة زائدة',
                'amount' => 100,
                'driver_share' => 30,
                'contract_share' => 70,
                'employee_id' => $anotherDriver->id // manual override
            ]);
        $response->assertStatus(422);

        // 3. Override driver WITH audit reason -> success
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/violations', [
                'vehicle_id' => $vehicle->id,
                'violation_date' => '2026-07-04 10:00:00',
                'violation_type' => 'سرعة زائدة',
                'amount' => 100,
                'driver_share' => 30,
                'contract_share' => 70,
                'employee_id' => $anotherDriver->id,
                'assignment_override_reason' => 'تصحيح إسناد المخالفة يدوياً'
            ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('violations', [
            'employee_id' => $anotherDriver->id,
            'driver_share' => '30.000',
            'contract_share' => '70.000',
            'is_driver_override' => true,
            'assignment_override_reason' => 'تصحيح إسناد المخالفة يدوياً'
        ]);
    }

    public function test_it_records_client_collections_against_contracts()
    {
        $contract = Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-22',
            'name' => 'Main Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'company_id' => $this->company->id
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/contracts/{$contract->id}/collections", [
                'amount' => 800,
                'date' => '2026-07-04',
                'payment_method' => 'bank_transfer',
                'notes' => 'الدفعة الأولى لشهر يوليو'
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('client_collections', [
            'contract_id' => $contract->id,
            'amount' => '800.000',
            'payment_method' => 'bank_transfer'
        ]);
    }

    public function test_it_records_payroll_slip_disbursements_and_write_offs()
    {
        $employee = Employee::create([
            'name' => 'Driver A',
            'date_of_joining' => '2026-07-01',
            'pay_type' => 'fixed',
            'official_salary' => 120,
            'actual_salary' => 150,
            'company_id' => $this->company->id
        ]);

        $run = PayrollRun::create([
            'year' => 2026,
            'month' => 7,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'company_id' => $this->company->id
        ]);

        $slip = PayrollSlip::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'official_salary' => 120,
            'actual_salary' => 150,
            'total_deductions' => 0,
            'net_salary' => 150,
            'company_id' => $this->company->id
        ]);

        // Disburse payroll slip
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/payroll-slips/{$slip->id}/payments", [
                'amount' => 150,
                'date' => '2026-07-05',
                'type' => 'disbursement',
                'payment_method' => 'bank_transfer'
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('payroll_payments', [
            'payroll_slip_id' => $slip->id,
            'amount' => '150.000',
            'type' => 'disbursement'
        ]);

        $anotherEmployee = Employee::create([
            'name' => 'Driver B',
            'date_of_joining' => '2026-07-01',
            'pay_type' => 'fixed',
            'official_salary' => 120,
            'actual_salary' => 150,
            'company_id' => $this->company->id
        ]);

        // Write off negative carryover (if slip has negative net)
        $negativeSlip = PayrollSlip::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $anotherEmployee->id,
            'official_salary' => 0,
            'actual_salary' => 0,
            'total_deductions' => 50,
            'net_salary' => -50,
            'company_id' => $this->company->id
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/payroll-slips/{$negativeSlip->id}/payments", [
                'amount' => -50,
                'date' => '2026-07-05',
                'type' => 'write_off',
                'audit_reason' => 'إعفاء السائق من الرصيد السالب عند الاستقالة'
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('payroll_payments', [
            'payroll_slip_id' => $negativeSlip->id,
            'amount' => '-50.000',
            'type' => 'write_off',
            'audit_reason' => 'إعفاء السائق من الرصيد السالب عند الاستقالة'
        ]);
    }
}
