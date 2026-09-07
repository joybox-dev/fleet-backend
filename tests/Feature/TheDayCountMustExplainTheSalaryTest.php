<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\EmployeeLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A driver whose salary is split across several contracts spends a few days on each and is marked
 * paid leave for the rest, so his fixed share comes out whole. That left three screens quoting
 * three different day counts for the same driver, contract and month — and none of them the truth:
 *
 *   the entry grid   31 / 31   attendance plus paid leave
 *   the contract sheet      6   attendance only, printed beside 50.000 د.ك
 *   the statement         143   every contract's month added together, in a 31-day August
 *
 * The middle one was the dangerous one: 6 × the daily rate is 11.538, so an accountant checking the
 * sheet could not arrive at the figure printed next to it. The formula line under it said the same
 * thing a different way — it opened on the paid days while the amount was built from the capped
 * days, so it read "31 يوم × 1.923 = 50.000".
 *
 * The sheet now carries the days the pay actually rests on, and the statement counts a month once.
 * Not one figure of pay changes.
 */
class TheDayCountMustExplainTheSalaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Vehicle $vehicle;

    /** @var array<int, Contract> */
    private array $contracts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Split Co',
            'code' => 'splitco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Split Admin',
            'email' => 'admin@split.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Split Client', 'company_id' => $this->company->id]);

        \DB::table('vehicle_types')->updateOrInsert(['id' => 2], [
            'company_id' => $this->company->id,
            'name' => 'Small Car',
            'name_ar' => 'سيارة صغيرة',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->driver = Employee::create([
            'name' => 'Split Driver',
            'employee_number' => 'EMP-SPL-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'PLATE-SPL',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);

        // Two contracts, each carrying half of a 100.000 salary, each paying over 26 days.
        foreach ([['A', 'CON-SPL-A'], ['B', 'CON-SPL-B']] as [$suffix, $number]) {
            $contract = Contract::create([
                'client_id' => $client->id,
                'contract_number' => $number,
                'name' => 'Split Contract '.$suffix,
                'payment_type' => 'per_order',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'company_id' => $this->company->id,
                'currency' => 'KWD',
                'default_required_work_days' => 26,
                'is_validity_enabled' => false,
                'driver_pricing_rules' => [
                    '2' => ['payment_method' => 'fixed', 'fixed_amount' => 50, 'fixed_target' => 0],
                ],
            ]);

            ContractAssignment::create([
                'employee_id' => $this->driver->id,
                'contract_id' => $contract->id,
                'start_date' => '2026-01-01',
                'status' => 'active',
                'company_id' => $this->company->id,
            ]);

            $this->contracts[] = $contract;
        }

        $this->actingAs($this->user);
    }

    private function log(Contract $contract, int $day, string $status, int $orders = 0): void
    {
        DailyLog::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $contract->id,
            'vehicle_id' => $this->vehicle->id,
            'log_date' => sprintf('2026-03-%02d', $day),
            'driver_status' => $status,
            'orders_count' => $orders,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * March has 31 days. He works the first 5 on contract A and the next 5 on contract B, and the
     * rest of each month is marked paid leave — the arrangement the bulk button produces.
     */
    private function splitMonth(): void
    {
        for ($day = 1; $day <= 31; $day++) {
            $this->log($this->contracts[0], $day, $day <= 5 ? 'working' : 'paid_leave', $day <= 5 ? 4 : 0);
            $this->log($this->contracts[1], $day, ($day > 5 && $day <= 10) ? 'working' : 'paid_leave', ($day > 5 && $day <= 10) ? 4 : 0);
        }
    }

    /** @return array<string, mixed> */
    private function sheetRow(Contract $contract): array
    {
        $response = $this->getJson("/api/payroll/contract-sheet/{$contract->id}?year=2026&month=3")->assertOk();
        $row = collect($response->json('drivers'))->firstWhere('employee_id', $this->driver->id);
        $this->assertNotNull($row, 'driver missing from the contract sheet');

        return $row;
    }

    public function test_the_sheet_reports_the_days_the_pay_rests_on(): void
    {
        $this->splitMonth();

        $row = $this->sheetRow($this->contracts[0]);

        // Attendance is still reported, and is still only five days.
        $this->assertSame(5, $row['actual_work_days']);
        // What the pay rests on: 31 days marked paid, capped at the contract's own 26.
        $this->assertSame(31, $row['paid_days']);
        $this->assertSame(26, $row['payable_days']);
        $this->assertSame(26, $row['contract_working_days']);
    }

    public function test_the_payable_days_multiply_out_to_the_salary_on_the_sheet(): void
    {
        $this->splitMonth();

        $row = $this->sheetRow($this->contracts[0]);

        $daily = 50 / 26;
        $this->assertEqualsWithDelta(
            $row['payable_days'] * $daily,
            (float) $row['gross_contract_earnings'],
            0.0005,
            'the day count on the sheet must be the one that produces the figure beside it'
        );
        $this->assertEqualsWithDelta(50.000, (float) $row['gross_contract_earnings'], 0.0005);

        // And the working shown under it opens on that same number, not on the uncapped one.
        $formula = collect($row['calculation_details'])->firstWhere('label', 'الراتب المستحق عن أيام الدوام الفعلي')['formula'];
        $this->assertStringStartsWith('26 يوم مدفوع', $formula);
        $this->assertStringContainsString('من 31 يوم مدفوع', $formula, 'the cap has to say what it capped');
    }

    public function test_a_month_is_counted_once_however_many_contracts_it_spans(): void
    {
        $this->splitMonth();

        $history = EmployeeLedgerService::history($this->driver->fresh());
        $march = collect($history['months'])->firstWhere(fn ($m) => $m['year'] === 2026 && $m['month'] === 3);

        // Two contracts × 31 rows used to add up to 62 days in a 31-day month.
        $this->assertSame(31, $march['work_days']);
        $this->assertEqualsWithDelta(100.000, (float) $march['gross_earnings'], 0.0005);
    }

    public function test_a_contracts_own_row_counts_the_days_it_pays_for(): void
    {
        // One contract where a stretch of the month was never touched at all.
        for ($day = 1; $day <= 31; $day++) {
            $this->log($this->contracts[0], $day, $day <= 20 ? 'working' : 'unpaid_leave', $day <= 20 ? 3 : 0);
        }

        $history = EmployeeLedgerService::history($this->driver->fresh());
        $march = collect($history['months'])->firstWhere(fn ($m) => $m['year'] === 2026 && $m['month'] === 3);
        $row = collect($march['contracts'])->firstWhere('contract_id', $this->contracts[0]->id);

        // 20 paid days, not the 31 rows the month holds.
        $this->assertSame(20, $row['work_days']);
    }
}
