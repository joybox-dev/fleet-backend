<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ConsolidatedPayrollRun;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Months are closed in whatever order suits, and some are skipped altogether. A month closed late
 * is not stranded: it collects whatever is still outstanding when it runs, and anything it cannot
 * cover stays on the driver for the next month to be closed — which may be earlier or later on the
 * calendar. What links the months is the order they were approved in, not their dates.
 *
 * Reopening follows from that. Each approval reads the balance the one before it left behind, so
 * only the month approved most recently can be reopened; undoing one underneath it would restore a
 * balance that later approvals have already spent.
 */
class PayrollApprovalOrderTest extends TestCase
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
            'name' => 'Order Test Company',
            'code' => 'ordertest',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Order Admin',
            'email' => 'admin@order.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Order Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Order Driver',
            'employee_number' => 'EMP-ORD-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $vehicle = Vehicle::create([
            'plate_number' => 'V-ORD-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-ORD',
            'name' => 'Order Contract',
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

        // A worked day in each of July, August and September, so any of the three can be closed.
        foreach (['2026-07-02', '2026-08-04', '2026-09-03'] as $date) {
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

        $this->actingAs($this->user);
    }

    /** Close a month end to end: its contract sheet, then the consolidated sheet. */
    private function close(int $month): void
    {
        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", [
            'year' => 2026, 'month' => $month,
        ])->assertOk();

        $this->postJson("/api/payroll/consolidated/2026/{$month}/approve")->assertOk();
    }

    private function runFor(int $month): ?ConsolidatedPayrollRun
    {
        return ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('year', 2026)->where('month', $month)
            ->first();
    }

    public function test_an_earlier_month_can_be_closed_after_a_later_one(): void
    {
        $this->close(8);
        $this->close(7);

        $this->assertNotNull($this->runFor(8));
        $this->assertNotNull($this->runFor(7));
    }

    public function test_a_month_can_be_skipped_entirely(): void
    {
        $this->close(7);
        $this->close(9);

        $this->assertNull($this->runFor(8), 'August was never closed and must stay open');
        $this->assertNotNull($this->runFor(9));

        // And the skipped month is still closeable afterwards.
        $this->close(8);
        $this->assertNotNull($this->runFor(8));
    }

    public function test_the_same_month_still_cannot_be_closed_twice(): void
    {
        $this->close(7);

        $this->postJson('/api/payroll/consolidated/2026/7/approve')
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'معتمد بالفعل'));
    }

    public function test_the_month_approved_most_recently_reopens_even_when_it_is_the_earlier_one(): void
    {
        $this->close(8);
        $this->close(7);

        // July was approved last, so July is what reopens — August's later date is irrelevant.
        $this->postJson('/api/payroll/consolidated/2026/7/unapprove')->assertOk();

        $this->assertNull($this->runFor(7));
        $this->assertNotNull($this->runFor(8));
    }

    public function test_a_month_approved_before_another_cannot_be_reopened_first(): void
    {
        $this->close(8);
        $this->close(7);

        // August was approved first; July has read the balance it left.
        $this->postJson('/api/payroll/consolidated/2026/8/unapprove')
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'اعتُمد بعده'));

        $this->assertNotNull($this->runFor(8), 'the refusal must reverse nothing');
    }

    public function test_reopening_unwinds_in_the_reverse_order_of_approval(): void
    {
        $this->close(7);
        $this->close(9);
        $this->close(8);

        // Approved 7, 9, 8 — so they must come back off 8, 9, 7.
        $this->postJson('/api/payroll/consolidated/2026/7/unapprove')->assertStatus(422);
        $this->postJson('/api/payroll/consolidated/2026/9/unapprove')->assertStatus(422);

        $this->postJson('/api/payroll/consolidated/2026/8/unapprove')->assertOk();
        $this->postJson('/api/payroll/consolidated/2026/9/unapprove')->assertOk();
        $this->postJson('/api/payroll/consolidated/2026/7/unapprove')->assertOk();

        $this->assertSame(0, ConsolidatedPayrollRun::withoutGlobalScopes()->count());
    }

    public function test_another_companys_approvals_do_not_constrain_this_one(): void
    {
        $other = Company::create([
            'name' => 'Unrelated Company',
            'code' => 'unrelated',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        $this->close(7);

        // Approved after ours, but it belongs to someone else.
        ConsolidatedPayrollRun::create([
            'company_id' => $other->id,
            'year' => 2026,
            'month' => 9,
            'status' => 'approved',
            'approved_by' => $this->user->id,
            'approved_at' => now(),
        ]);

        $this->postJson('/api/payroll/consolidated/2026/7/unapprove')->assertOk();
    }
}
