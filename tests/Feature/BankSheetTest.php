<?php

namespace Tests\Feature;

use App\Helpers\Iban;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An employee's IBAN, and the list handed to the bank for an approved month.
 *
 * Fixture: a fixed 260/26 contract — 10.000 a day — and four drivers who each worked twelve days
 * of April, so each is owed 120.000:
 *   Ahmad   registered salary 100, a valid NBK IBAN          → 100.000 by bank, 20.000 cash
 *   Bilal   registered salary 150, a valid KFH IBAN          → all 120.000 by bank
 *   Careem  registered salary 100, no IBAN                   → named apart, 100.000 waiting
 *   Dawood  no registered salary at all                      → cash only, not a bank matter
 */
class BankSheetTest extends TestCase
{
    use RefreshDatabase;

    /** Real check digits: both pass mod-97, and their bank codes are NBK's and KFH's. */
    private const NBK_IBAN = 'KW74NBOK0000000000001000372151';

    private const KFH_IBAN = 'KW52KFHO0000000000001234567890';

    private Company $company;

    private User $admin;

    private Contract $contract;

    private Vehicle $vehicle;

    /** @var array<string, Employee> */
    private array $drivers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-03 10:00:00');

        $this->company = Company::create(['name' => 'Bank Co', 'name_ar' => 'شركة البنك', 'code' => 'bankco', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@bank.test', 'password' => bcrypt('password'), 'role' => 'admin', 'company_id' => $this->company->id, 'is_active' => true]);

        $client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);
        $this->vehicle = Vehicle::create(['plate_number' => 'V-BANK', 'make' => 'Toyota', 'status' => 'working', 'company_id' => $this->company->id, 'vehicle_type_id' => 1]);
        $this->contract = Contract::create([
            'client_id' => $client->id, 'contract_number' => 'CON-BANK', 'name' => 'Bank Contract', 'payment_type' => 'fixed',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'client_payment_method' => 'fixed', 'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id, 'currency' => 'KWD', 'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);

        foreach ([
            'ahmad' => ['Ahmad', 100, self::NBK_IBAN, null],
            'bilal' => ['Bilal', 150, 'kw52 kfho 0000 0000 0000 1234 5678 90', 'KFH — فرع الفروانية'],
            'careem' => ['Careem', 100, null, null],
            'dawood' => ['Dawood', 0, null, null],
        ] as $key => [$name, $salary, $iban, $bank]) {
            $this->drivers[$key] = Employee::create([
                'name' => $name, 'employee_number' => 'EMP-'.strtoupper($key), 'company_id' => $this->company->id, 'status' => 'active',
                'role_category' => 'driver', 'date_of_joining' => '2026-01-01', 'actual_salary' => 0, 'official_salary' => $salary,
                'civil_id' => '2900101000'.strlen($key).ord($key[0]), 'iban' => $iban, 'bank_name' => $bank,
            ]);
            ContractAssignment::create(['employee_id' => $this->drivers[$key]->id, 'contract_id' => $this->contract->id, 'start_date' => '2026-01-01', 'status' => 'active', 'company_id' => $this->company->id]);
            foreach (range(1, 12) as $day) {
                DailyLog::create([
                    'employee_id' => $this->drivers[$key]->id, 'contract_id' => $this->contract->id, 'vehicle_id' => $this->vehicle->id,
                    'log_date' => sprintf('2026-04-%02d', $day), 'driver_status' => 'working', 'orders_count' => 0,
                    'company_id' => $this->company->id, 'created_by' => $this->admin->id,
                ]);
            }
        }

        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function approveApril(): void
    {
        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", ['year' => 2026, 'month' => 4])->assertOk();
        $this->postJson('/api/payroll/consolidated/2026/4/approve')->assertOk();
    }

    public function test_an_iban_checks_itself(): void
    {
        $this->assertTrue(Iban::isValid(self::NBK_IBAN));
        $this->assertTrue(Iban::isValid('KW81CBKU0000000000001234560101'), 'the registry\'s own example');
        $this->assertTrue(Iban::isValid(' kw81 cbku 0000-0000-0000 1234 5601 01 '), 'as a bank letter prints it');
        $this->assertTrue(Iban::isValid('GB82WEST12345698765432'));

        $this->assertFalse(Iban::isValid('KW81CBKU0000000000001234560102'), 'one digit off');
        $this->assertFalse(Iban::isValid('KW81CBKU000000000000123456010'), 'a Kuwaiti IBAN is 30 characters');
        $this->assertFalse(Iban::isValid('1234567890'));
        $this->assertFalse(Iban::isValid(''));

        $this->assertSame('KW81CBKU0000000000001234560101', Iban::normalize(' kw81 cbku 0000-0000-0000 1234 5601 01 '));
        $this->assertSame('KW81 CBKU 0000 0000 0000 1234 5601 01', Iban::format('KW81CBKU0000000000001234560101'));
        $this->assertSame('بنك الكويت الوطني', Iban::bankName(self::NBK_IBAN));
        $this->assertSame('بيت التمويل الكويتي', Iban::bankName(self::KFH_IBAN));
        $this->assertNull(Iban::bankName('GB82WEST12345698765432'), 'only a Kuwaiti IBAN names a bank we know');
    }

    public function test_the_employee_form_stores_one_form_of_an_iban_and_reads_the_bank_off_it(): void
    {
        $careem = $this->drivers['careem'];

        // Pasted the way a bank prints it; the bank left blank.
        $this->putJson("/api/employees/{$careem->id}", ['iban' => 'kw74 nbok 0000 0000 0000 1000 3721 51'])
            ->assertStatus(422)->assertJsonValidationErrors(['iban']);   // Ahmad already holds it

        $this->putJson("/api/employees/{$careem->id}", ['iban' => 'KW81 CBKU 0000 0000 0000 1234 5601 02'])
            ->assertStatus(422)->assertJsonValidationErrors(['iban']);   // check digits do not agree

        $this->putJson("/api/employees/{$careem->id}", ['iban' => 'kw81 cbku 0000 0000 0000 1234 5601 01', 'bank_name' => 'بنك الكويت المركزي'])->assertOk();
        $this->assertSame('KW81CBKU0000000000001234560101', $careem->fresh()->iban);
        $this->assertSame('بنك الكويت المركزي', $careem->fresh()->bank_name, 'a typed bank name is kept');

        // The bank is read off a Kuwaiti IBAN when nobody typed it.
        $dawood = $this->drivers['dawood'];
        $this->putJson("/api/employees/{$dawood->id}", ['iban' => 'KW16 BBYN 0000 0000 0000 0000 0012 34'])->assertOk();
        $this->assertSame('KW16BBYN0000000000000000001234', $dawood->fresh()->iban);
        $this->assertSame('بنك بوبيان', $dawood->fresh()->bank_name);

        // An IBAN can be taken off again, and a blank is no IBAN rather than an empty string.
        $this->putJson("/api/employees/{$careem->id}", ['iban' => ''])->assertOk();
        $this->assertNull($careem->fresh()->iban);

        $ahmad = $this->drivers['ahmad'];
        $this->putJson("/api/employees/{$ahmad->id}", ['iban' => self::NBK_IBAN])->assertOk();   // his own IBAN is not a duplicate of itself
        $this->assertSame('بنك الكويت الوطني', $ahmad->fresh()->bank_name);
    }

    /**
     * What the payment form would propose for each man's bank side, to the fils.
     */
    public function test_the_sheet_proposes_what_the_payment_form_proposes(): void
    {
        $this->approveApril();

        $sheet = $this->getJson('/api/payroll/consolidated/2026/4/bank-sheet')->assertOk()->json();

        $this->assertSame('04/2026', $sheet['period']['label']);
        $this->assertSame('شركة البنك', $sheet['company_name']);
        $this->assertSame(['Ahmad', 'Bilal'], array_column($sheet['rows'], 'name'));

        [$ahmad, $bilal] = $sheet['rows'];
        $this->assertSame(100.0, (float) $ahmad['amount'], 'the bank takes no more than his registered salary');
        $this->assertSame(20.0, (float) $ahmad['cash_part']);
        $this->assertSame(self::NBK_IBAN, $ahmad['iban']);
        $this->assertSame('KW74 NBOK 0000 0000 0000 1000 3721 51', $ahmad['iban_formatted']);
        $this->assertSame('بنك الكويت الوطني', $ahmad['bank_name'], 'read off the IBAN');
        $this->assertFalse($ahmad['duplicate_iban']);

        $this->assertSame(120.0, (float) $bilal['amount'], 'all he is owed fits under his salary');
        $this->assertSame(0.0, (float) $bilal['cash_part']);
        $this->assertSame(self::KFH_IBAN, $bilal['iban'], 'stored without the spaces it was typed with');
        $this->assertSame('KFH — فرع الفروانية', $bilal['bank_name'], 'the name on file wins');

        // No IBAN: he cannot be on a bank's list, and is not lost either.
        $this->assertSame(['Careem'], array_column($sheet['missing_iban'], 'name'));
        $this->assertSame(100.0, (float) $sheet['missing_iban'][0]['amount']);
        $this->assertSame('لا IBAN في ملفه', $sheet['missing_iban'][0]['problem']);

        // JSON carries 220.000 as 220, so the figures are compared as numbers.
        $this->assertEquals(
            ['count' => 2, 'amount' => 220.0, 'cash_alongside' => 20.0, 'missing_count' => 1, 'missing_amount' => 100.0, 'duplicate_ibans' => 0],
            $sheet['totals']
        );
        $this->assertSame(1, $sheet['left_out']['no_bank_salary'], 'Dawood is paid in cash and is no business of the bank\'s');

        // The form's own figures for the same two men.
        $drivers = collect($this->getJson('/api/payroll/consolidated/2026/4')->json('drivers'))->keyBy('employee_name');
        $this->assertSame((float) $ahmad['amount'], min((float) $drivers['Ahmad']['suggested_disbursement'], (float) $drivers['Ahmad']['bank_transferable']));
    }

    public function test_what_was_already_paid_comes_off_the_sheet(): void
    {
        $this->approveApril();

        // Ahmad's bank side was sent in two parts; Bilal was paid in full, in cash.
        $this->postJson('/api/payroll/consolidated/2026/4/disbursements', ['employee_id' => $this->drivers['ahmad']->id, 'bank_amount' => 60, 'cash_amount' => 0, 'paid_at' => '2026-05-01'])->assertCreated();
        $this->postJson('/api/payroll/consolidated/2026/4/disbursements', ['employee_id' => $this->drivers['bilal']->id, 'bank_amount' => 0, 'cash_amount' => 120, 'paid_at' => '2026-05-01'])->assertCreated();

        $sheet = $this->getJson('/api/payroll/consolidated/2026/4/bank-sheet')->assertOk()->json();

        $this->assertSame(['Ahmad'], array_column($sheet['rows'], 'name'));
        $this->assertSame(40.0, (float) $sheet['rows'][0]['amount'], '100 of salary, 60 already sent');
        $this->assertSame(60.0, (float) $sheet['rows'][0]['already_transferred']);
        $this->assertSame(1, $sheet['left_out']['paid']);

        // The rest of his salary by bank, and the bank has nothing left to take for him.
        $this->postJson('/api/payroll/consolidated/2026/4/disbursements', ['employee_id' => $this->drivers['ahmad']->id, 'bank_amount' => 40, 'cash_amount' => 0, 'paid_at' => '2026-05-02'])->assertCreated();
        $after = $this->getJson('/api/payroll/consolidated/2026/4/bank-sheet')->json();
        $this->assertSame([], $after['rows']);
        $this->assertSame(1, $after['left_out']['bank_side_sent'], 'he is still owed 20.000 — in cash');
    }

    public function test_two_men_on_one_account_are_pointed_at(): void
    {
        // Written past the form, which would have refused it.
        Employee::where('id', $this->drivers['careem']->id)->update(['iban' => self::NBK_IBAN]);
        $this->approveApril();

        $sheet = $this->getJson('/api/payroll/consolidated/2026/4/bank-sheet')->assertOk()->json();

        $this->assertSame(2, $sheet['totals']['duplicate_ibans']);
        $this->assertSame([true, false, true], array_column($sheet['rows'], 'duplicate_iban'));   // Ahmad, Bilal, Careem
    }

    public function test_only_an_approved_month_has_a_bank_sheet_and_only_a_payer_may_ask(): void
    {
        $this->getJson('/api/payroll/consolidated/2026/4/bank-sheet')->assertStatus(422);

        $this->approveApril();
        $role = Role::create(['name' => 'قارئ الرواتب', 'company_id' => $this->company->id, 'allowed_modules' => ['payroll.view', 'contract_payroll.view']]);
        $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@bank.test', 'password' => bcrypt('password'), 'role' => 'قارئ الرواتب', 'company_id' => $this->company->id]);
        Employee::create(['name' => 'Viewer', 'employee_number' => 'EMP-V', 'company_id' => $this->company->id, 'status' => 'active', 'role_category' => 'admin', 'admin_role_id' => $role->id, 'user_id' => $viewer->id, 'date_of_joining' => '2026-01-01']);

        // He may read the sheet; the IBANs are for whoever pays.
        $this->actingAs($viewer)->getJson('/api/payroll/consolidated/2026/4')->assertOk();
        $this->actingAs($viewer)->getJson('/api/payroll/consolidated/2026/4/bank-sheet')->assertStatus(403);
    }
}
