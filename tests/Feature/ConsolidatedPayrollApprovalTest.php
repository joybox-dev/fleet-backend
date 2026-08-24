<?php

namespace Tests\Feature;

use App\Models\AdvanceDeduction;
use App\Models\Client;
use App\Models\Company;
use App\Models\ConsolidatedPayrollRun;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Company-level deductions (traffic fines, salary-advance instalments) must only reduce a
 * driver's pay once the consolidated month has been approved. Before approval the sheet is
 * a projection and nothing is committed.
 */
class ConsolidatedPayrollApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Approval Test Company',
            'code' => 'apprtest',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@approval.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Approval Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Approval Driver',
            'employee_number' => 'EMP-APR-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $vehicle = Vehicle::create([
            'plate_number' => 'V-APR-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-APR',
            'name' => 'Approval Contract',
            'payment_type' => 'fixed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed',
            'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);

        ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        // Two worked days => 20.000 KWD gross (260 / 26 = 10.000 per day).
        foreach (['2026-03-02', '2026-03-03'] as $date) {
            DailyLog::create([
                'employee_id' => $this->driver->id,
                'contract_id' => $this->contract->id,
                'vehicle_id' => $vehicle->id,
                'log_date' => $date,
                'driver_status' => 'working',
                'orders_count' => 0,
                'company_id' => $this->company->id,
                'created_by' => $this->user->id,
            ]);
        }

        Violation::create([
            'employee_id' => $this->driver->id,
            'vehicle_id' => $vehicle->id,
            'violation_date' => '2026-03-10',
            'violation_type' => 'Speeding',
            'amount' => 6.000,
            'driver_deduction' => 6.000,
            'driver_share' => 6.000,
            'contract_share' => 0.000,
            'is_driver_liable' => true,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);

        SalaryAdvance::create([
            'employee_id' => $this->driver->id,
            'company_id' => $this->company->id,
            'amount' => 9.000,
            'monthly_installment' => 4.000,
            'total_installments' => 3,
            'paid_installments' => 0,
            'remaining_balance' => 9.000,
            'advance_date' => '2026-03-01',
            'status' => 'active',
            'approved_by' => $this->user->id,
        ]);

        $this->actingAs($this->user);
        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", [
            'year' => 2026, 'month' => 3,
        ])->assertOk();
    }

    private function sheet(): array
    {
        return $this->getJson('/api/payroll/consolidated/2026/3')->assertOk()->json();
    }

    private function driverRow(array $sheet): array
    {
        foreach ($sheet['drivers'] as $row) {
            if ((int) $row['employee_id'] === $this->driver->id) {
                return $row;
            }
        }
        $this->fail('driver missing from consolidated sheet');
    }

    public function test_deductions_are_pending_and_not_applied_before_approval(): void
    {
        $sheet = $this->sheet();
        $row = $this->driverRow($sheet);

        $this->assertFalse($sheet['is_approved']);
        $this->assertSame(20.0, (float) $row['gross_contract_earnings']);

        // Reported, so the accountant can see the effect...
        $this->assertSame(6.0, (float) $row['pending_violations_deduction']);
        $this->assertSame(4.0, (float) $row['pending_advances_deduction']);

        // ...but not taken off the payable net.
        $this->assertSame(0.0, (float) $row['violations_deduction']);
        $this->assertSame(0.0, (float) $row['advances_deduction']);
        $this->assertSame(20.0, (float) $row['final_net_payout']);

        // And nothing was written.
        $this->assertDatabaseHas('salary_advances', [
            'employee_id' => $this->driver->id,
            'remaining_balance' => 9.000,
            'paid_installments' => 0,
            'status' => 'active',
        ]);
        $this->assertSame(0, AdvanceDeduction::count());
        $this->assertDatabaseHas('violations', ['employee_id' => $this->driver->id, 'is_deducted' => false]);
    }

    public function test_approval_applies_and_commits_both_deductions(): void
    {
        $this->postJson('/api/payroll/consolidated/2026/3/approve', ['notes' => 'اعتماد مارس'])
            ->assertOk();

        $sheet = $this->sheet();
        $row = $this->driverRow($sheet);

        $this->assertTrue($sheet['is_approved']);
        $this->assertSame(6.0, (float) $row['violations_deduction']);
        $this->assertSame(4.0, (float) $row['advances_deduction']);
        $this->assertSame(10.0, (float) $row['final_net_payout'], '20 − 6 − 4');

        // The advance was actually paid down — the defect that made it deduct forever.
        $this->assertDatabaseHas('salary_advances', [
            'employee_id' => $this->driver->id,
            'remaining_balance' => 5.000,
            'paid_installments' => 1,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('advance_deductions', [
            'salary_advance_id' => SalaryAdvance::first()->id,
            'amount' => 4.000,
            'payroll_slip_id' => null,
        ]);
        $this->assertDatabaseHas('violations', ['employee_id' => $this->driver->id, 'is_deducted' => true]);
    }

    public function test_unapproval_reverses_every_committed_deduction(): void
    {
        $this->postJson('/api/payroll/consolidated/2026/3/approve')->assertOk();
        $this->postJson('/api/payroll/consolidated/2026/3/unapprove')->assertOk();

        $sheet = $this->sheet();
        $row = $this->driverRow($sheet);

        $this->assertFalse($sheet['is_approved']);
        $this->assertSame(20.0, (float) $row['final_net_payout']);

        $this->assertDatabaseHas('salary_advances', [
            'employee_id' => $this->driver->id,
            'remaining_balance' => 9.000,
            'paid_installments' => 0,
            'status' => 'active',
        ]);
        $this->assertSame(0, AdvanceDeduction::count());
        $this->assertDatabaseHas('violations', ['employee_id' => $this->driver->id, 'is_deducted' => false]);
        $this->assertSame(0, ConsolidatedPayrollRun::count());
    }

    public function test_approving_twice_is_refused_so_deductions_cannot_double(): void
    {
        $this->postJson('/api/payroll/consolidated/2026/3/approve')->assertOk();
        $this->postJson('/api/payroll/consolidated/2026/3/approve')->assertStatus(422);

        $this->assertSame(1, AdvanceDeduction::count());
        $this->assertDatabaseHas('salary_advances', [
            'employee_id' => $this->driver->id,
            'remaining_balance' => 5.000,
            'paid_installments' => 1,
        ]);
    }

    public function test_final_instalment_completes_the_advance(): void
    {
        // 4 + 4 + 1 = the full 9.000 principal across March, April and May.
        foreach ([3 => 4.0, 4 => 4.0, 5 => 1.0] as $month => $expected) {
            if ($month > 3) {
                $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", [
                    'year' => 2026, 'month' => $month,
                ])->assertOk();
            }

            $this->postJson("/api/payroll/consolidated/2026/{$month}/approve")->assertOk();

            $advance = SalaryAdvance::withoutGlobalScopes()->first();
            $this->assertSame(
                $expected,
                (float) AdvanceDeduction::orderByDesc('id')->first()->amount,
                "unexpected instalment in month {$month}"
            );
            $this->assertGreaterThanOrEqual(0, (float) $advance->remaining_balance);
        }

        $advance = SalaryAdvance::withoutGlobalScopes()->first();
        $this->assertSame('completed', $advance->status);
        $this->assertSame(0.0, (float) $advance->remaining_balance);
    }
}
