<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCollection;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\DriverExpense;
use App\Models\DriverOpeningBalance;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ContractProfitabilityService;
use App\Services\OperationalFundService;
use App\Services\PayrollBalanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The general statement of account: every account it prints must close on the balance the rest of
 * the system already keeps — the driver's on the payroll balance, the custody holder's on his float,
 * the client's on the month's bills less what was collected — and a period cut anywhere must open
 * on the balance standing the day before.
 *
 * Fixture: a fixed 260/26 contract, 10.000 a day. March is two days (20.000) with a 50.000
 * driver-borne expense — a −30.000 month. April is five days (50.000) and clean. Five orders a day,
 * so the client's fixed 500 has a vehicle to bill.
 */
class StatementOfAccountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Employee $driver;

    private Client $client;

    private Contract $contract;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-08 10:00:00');

        $this->company = Company::create([
            'name' => 'Statement Co',
            'code' => 'stmtco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@stmt.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Statement Driver',
            'employee_number' => 'EMP-STMT-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
            'official_salary' => 100.000,
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-STMT-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->contract = Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-STMT',
            'name' => 'Statement Contract',
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

        foreach (['2026-03-02', '2026-03-03', '2026-04-01', '2026-04-02', '2026-04-05', '2026-04-06', '2026-04-07'] as $date) {
            DailyLog::create([
                'employee_id' => $this->driver->id,
                'contract_id' => $this->contract->id,
                'vehicle_id' => $this->vehicle->id,
                'log_date' => $date,
                'driver_status' => 'working',
                'orders_count' => 5,
                'company_id' => $this->company->id,
                'created_by' => $this->admin->id,
            ]);
        }

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

    private function approveMonth(int $month): void
    {
        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", ['year' => 2026, 'month' => $month])->assertOk();
        $this->postJson("/api/payroll/consolidated/2026/{$month}/approve")->assertOk();
    }

    public function test_the_pay_statement_closes_on_the_payroll_balance_and_cuts_cleanly(): void
    {
        DriverOpeningBalance::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'amount' => -12.5,
            'balance_date' => '2026-02-28',
        ]);
        $this->approveMonth(3);
        $this->approveMonth(4);
        $this->postJson('/api/payroll/consolidated/2026/4/disbursements', [
            'employee_id' => $this->driver->id, 'bank_amount' => 5, 'cash_amount' => 2.5, 'paid_at' => '2026-09-15',
        ])->assertCreated();

        $statement = $this->getJson("/api/statements/pay/{$this->driver->id}")->assertOk()->json();

        $balance = PayrollBalanceService::openingBalances($this->company->id)[$this->driver->id]['balance'];
        $this->assertSame(0.0, round($balance, 3), '−12.5 opening − 30 March + 50 April − 7.5 paid');
        $this->assertSame($balance, (float) $statement['closing']['amount']);

        $lines = collect($statement['lines']);
        $this->assertSame(['opening', 'earning', 'deduction', 'earning', 'payment'], $lines->pluck('kind')->all());
        $this->assertSame(12.5, (float) $lines[0]['debit']);
        $this->assertSame(20.0, (float) $lines[1]['credit']);
        $this->assertSame(50.0, (float) $lines[2]['debit']);
        $this->assertSame(-42.5, (float) $lines[2]['balance']);
        $this->assertSame('عليه', $lines[2]['side']);
        $this->assertSame('2026-09-15', $lines[4]['date']);
        $this->assertSame(7.5, (float) $lines[4]['debit']);
        $this->assertSame(70.0, (float) $statement['totals']['debit']);
        $this->assertSame(70.0, (float) $statement['totals']['credit']);

        // From April on: March and the opening fold into the brought-forward figure.
        $april = $this->getJson("/api/statements/pay/{$this->driver->id}?from=2026-04-01")->assertOk()->json();
        $this->assertSame(-42.5, (float) $april['opening']['amount']);
        $this->assertSame('عليه', $april['opening']['side']);
        $this->assertSame(['earning', 'payment'], collect($april['lines'])->pluck('kind')->all());
        $this->assertSame(0.0, (float) $april['closing']['amount']);

        // Up to the end of April: the September payment is left out.
        $upToApril = $this->getJson("/api/statements/pay/{$this->driver->id}?to=2026-04-30")->assertOk()->json();
        $this->assertSame(7.5, (float) $upToApril['closing']['amount']);
        $this->assertSame('له', $upToApril['closing']['side']);

        $this->getJson("/api/statements/pay/{$this->driver->id}?from=2026-05-01&to=2026-04-01")->assertStatus(422);
    }

    public function test_the_client_statement_is_the_months_bills_less_collections(): void
    {
        ClientCollection::create(['company_id' => $this->company->id, 'contract_id' => $this->contract->id, 'amount' => 300, 'date' => '2026-04-10', 'payment_method' => 'bank', 'notes' => 'دفعة']);

        $statement = $this->getJson("/api/statements/client/{$this->client->id}")->assertOk()->json();

        $march = collect(ContractProfitabilityService::forCompanyMonth($this->company->id, 2026, 3))->sum('revenue');
        $april = collect(ContractProfitabilityService::forCompanyMonth($this->company->id, 2026, 4))->sum('revenue');
        $this->assertGreaterThan(0, $march);

        $lines = collect($statement['lines']);
        $this->assertSame(['invoice', 'collection', 'invoice'], $lines->pluck('kind')->all(), 'by date: March bill, April 10 collection, April bill');
        $this->assertSame(round($march, 3), (float) $lines[0]['debit']);
        $this->assertSame(300.0, (float) $lines[1]['credit']);
        $this->assertSame(round($april, 3), (float) $lines[2]['debit']);
        $this->assertSame(round($march + $april - 300, 3), (float) $statement['closing']['amount']);
        $this->assertSame('عليه', $statement['closing']['side']);
    }

    public function test_the_custody_statement_closes_on_the_float_and_stays_private(): void
    {
        Role::create(['name' => 'أمين الصندوق', 'company_id' => $this->company->id, 'allowed_modules' => ['op_advances.view', 'op_advances.fund']]);
        Role::create(['name' => 'مستلم', 'company_id' => $this->company->id, 'allowed_modules' => ['op_advances.view', 'op_advances.create']]);
        $custodian = $this->login('custodian', 'أمين الصندوق');
        [$giverLogin, $giver] = $this->adminEmployee('giver');
        [$receiverLogin, $receiver] = $this->adminEmployee('receiver');

        $this->actingAs($custodian)->postJson('/api/operational-advances/balances', ['employee_id' => $giver->id, 'kind' => 'add', 'amount' => 1000, 'date' => '2026-09-01', 'notes' => 'دفعة'])->assertCreated();
        $advanceId = $this->actingAs($giverLogin)->postJson('/api/operational-advances', ['employee_id' => $receiver->id, 'amount' => 400, 'date' => '2026-09-02', 'reason' => 'عهدة ميدانية'])->assertCreated()->json('id');
        $this->actingAs($giverLogin)->postJson("/api/operational-advances/{$advanceId}/expense", ['amount' => 100, 'date' => '2026-09-03', 'description' => 'وقود'])->assertCreated();
        $this->actingAs($giverLogin)->postJson("/api/operational-advances/{$advanceId}/return", ['amount' => 50, 'date' => '2026-09-04'])->assertCreated();

        $giverStatement = $this->actingAs($custodian)->getJson("/api/statements/custody/{$giver->id}")->assertOk()->json();
        $available = OperationalFundService::balanceFor($this->company->id, $giver->id)['available'];
        $this->assertSame(650.0, $available);
        $this->assertSame($available, (float) $giverStatement['closing']['amount']);
        $this->assertSame('عليه', $giverStatement['closing']['side'], 'he holds the company\'s cash');

        $receiverStatement = $this->actingAs($receiverLogin)->getJson("/api/statements/custody/{$receiver->id}")->assertOk()->json();
        $this->assertSame(['custody', 'custody_expense', 'custody_return'], collect($receiverStatement['lines'])->pluck('kind')->all());
        $this->assertSame(250.0, (float) $receiverStatement['closing']['amount']);

        // Without the «إعطاء رصيد» permission one sees one's own custody only.
        $this->actingAs($receiverLogin)->getJson("/api/statements/custody/{$giver->id}")->assertForbidden();
        $parties = $this->actingAs($receiverLogin)->getJson('/api/statements/parties')->assertOk()->json();
        $this->assertSame([$receiver->id], array_column($parties['custody'], 'id'));
        $this->assertSame([], $parties['pay']);
        $this->assertSame([], $parties['client']);
        $this->actingAs($receiverLogin)->getJson("/api/statements/pay/{$this->driver->id}")->assertForbidden();
        $this->actingAs($receiverLogin)->getJson("/api/statements/client/{$this->client->id}")->assertForbidden();

        $all = $this->actingAs($custodian)->getJson('/api/statements/parties')->assertOk()->json('custody');
        $this->assertEqualsCanonicalizing([$giver->id, $receiver->id], array_column($all, 'id'));
    }

    private function login(string $handle, string $role): User
    {
        return User::create([
            'name' => ucfirst($handle),
            'email' => "{$handle}@stmt.test",
            'password' => bcrypt('password'),
            'role' => $role,
            'company_id' => $this->company->id,
        ]);
    }

    /** @return array{0: User, 1: Employee} */
    private function adminEmployee(string $handle): array
    {
        $user = $this->login($handle, 'مستلم');
        $employee = Employee::create([
            'name' => ucfirst($handle).' Admin',
            'date_of_joining' => '2026-01-01',
            'pay_type' => 'fixed',
            'official_salary' => 300,
            'actual_salary' => 300,
            'company_id' => $this->company->id,
            'role_category' => 'admin',
            'user_id' => $user->id,
        ]);

        return [$user, $employee];
    }
}
