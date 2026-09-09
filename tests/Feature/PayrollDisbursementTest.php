<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\PayrollDisbursement;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\EmployeeLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Paying a month out, and what that does to the driver's balance.
 *
 * The owner's rules: a driver's debt is netted off his pay as a suggestion, not a law — he may be
 * paid part of a month despite it; the bank/cash split applies to whatever amount is actually
 * paid; the payment is a real, dated movement on his account; and a month that was paid cannot be
 * quietly reopened.
 *
 * Fixture: a fixed 260/26 contract, so 10.000 a day. March is two days' work (20.000) with a
 * 50.000 driver-borne expense — a −30.000 month. April is five days (50.000) and clean.
 */
class PayrollDisbursementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Employee $driver;

    private Contract $contract;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-08 10:00:00');

        $this->company = Company::create([
            'name' => 'Disbursement Co',
            'code' => 'disbco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@disb.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Paid Driver',
            'employee_number' => 'EMP-PAY-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-PAY-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-PAY',
            'name' => 'Pay Contract',
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

        $this->workDays(['2026-03-02', '2026-03-03']);
        $this->workDays(['2026-04-01', '2026-04-02', '2026-04-05', '2026-04-06', '2026-04-07']);

        DriverExpense::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'expense_type' => 'إصلاح على حساب السائق',
            'amount' => 50.000,
            'borne_by' => 'driver',
            'driver_amount' => 50.000,
            'expense_date' => '2026-03-09',
            'is_deducted' => 0,
        ]);

        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<int, string>  $dates */
    private function workDays(array $dates): void
    {
        foreach ($dates as $date) {
            DailyLog::create([
                'employee_id' => $this->driver->id,
                'contract_id' => $this->contract->id,
                'vehicle_id' => $this->vehicle->id,
                'log_date' => $date,
                'driver_status' => 'working',
                'orders_count' => 0,
                'company_id' => $this->company->id,
                'created_by' => $this->admin->id,
            ]);
        }
    }

    private function approveContract(int $month): void
    {
        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", [
            'year' => 2026, 'month' => $month,
        ])->assertOk();
    }

    private function approveMonth(int $month): void
    {
        $this->approveContract($month);
        $this->postJson("/api/payroll/consolidated/2026/{$month}/approve")->assertOk();
    }

    /** @return array<string, mixed> */
    private function driverRow(int $month): array
    {
        $sheet = $this->getJson("/api/payroll/consolidated/2026/{$month}")->assertOk()->json();
        foreach ($sheet['drivers'] as $row) {
            if ((int) $row['employee_id'] === $this->driver->id) {
                return $row + ['_summary' => $sheet['summary']];
            }
        }
        $this->fail("driver missing from the consolidated sheet for month {$month}");
    }

    /** @param  array<string, mixed>  $payload */
    private function pay(int $month, array $payload): TestResponse
    {
        return $this->postJson("/api/payroll/consolidated/2026/{$month}/disbursements", $payload + [
            'employee_id' => $this->driver->id,
        ]);
    }

    public function test_the_opening_balance_carries_a_debt_and_the_suggestion_nets_it_off(): void
    {
        $this->approveMonth(3);

        $march = $this->driverRow(3);
        $this->assertSame(-30.0, (float) $march['final_net_payout']);
        $this->assertSame(0.0, (float) $march['opening_balance'], 'first approved month opens at zero');
        $this->assertSame(0.0, (float) $march['suggested_disbursement'], 'a month in debt suggests paying nothing');
        $this->assertSame('nothing_due', $march['disbursement_status']);
        $this->assertSame(-30.0, (float) $march['remaining_balance']);

        // Until a contract sheet for April is approved he has no April row at all — so the debt is
        // listed beside the sheet rather than lost with him.
        $offSheet = $this->getJson('/api/payroll/consolidated/2026/4')->assertOk()->json('standing_balances_off_sheet');
        $this->assertCount(1, $offSheet);
        $this->assertSame($this->driver->id, $offSheet[0]['employee_id']);
        $this->assertSame(-30.0, (float) $offSheet[0]['balance']);

        // Once he is on the draft, the debt is already visible on it — the owner asked to know
        // before paying whether anything is owed, not after.
        $this->approveContract(4);
        $aprilDraft = $this->driverRow(4);
        $this->assertSame(-30.0, (float) $aprilDraft['opening_balance']);
        $this->assertSame('03/2026', $aprilDraft['opening_balance_from']);
        $this->assertSame('not_approved', $aprilDraft['disbursement_status']);
        $this->assertSame([], $this->getJson('/api/payroll/consolidated/2026/4')->json('standing_balances_off_sheet'));

        $this->approveMonth(4);

        $april = $this->driverRow(4);
        $this->assertSame(50.0, (float) $april['final_net_payout']);
        $this->assertSame(-30.0, (float) $april['opening_balance']);
        $this->assertSame(20.0, (float) $april['amount_due'], 'net 50 less the 30 he owes');
        $this->assertSame(20.0, (float) $april['suggested_disbursement']);
        $this->assertSame('unpaid', $april['disbursement_status']);
        $this->assertCount(1, $april['opening_balance_breakdown']);
        $this->assertSame(-30.0, (float) $april['opening_balance_breakdown'][0]['remaining']);

        // The split is on the amount actually paid, in whatever proportion the payer chose.
        $this->pay(4, ['bank_amount' => 15, 'cash_amount' => 5, 'paid_at' => '2026-09-15'])->assertCreated();

        $paid = $this->driverRow(4);
        $this->assertSame(15.0, (float) $paid['disbursed_bank']);
        $this->assertSame(5.0, (float) $paid['disbursed_cash']);
        $this->assertSame(20.0, (float) $paid['disbursed_total']);
        $this->assertSame(0.0, (float) $paid['remaining_balance'], 'paid in full: nothing carries');
        $this->assertSame(0.0, (float) $paid['suggested_disbursement']);
        $this->assertSame('paid', $paid['disbursement_status']);
        $this->assertCount(1, $paid['disbursements']);
        $this->assertSame('2026-09-15', $paid['disbursements'][0]['paid_at']);
        $this->assertSame('Admin', $paid['disbursements'][0]['created_by_name']);

        $this->assertSame(20.0, (float) $paid['_summary']['total_disbursed']);
        $this->assertSame(1, $paid['_summary']['drivers_paid']);
        $this->assertSame(0, $paid['_summary']['drivers_unpaid']);
    }

    public function test_a_payment_despite_a_debt_is_recorded_as_given_and_deepens_the_carry(): void
    {
        $this->approveMonth(3);

        // Suggested nothing — the owner decides to hand him 10 anyway. That is his call to make,
        // and the record must say so rather than refuse it.
        $this->pay(3, ['cash_amount' => 10])->assertCreated();

        $march = $this->driverRow(3);
        $this->assertSame(10.0, (float) $march['disbursed_total']);
        $this->assertSame(-40.0, (float) $march['remaining_balance'], 'the 10 he was given is now owed too');

        $this->approveMonth(4);

        $april = $this->driverRow(4);
        $this->assertSame(-40.0, (float) $april['opening_balance']);
        $this->assertSame(10.0, (float) $april['amount_due']);
        $this->assertSame(10.0, (float) $april['suggested_disbursement']);
    }

    public function test_recording_needs_an_approved_month_and_a_driver_on_it(): void
    {
        $this->pay(4, ['bank_amount' => 10])->assertStatus(422);

        $this->approveMonth(3);

        $this->postJson('/api/payroll/consolidated/2026/3/disbursements', [
            'employee_id' => 999999, 'bank_amount' => 10,
        ])->assertStatus(422);

        $this->pay(3, ['bank_amount' => 0, 'cash_amount' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bank_amount']);

        $this->assertSame(0, PayrollDisbursement::withoutGlobalScopes()->count());
    }

    public function test_a_paid_month_cannot_be_reopened_until_its_payments_are_deleted(): void
    {
        $this->approveMonth(3);
        $id = $this->pay(3, ['cash_amount' => 5])->assertCreated()->json('disbursement.id');

        $this->postJson('/api/payroll/consolidated/2026/3/unapprove')->assertStatus(422);

        $this->deleteJson("/api/payroll/disbursements/{$id}")->assertOk();
        $this->postJson('/api/payroll/consolidated/2026/3/unapprove')->assertOk();
    }

    public function test_a_recorded_payment_can_be_corrected(): void
    {
        $this->approveMonth(4);
        $id = $this->pay(4, ['bank_amount' => 50])->assertCreated()->json('disbursement.id');

        $this->putJson("/api/payroll/disbursements/{$id}", [
            'bank_amount' => 30, 'cash_amount' => 20, 'paid_at' => '2026-09-20', 'notes' => 'جزء نقدي',
        ])->assertOk();

        $row = $this->driverRow(4);
        $this->assertSame(30.0, (float) $row['disbursed_bank']);
        $this->assertSame(20.0, (float) $row['disbursed_cash']);
        $this->assertSame('جزء نقدي', $row['disbursements'][0]['notes']);
    }

    public function test_the_statement_lists_movements_by_date_and_lands_on_the_sheet_balance(): void
    {
        $this->approveMonth(3);
        Carbon::setTestNow('2026-09-09 10:00:00');
        $this->approveMonth(4);
        $this->pay(4, ['bank_amount' => 20, 'paid_at' => '2026-09-15'])->assertCreated();

        $history = EmployeeLedgerService::history($this->driver);

        $movements = $history['movements'];
        $this->assertSame(['month', 'month', 'disbursement'], array_column($movements, 'kind'));
        $this->assertSame(['2026-09-08', '2026-09-09', '2026-09-15'], array_column($movements, 'date'));
        $this->assertSame([-30.0, 50.0, -20.0], array_map(fn ($m) => (float) $m['amount'], $movements));
        $this->assertSame([-30.0, 20.0, 0.0], array_map(fn ($m) => (float) $m['balance_after'], $movements));

        $this->assertSame(0.0, (float) $history['settled_balance']);
        $this->assertSame('04/2026', $history['settled_through']);

        $april = collect($history['months'])->firstWhere('label', '04/2026');
        $this->assertSame(20.0, (float) $april['disbursed_total']);
        $this->assertSame(-30.0, (float) $april['carried_in']);
        $this->assertSame(0.0, (float) $april['closing_balance'], 'net 50 − 20 paid, on top of the −30 carried in');

        $this->assertSame(
            (float) $this->driverRow(4)['remaining_balance'],
            (float) $history['settled_balance'],
            'the sheet and the statement must quote one balance'
        );
    }

    public function test_recording_a_payment_needs_the_payroll_edit_permission(): void
    {
        $this->approveMonth(3);

        $role = Role::create([
            'name' => 'مراقب',
            'company_id' => $this->company->id,
            'allowed_modules' => ['employees'],
        ]);
        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@disb.test',
            'password' => bcrypt('password'),
            'role' => 'مراقب',
            'company_id' => $this->company->id,
        ]);
        Employee::create([
            'name' => 'Viewer',
            'employee_number' => 'EMP-VIEW',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'admin',
            'admin_role_id' => $role->id,
            'user_id' => $viewer->id,
            'date_of_joining' => '2026-01-01',
        ]);

        $this->actingAs($viewer);
        $this->pay(3, ['cash_amount' => 5])->assertStatus(403);
        $this->assertSame(0, PayrollDisbursement::withoutGlobalScopes()->count());

        $accountant = User::create([
            'name' => 'Accountant',
            'email' => 'acc@disb.test',
            'password' => bcrypt('password'),
            'role' => 'accountant',
            'company_id' => $this->company->id,
        ]);
        $this->actingAs($accountant);
        $this->pay(3, ['cash_amount' => 5])->assertCreated();
    }
}
