<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractPayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Approving freezes a month's pay and unapproving destroys that frozen record, so both need
 * the approve authority explicitly — not merely the ability to reach the route.
 */
class PayrollApprovalPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Permissions Co',
            'code' => 'permco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $client = Client::create(['name' => 'C', 'company_id' => $this->company->id]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-PERM',
            'name' => 'Permissions Contract',
            'payment_type' => 'fixed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'status' => 'active',
        ]);
    }

    /**
     * An accountant whose approve permission has been revoked in role management.
     */
    private function accountantWithout(array $revoked): User
    {
        $permissions = [];
        foreach (['view', 'create', 'edit', 'approve', 'delete'] as $action) {
            $permissions["contract_payroll.{$action}"] = ! in_array($action, $revoked, true);
        }
        // Deny the blanket payroll.edit escape hatch too, otherwise it masks the gate.
        $permissions['payroll.edit'] = false;

        return User::create([
            'name' => 'Restricted Accountant',
            'email' => 'restricted'.uniqid().'@perm.test',
            'password' => bcrypt('password'),
            'role' => 'accountant',
            'company_id' => $this->company->id,
            'is_active' => true,
            'permissions' => $permissions,
        ]);
    }

    public function test_approving_a_contract_sheet_requires_the_approve_permission(): void
    {
        $user = $this->accountantWithout(['approve']);

        $this->actingAs($user)
            ->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", ['year' => 2026, 'month' => 3])
            ->assertStatus(403);
    }

    /**
     * The gap this test exists for: unapproving had no permission check at all, so a user with
     * approve revoked could still delete an approved month's frozen snapshot.
     */
    public function test_unapproving_a_contract_sheet_requires_the_approve_permission(): void
    {
        $run = ContractPayrollRun::create([
            'company_id' => $this->company->id,
            'contract_id' => $this->contract->id,
            'year' => 2026,
            'month' => 3,
            'status' => 'approved',
        ]);

        $user = $this->accountantWithout(['approve']);

        $this->actingAs($user)
            ->postJson("/api/payroll/contract-sheet/{$this->contract->id}/unapprove", ['year' => 2026, 'month' => 3])
            ->assertStatus(403);

        // The frozen run must survive the refused request.
        $this->assertDatabaseHas('contract_payroll_runs', ['id' => $run->id]);
    }

    /**
     * Editing a sheet and signing it off are different authorities.
     */
    public function test_edit_permission_alone_does_not_grant_approval(): void
    {
        $user = $this->accountantWithout(['approve']);
        $this->assertTrue($user->can('contract_payroll.edit'), 'edit is still granted');

        $this->actingAs($user)
            ->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", ['year' => 2026, 'month' => 3])
            ->assertStatus(403);
    }

    public function test_consolidated_approve_and_unapprove_require_the_approve_permission(): void
    {
        $user = $this->accountantWithout(['approve']);

        $this->actingAs($user)->postJson('/api/payroll/consolidated/2026/3/approve')->assertStatus(403);
        $this->actingAs($user)->postJson('/api/payroll/consolidated/2026/3/unapprove')->assertStatus(403);
    }

    public function test_an_accountant_with_the_permission_is_allowed_through_the_gate(): void
    {
        $user = $this->accountantWithout([]);

        // Not 403: the request reaches the handler. It may still fail on data grounds.
        $response = $this->actingAs($user)
            ->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", ['year' => 2026, 'month' => 3]);

        $this->assertNotSame(403, $response->status(), 'permission holder must pass the gate');
    }
}
