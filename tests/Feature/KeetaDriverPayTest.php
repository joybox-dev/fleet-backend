<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\DriverContractOverride;
use App\Models\Employee;
use App\Models\KeetaInvoice;
use App\Models\KeetaInvoiceRider;
use App\Models\KeetaLevelSnapshot;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ContractSheetService;
use App\Services\KeetaDriverPayService;
use App\Services\KeetaRevenueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The owner's Keeta scenario («السيناريو المحسّن المعتدل», from August 2026), checked against the
 * formulas of the client's own workbook «حساب رواتب كيتا شهر 8»:
 *
 *   S/A/B and valid (≥ 280 orders, ≤ 4 invalid days): 350 / 300 / 250 ± 0.550 per order from 400;
 *   C/D or not valid: 0.400 per order; plus 25 from 500 orders, 35 from 600.
 *
 * The level comes from the experience incentive on Keeta's statement (370 S · 270 A · 170 B ·
 * 50 C · 0 D), the orders and valid days from the same row — Keeta's count is the one paid.
 */
class KeetaDriverPayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Contract $contract;

    private Vehicle $vehicle;

    private int $courier = 100;

    /** @var array<int, string> courier id by driver */
    private array $couriers = [];

    /** @var array<int, int> assignment id by driver */
    private array $assignments = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 10:00:00');

        $this->company = Company::create(['name' => 'Keeta Pay Co', 'code' => 'keetapay', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@keetapay.test', 'password' => bcrypt('password'),
            'role' => 'admin', 'company_id' => $this->company->id,
        ]);
        $client = Client::create(['name' => 'Keeta', 'company_id' => $this->company->id]);
        $this->vehicle = Vehicle::create(['plate_number' => 'KP-1', 'make' => 'Toyota', 'status' => 'working', 'company_id' => $this->company->id, 'vehicle_type_id' => 2]);

        // The contract's own driver rule — fixed 300 for 400 orders — stays for the months before.
        $this->contract = Contract::create([
            'client_id' => $client->id, 'contract_number' => 'CON-KP', 'name' => 'كيتا',
            'payment_type' => 'fixed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed', 'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id, 'currency' => 'KWD', 'default_required_work_days' => 26,
            'client_pricing_rules' => ['2' => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_pricing_rules' => ['2' => ['vehicle_type_id' => '2', 'payment_method' => 'fixed', 'fixed_amount' => 300, 'fixed_target' => 400, 'fixed_deficit_rate' => 0.75]],
        ]);
        $this->contract->forceFill([
            'keeta_settlement_from' => '2026-08-01',
            'keeta_pay_rules' => KeetaDriverPayService::DEFAULT_RULES,
        ])->saveQuietly();

        KeetaRevenueService::forget();
    }

    protected function tearDown(): void
    {
        KeetaRevenueService::forget();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_level_is_paid_the_way_the_workbook_computes_it(): void
    {
        $invoice = $this->invoice(2026, 8);
        $cases = [
            // name => [orders, valid days, experience incentive, expected pay] — rows of the workbook.
            'S valid, 550' => [550, 29, 370, 457.5],     // 350 + 150 × 0.55 + 25
            'A valid, 591' => [591, 29, 270, 430.05],    // 300 + 191 × 0.55 + 25
            'B valid, 392' => [392, 28, 170, 245.6],     // 250 − 8 × 0.55
            'C, 401' => [401, 30, 50, 160.4],            // 401 × 0.4
            'D, 483' => [483, 26, 0, 193.2],             // 483 × 0.4
            'A, 6 invalid days' => [470, 25, 270, 188.0], // not valid: 470 × 0.4
            'A, 650' => [650, 29, 270, 472.5],           // 300 + 250 × 0.55 + 35
            'B, under 280' => [270, 29, 170, 108.0],     // not valid: 270 × 0.4
        ];
        $drivers = [];
        foreach ($cases as $name => [$orders, $validDays, $incentive]) {
            $drivers[$name] = $this->driver($name);
            $this->logs($drivers[$name], '2026-08', 10, 1);
            $this->rider($invoice, $drivers[$name], $orders, $validDays, $incentive);
        }

        $sheet = $this->sheet(2026, 8);
        foreach ($cases as $name => [, , , $expected]) {
            $row = $sheet[$drivers[$name]->id];
            $this->assertSame('keeta_tiers', $row['payment_method'], $name);
            $this->assertEqualsWithDelta($expected, $row['gross_contract_earnings'], 0.0005, $name);
        }
        $this->assertSame(591, $sheet[$drivers['A valid, 591']->id]['orders_count'], 'Keeta\'s count is the one paid, not the 10 logged');
    }

    public function test_the_owner_can_set_a_riders_invalid_days_by_hand(): void
    {
        $invoice = $this->invoice(2026, 8);
        $driver = $this->driver('Abdelseid');
        $this->logs($driver, '2026-08', 25, 18);
        $rider = $this->rider($invoice, $driver, 470, 25, 270);

        $this->assertEqualsWithDelta(188.0, $this->sheet(2026, 8)[$driver->id]['gross_contract_earnings'], 0.0005, '31 − 25 = 6 invalid days: paid per order');

        $rider->update(['invalid_days_override' => 4]);
        KeetaRevenueService::forget();
        $this->assertEqualsWithDelta(338.5, $this->sheet(2026, 8)[$driver->id]['gross_contract_earnings'], 0.0005, '4 by hand: A salary 300 + 70 × 0.55');
    }

    public function test_a_driver_who_worked_but_is_not_on_keetas_statement_holds_the_month(): void
    {
        $invoice = $this->invoice(2026, 8);
        $onStatement = $this->driver('On statement');
        $this->rider($invoice, $onStatement, 450, 29, 170);
        $missing = $this->driver('No courier id');
        $this->logs($missing, '2026-08', 3, 5);

        $build = ContractSheetService::build($this->contract->fresh(), 2026, 8, $this->company->id);
        $rows = collect($build['drivers'])->keyBy('employee_id');

        $this->assertEqualsWithDelta(0.0, $rows[$missing->id]['gross_contract_earnings'], 0.0005);
        $blockers = ContractSheetService::approvalBlockers($build['drivers']);
        $this->assertCount(1, $blockers);
        $this->assertSame($missing->id, $blockers[0]['employee_id']);
        $this->assertSame(15, $blockers[0]['unpriced_orders']);
    }

    public function test_before_keetas_statement_the_month_is_an_estimate_that_cannot_be_approved(): void
    {
        $driver = $this->driver('Estimated');
        $this->logs($driver, '2026-09', 18, 25); // 450 orders logged so far
        $snapshot = KeetaLevelSnapshot::create(['company_id' => $this->company->id, 'contract_id' => $this->contract->id, 'year' => 2026, 'month' => 9, 'taken_on' => '2026-09-19']);
        $snapshot->rows()->create(['courier_id' => $this->couriers[$driver->id], 'employee_id' => $driver->id, 'level' => 'A', 'reward' => 270, 'orders' => 450]);

        $build = ContractSheetService::build($this->contract->fresh(), 2026, 9, $this->company->id);
        $row = collect($build['drivers'])->firstWhere('employee_id', $driver->id);

        $this->assertSame('keeta_tiers', $row['payment_method']);
        $this->assertEqualsWithDelta(327.5, $row['gross_contract_earnings'], 0.0005, 'level A from Keeta\'s expected level: 300 + 50 × 0.55');
        $this->assertNotEmpty(ContractSheetService::approvalBlockers($build['drivers']), 'the month waits for Keeta\'s statement');
    }

    public function test_months_before_keeta_keep_the_contracts_own_rule_and_an_override_still_wins(): void
    {
        $july = $this->driver('July');
        $this->logs($july, '2026-07', 26, 16);
        $this->assertSame('fixed', $this->sheet(2026, 7)[$july->id]['payment_method']);

        $invoice = $this->invoice(2026, 8);
        $special = $this->driver('Own deal');
        $this->logs($special, '2026-08', 26, 16);
        $this->rider($invoice, $special, 416, 30, 270);
        DriverContractOverride::create([
            'company_id' => $this->company->id, 'contract_assignment_id' => $this->assignments[$special->id],
            'override_type' => 'fixed', 'custom_fixed_salary' => 260, 'custom_pricing_rules' => ['fixed_amount' => 260],
            'effective_from' => '2026-08-01', 'effective_to' => '2026-08-31', 'customization_reason' => 'اتفاق خاص',
        ]);

        $row = $this->sheet(2026, 8)[$special->id];
        $this->assertSame('fixed', $row['payment_method']);
        $this->assertEqualsWithDelta(260.0, $row['gross_contract_earnings'], 0.0005);
    }

    public function test_the_contract_form_stores_the_scenario_and_switches_it_off(): void
    {
        $rules = [
            'enabled' => true,
            'tier_salaries' => ['S' => 360, 'A' => 300, 'B' => 250],
            'target' => 400, 'surplus_rate' => 0.55, 'deficit_rate' => 0.55,
            'min_orders' => 280, 'max_invalid_days' => 4, 'per_order_rate' => 0.4,
            'achievements' => [['orders' => 600, 'amount' => 35], ['orders' => 500, 'amount' => 25]],
            'tier_incentives' => ['S' => 370, 'A' => 270, 'B' => 170, 'C' => 50, 'D' => 0],
        ];
        $this->actingAs($this->admin)->putJson("/api/contracts/{$this->contract->id}", ['keeta_pay_rules' => $rules])->assertOk();

        $stored = $this->contract->fresh()->keeta_pay_rules;
        $this->assertEquals(360.0, $stored['tier_salaries']['S']);
        $this->assertSame([500, 600], array_column($stored['achievements'], 'orders'), 'kept in order of their thresholds');

        $this->actingAs($this->admin)->putJson("/api/contracts/{$this->contract->id}", ['keeta_pay_rules' => ['enabled' => false]])->assertOk();
        $this->assertNull($this->contract->fresh()->keeta_pay_rules);

        $this->actingAs($this->admin)->putJson("/api/contracts/{$this->contract->id}", ['keeta_pay_rules' => ['enabled' => true, 'target' => -1]])->assertStatus(422);
    }

    // ─── helpers ───────────────────────────────────────────────────────────────

    private function driver(string $name): Employee
    {
        $employee = Employee::create([
            'name' => $name, 'employee_number' => 'KP-'.(++$this->courier), 'company_id' => $this->company->id,
            'status' => 'active', 'role_category' => 'driver', 'date_of_joining' => '2026-01-01',
        ]);
        $assignment = ContractAssignment::create([
            'employee_id' => $employee->id, 'contract_id' => $this->contract->id, 'courier_id' => (string) $this->courier,
            'start_date' => '2026-01-01', 'status' => 'active', 'company_id' => $this->company->id,
        ]);
        $this->couriers[$employee->id] = (string) $this->courier;
        $this->assignments[$employee->id] = $assignment->id;

        return $employee;
    }

    private function logs(Employee $employee, string $month, int $days, int $orders): void
    {
        foreach (range(1, $days) as $day) {
            DailyLog::create([
                'employee_id' => $employee->id, 'contract_id' => $this->contract->id, 'vehicle_id' => $this->vehicle->id,
                'log_date' => sprintf('%s-%02d', $month, $day), 'driver_status' => 'working', 'orders_count' => $orders,
                'company_id' => $this->company->id, 'created_by' => $this->admin->id,
            ]);
        }
    }

    private function invoice(int $year, int $month): KeetaInvoice
    {
        return KeetaInvoice::create(['company_id' => $this->company->id, 'contract_id' => $this->contract->id, 'year' => $year, 'month' => $month]);
    }

    private function rider(KeetaInvoice $invoice, Employee $employee, int $orders, float $validDays, float $incentive): KeetaInvoiceRider
    {
        KeetaRevenueService::forget();

        return $invoice->riders()->create([
            'courier_id' => $this->couriers[$employee->id], 'employee_id' => $employee->id, 'name' => $employee->name,
            'is_valid' => $incentive > 0, 'valid_days' => $validDays, 'orders' => $orders, 'experience_incentive' => $incentive,
        ]);
    }

    /** @return array<int, array<string, mixed>> the month's sheet rows by driver */
    private function sheet(int $year, int $month): array
    {
        KeetaRevenueService::forget();

        return collect(ContractSheetService::build($this->contract->fresh(), $year, $month, $this->company->id)['drivers'])
            ->keyBy('employee_id')->all();
    }
}
