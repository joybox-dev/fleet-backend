<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\DriverOpeningBalance;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\EmployeeLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A driver's account does not have to start at zero.
 *
 * The owner's rule: a driver who came to the system already owing the company («مكسور»), or with
 * money of his still held by it, is entered with that figure — negative when he owes, positive when
 * he is owed. It is the first line of his running account: it changes what a month's payment
 * suggests, and never what a sheet says he earned.
 *
 * Fixture: a fixed 260/26 contract, so 10.000 a day. April is five days' work — a clean 50.000.
 */
class DriverOpeningBalanceTest extends TestCase
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
            'name' => 'Opening Co',
            'code' => 'openco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@opening.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);

        $this->driver = $this->makeDriver('Carried Driver', 'EMP-OPEN-1');

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-OPEN-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-OPEN',
            'name' => 'Opening Contract',
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

        foreach (['2026-04-01', '2026-04-02', '2026-04-05', '2026-04-06', '2026-04-07'] as $date) {
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

        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeDriver(string $name, string $number): Employee
    {
        return Employee::create([
            'name' => $name,
            'employee_number' => $number,
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
            'official_salary' => 100.000,
        ]);
    }

    private function approveApril(): void
    {
        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", ['year' => 2026, 'month' => 4])->assertOk();
        $this->postJson('/api/payroll/consolidated/2026/4/approve')->assertOk();
    }

    /** @return array<string, mixed> */
    private function aprilRow(): array
    {
        $sheet = $this->getJson('/api/payroll/consolidated/2026/4')->assertOk()->json();
        foreach ($sheet['drivers'] as $row) {
            if ((int) $row['employee_id'] === $this->driver->id) {
                return $row + ['_summary' => $sheet['summary']];
            }
        }
        $this->fail('driver missing from the consolidated sheet for April');
    }

    /** @param  array<string, mixed>  $payload */
    private function enter(array $payload, ?Employee $for = null): TestResponse
    {
        return $this->postJson('/api/payroll/opening-balances', $payload + [
            'employee_id' => ($for ?? $this->driver)->id,
            'balance_date' => '2026-04-01',
        ]);
    }

    public function test_a_debt_he_came_with_is_netted_off_the_first_month_and_the_rest_carries(): void
    {
        $this->enter(['amount' => -70, 'notes' => 'دين قديم قبل النظام'])->assertCreated();
        $this->approveApril();

        $april = $this->aprilRow();
        $this->assertSame(50.0, (float) $april['final_net_payout'], 'the sheet still says what he earned');
        $this->assertSame(-70.0, (float) $april['opening_balance']);
        $this->assertSame(-70.0, (float) $april['opening_balance_declared']);
        $this->assertNull($april['opening_balance_from'], 'no approved month stands behind it');
        $this->assertSame(-20.0, (float) $april['amount_due']);
        $this->assertSame(0.0, (float) $april['suggested_disbursement'], 'still in debt after his month: nothing is suggested');
        $this->assertSame('nothing_due', $april['disbursement_status']);
        $this->assertSame(-20.0, (float) $april['remaining_balance'], 'what April could not cover rolls on');

        $this->assertCount(1, $april['opening_balance_breakdown']);
        $line = $april['opening_balance_breakdown'][0];
        $this->assertSame(DriverOpeningBalance::LABEL, $line['label']);
        $this->assertTrue($line['declared']);
        $this->assertNull($line['run_id']);
        $this->assertSame(-70.0, (float) $line['remaining']);

        $this->assertSame(-70.0, (float) $april['_summary']['total_opening_balance']);
        $this->assertSame(1, $april['_summary']['drivers_owing']);
    }

    public function test_money_held_for_him_is_added_to_the_first_month_and_paid_with_it(): void
    {
        $this->enter(['amount' => 40.5])->assertCreated();
        $this->approveApril();

        $april = $this->aprilRow();
        $this->assertSame(40.5, (float) $april['opening_balance']);
        $this->assertSame(90.5, (float) $april['amount_due']);
        $this->assertSame(90.5, (float) $april['suggested_disbursement']);
        $this->assertSame('unpaid', $april['disbursement_status']);

        // The bank's list follows the same suggestion, capped at his registered salary.
        $bank = $this->getJson('/api/payroll/consolidated/2026/4/bank-sheet')->assertOk()->json();
        $this->assertSame(90.5, round((float) ($bank['rows'][0]['amount'] ?? $bank['missing_iban'][0]['amount']), 3));

        $this->postJson('/api/payroll/consolidated/2026/4/disbursements', [
            'employee_id' => $this->driver->id, 'bank_amount' => 90.5, 'paid_at' => '2026-09-08',
        ])->assertCreated();

        $paid = $this->aprilRow();
        $this->assertSame(0.0, (float) $paid['remaining_balance']);
        $this->assertSame('paid', $paid['disbursement_status']);
    }

    public function test_a_figure_entered_after_the_month_was_approved_still_comes_off_that_month(): void
    {
        $this->approveApril();
        $this->assertSame(50.0, (float) $this->aprilRow()['suggested_disbursement']);

        $this->enter(['amount' => -20])->assertCreated();

        $april = $this->aprilRow();
        $this->assertSame(50.0, (float) $april['final_net_payout'], 'an approved month keeps its figures');
        $this->assertSame(-20.0, (float) $april['opening_balance']);
        $this->assertSame(30.0, (float) $april['suggested_disbursement'], 'only what is paid changes');
    }

    public function test_the_statement_opens_with_the_figure_and_lands_on_the_sheet_balance(): void
    {
        $this->enter(['amount' => -20, 'balance_date' => '2026-03-31', 'notes' => 'سلفة قديمة'])->assertCreated();
        $this->approveApril();
        $this->postJson('/api/payroll/consolidated/2026/4/disbursements', [
            'employee_id' => $this->driver->id, 'cash_amount' => 10, 'paid_at' => '2026-09-10',
        ])->assertCreated();

        $history = EmployeeLedgerService::history($this->driver);

        $this->assertSame(-20.0, (float) $history['opening_balance']['amount']);
        $this->assertSame('Admin', $history['opening_balance']['created_by_name']);

        $movements = $history['movements'];
        $this->assertSame(['opening', 'month', 'disbursement'], array_column($movements, 'kind'));
        $this->assertSame('2026-03-31', $movements[0]['date']);
        $this->assertSame([-20.0, 50.0, -10.0], array_map(fn ($m) => (float) $m['amount'], $movements));
        $this->assertSame([-20.0, 30.0, 20.0], array_map(fn ($m) => (float) $m['balance_after'], $movements));

        $april = collect($history['months'])->firstWhere('label', '04/2026');
        $this->assertSame(-20.0, (float) $april['carried_in'], 'the first approved month opens with what he came with');
        $this->assertSame(20.0, (float) $april['closing_balance']);

        $this->assertSame(20.0, (float) $history['settled_balance']);
        $this->assertSame(
            (float) $this->aprilRow()['remaining_balance'],
            (float) $history['settled_balance'],
            'the sheet and the statement must quote one balance'
        );
    }

    public function test_the_profile_quotes_where_the_account_stands_not_what_he_earned(): void
    {
        $this->enter(['amount' => -80])->assertCreated();
        $this->approveApril();

        $balance = $this->getJson("/api/employees/{$this->driver->id}/balance")->assertOk()->json();

        $this->assertSame(50.0, (float) $balance['net_balance'], 'earnings less deductions: he looks in credit');
        $this->assertSame(-30.0, (float) $balance['running_balance'], 'the account: he came owing 80 and April covered 50');

        $this->postJson('/api/payroll/consolidated/2026/4/disbursements', [
            'employee_id' => $this->driver->id, 'cash_amount' => 20, 'paid_at' => '2026-09-08',
        ])->assertCreated();

        $this->assertSame(
            -50.0,
            (float) $this->getJson("/api/employees/{$this->driver->id}/balance")->json('running_balance'),
            'money handed to him deepens what he owes'
        );
    }

    public function test_a_driver_with_a_figure_and_no_month_is_still_seen(): void
    {
        $idle = $this->makeDriver('Idle Debtor', 'EMP-OPEN-2');
        $this->enter(['amount' => -75], $idle)->assertCreated();

        $offSheet = $this->getJson('/api/payroll/consolidated/2026/4')->assertOk()->json('standing_balances_off_sheet');
        $this->assertCount(1, $offSheet);
        $this->assertSame($idle->id, $offSheet[0]['employee_id']);
        $this->assertSame(-75.0, (float) $offSheet[0]['balance']);
        $this->assertSame(-75.0, (float) $offSheet[0]['declared']);
        $this->assertNull($offSheet[0]['from']);

        // No work, no approved month — his statement is the one figure.
        $history = EmployeeLedgerService::history($idle);
        $this->assertSame(-75.0, (float) $history['settled_balance']);
        $this->assertNull($history['settled_through']);
        $this->assertSame(['opening'], array_column($history['movements'], 'kind'));
    }

    public function test_one_figure_per_driver_never_zero_never_dated_ahead(): void
    {
        $this->enter(['amount' => 0])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->enter(['amount' => 'مئة'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->enter(['amount' => -10, 'balance_date' => '2026-09-09'])->assertStatus(422)->assertJsonValidationErrors('balance_date');
        $this->postJson('/api/payroll/opening-balances', ['amount' => -10, 'balance_date' => '2026-04-01'])
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');
        $this->assertSame(0, DriverOpeningBalance::withoutGlobalScopes()->count());

        $this->enter(['amount' => -10.1234])->assertCreated()->assertJsonPath('opening.amount', -10.123);

        $again = $this->enter(['amount' => -10])->assertStatus(422);
        $this->assertStringContainsString('عدِّله', $again->json('message'));
        $this->assertSame(1, DriverOpeningBalance::withoutGlobalScopes()->count());
    }

    public function test_a_figure_can_be_corrected_and_removed(): void
    {
        $id = $this->enter(['amount' => -300])->assertCreated()->json('opening.id');
        $this->approveApril();
        $this->assertSame(0.0, (float) $this->aprilRow()['suggested_disbursement']);

        // A slip of the hand: it was 30, not 300.
        $this->putJson("/api/payroll/opening-balances/{$id}", [
            'amount' => -30, 'balance_date' => '2026-04-01', 'notes' => 'صُحِّح: 30 لا 300',
        ])->assertOk()->assertJsonPath('opening.amount', -30)->assertJsonPath('opening.updated_by_name', 'Admin');
        $this->assertSame(20.0, (float) $this->aprilRow()['suggested_disbursement']);

        // The driver a figure belongs to is not something an edit may move.
        $this->putJson("/api/payroll/opening-balances/{$id}", [
            'employee_id' => $this->driver->id, 'amount' => -30, 'balance_date' => '2026-04-01',
        ])->assertStatus(422)->assertJsonValidationErrors('employee_id');

        $this->deleteJson("/api/payroll/opening-balances/{$id}")->assertOk();
        $april = $this->aprilRow();
        $this->assertSame(0.0, (float) $april['opening_balance']);
        $this->assertSame(50.0, (float) $april['suggested_disbursement']);
        $this->assertSame([], $april['opening_balance_breakdown']);
    }

    public function test_the_list_names_every_driver_and_where_his_account_stands(): void
    {
        $other = $this->makeDriver('No Figure Driver', 'EMP-OPEN-3');
        $staff = Employee::create([
            'name' => 'Office Clerk', 'employee_number' => 'EMP-OPEN-4', 'company_id' => $this->company->id,
            'status' => 'active', 'role_category' => 'admin', 'date_of_joining' => '2026-01-01',
        ]);
        $this->enter(['amount' => -20])->assertCreated();
        $this->approveApril();

        $list = $this->getJson('/api/payroll/opening-balances')->assertOk()->json();

        $byId = collect($list['drivers'])->keyBy('employee_id');
        $this->assertTrue($byId->has($this->driver->id));
        $this->assertTrue($byId->has($other->id), 'a driver with no figure is listed, waiting for one');
        $this->assertFalse($byId->has($staff->id), 'office staff have no running account');

        $this->assertSame(-20.0, (float) $byId[$this->driver->id]['opening']['amount']);
        $this->assertSame(30.0, (float) $byId[$this->driver->id]['current_balance'], 'the figure plus April, still unpaid');
        $this->assertSame('04/2026', $byId[$this->driver->id]['current_through']);
        $this->assertNull($byId[$other->id]['opening']);
        $this->assertSame(0.0, (float) $byId[$other->id]['current_balance']);

        $this->assertSame(1, $list['totals']['declared_count']);
        $this->assertSame(20.0, (float) $list['totals']['owed_by_drivers']);
        $this->assertSame(0.0, (float) $list['totals']['owed_to_drivers']);
        $this->assertSame(30.0, (float) $list['totals']['current_owed_to_drivers']);
        $this->assertSame('2026-04-01', $list['default_date'], 'a new figure is dated before the first approved month');
    }

    public function test_a_figure_belongs_to_one_company(): void
    {
        $otherCompany = Company::create(['name' => 'Other Co', 'code' => 'otherco', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true]);
        $stranger = Employee::forceCreate([
            'name' => 'Stranger', 'employee_number' => 'EMP-X', 'company_id' => $otherCompany->id,
            'status' => 'active', 'role_category' => 'driver', 'date_of_joining' => '2026-01-01',
        ]);
        $theirs = DriverOpeningBalance::forceCreate([
            'company_id' => $otherCompany->id, 'employee_id' => $stranger->id, 'amount' => -500, 'balance_date' => '2026-04-01',
        ]);

        $this->enter(['amount' => -10], $stranger)->assertStatus(422)->assertJsonValidationErrors('employee_id');
        $this->putJson("/api/payroll/opening-balances/{$theirs->id}", ['amount' => -1, 'balance_date' => '2026-04-01'])->assertNotFound();
        $this->deleteJson("/api/payroll/opening-balances/{$theirs->id}")->assertNotFound();

        $list = $this->getJson('/api/payroll/opening-balances')->assertOk()->json();
        $this->assertNotContains($stranger->id, array_column($list['drivers'], 'employee_id'));
        $this->assertSame(0, $list['totals']['declared_count']);
        $this->assertSame(-500.0, (float) $theirs->fresh()->amount);
    }

    public function test_reading_the_list_and_writing_a_figure_are_different_authorities(): void
    {
        $role = Role::create(['name' => 'قارئ الرواتب', 'company_id' => $this->company->id, 'allowed_modules' => ['payroll.view', 'contract_payroll.view']]);
        $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@opening.test', 'password' => bcrypt('password'), 'role' => 'قارئ الرواتب', 'company_id' => $this->company->id]);
        Employee::create(['name' => 'Viewer', 'employee_number' => 'EMP-V', 'company_id' => $this->company->id, 'status' => 'active', 'role_category' => 'admin', 'admin_role_id' => $role->id, 'user_id' => $viewer->id, 'date_of_joining' => '2026-01-01']);

        $this->actingAs($viewer)->getJson('/api/payroll/opening-balances')->assertOk();
        $this->actingAs($viewer);
        $this->enter(['amount' => -10])->assertStatus(403);
        $this->assertSame(0, DriverOpeningBalance::withoutGlobalScopes()->count());

        $accountant = User::create(['name' => 'Accountant', 'email' => 'acc@opening.test', 'password' => bcrypt('password'), 'role' => 'accountant', 'company_id' => $this->company->id]);
        $this->actingAs($accountant);
        $this->enter(['amount' => -10])->assertCreated();
    }
}
