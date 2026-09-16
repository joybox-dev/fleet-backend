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
use App\Models\VehicleExpense;
use App\Services\ContractProfitabilityService;
use App\Services\ContractRevenueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The contribution report: a driver's orders priced by his contract's client rules, against what
 * he earned in the contract sheet — and the driver rows adding up to the contract row.
 *
 * Fixture: contracts that pay the driver a fixed 260 over 26 days, so 10.000 a logged day. «زون»
 * bills by zone (z1 0.500, z2 1.000), «ثابت» bills a flat 300.000 a month shared by days, «شرائح»
 * bills by volume band (0.200 up to 100 orders, 0.300 above) on the contract's whole month.
 */
class ContributionReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Client $client;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->company = Company::create([
            'name' => 'Contribution Co',
            'code' => 'contribco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@contrib.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-CONTRIB-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $clientRule */
    private function contract(string $name, array $clientRule): Contract
    {
        return Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-'.uniqid(),
            'name' => $name,
            'payment_type' => 'fixed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'client_payment_method' => $clientRule['payment_method'],
            'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'is_active' => true,
            'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => $clientRule],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);
    }

    private function driverOn(Contract $contract, string $number, ?int $target = null): Employee
    {
        $driver = Employee::create([
            'name' => "Driver {$number}",
            'employee_number' => $number,
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
            'official_salary' => 100.000,
            'target_orders_monthly' => $target,
        ]);
        ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $contract->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        return $driver;
    }

    /** @param  array<string, int>|null  $zones */
    private function log(Employee $driver, Contract $contract, string $date, int $orders, ?array $zones = null): void
    {
        DailyLog::create([
            'company_id' => $this->company->id,
            'employee_id' => $driver->id,
            'vehicle_id' => $this->vehicle->id,
            'contract_id' => $contract->id,
            'log_date' => $date,
            'driver_status' => 'working',
            'orders_count' => $orders,
            'orders_online' => $orders,
            'orders_cash' => 0,
            'income_amount' => 0,
            'created_by' => $this->admin->id,
            'notes' => $zones === null ? null : json_encode(['zone_orders' => $zones]),
        ]);
    }

    /** @return array<string, mixed> */
    private function report(array $params = []): array
    {
        return $this->getJson('/api/reports/contribution?'.http_build_query($params + ['year' => 2026, 'month' => 9]))
            ->assertOk()
            ->json();
    }

    /** @return array<string, mixed> */
    private function row(array $rows, string $key, string $value): array
    {
        foreach ($rows as $row) {
            if ((string) $row[$key] === $value) {
                return $row;
            }
        }
        $this->fail("no row with {$key} = {$value}");
    }

    public function test_zone_orders_are_priced_per_driver_and_the_drivers_add_up_to_the_contract(): void
    {
        $zone = $this->contract('زون', ['payment_method' => 'zones', 'zones' => [
            ['id' => 'z1', 'name' => 'الفئة 1', 'price' => 0.5],
            ['id' => 'z2', 'name' => 'الفئة 2', 'price' => 1.0],
        ]]);
        $a = $this->driverOn($zone, 'D-A');
        $b = $this->driverOn($zone, 'D-B');
        $this->log($a, $zone, '2026-09-10', 100, ['z1' => 100]);   // 50.000, one day
        $this->log($b, $zone, '2026-09-10', 40, ['z2' => 40]);     // 40.000
        $this->log($b, $zone, '2026-09-11', 20, []);               // no zone: counted, not valued

        $report = $this->report();

        $contract = $this->row($report['contracts'], 'contract_name', 'زون');
        $this->assertSame(160, $contract['orders']);
        $this->assertSame(20, $contract['unpriced_orders']);
        $this->assertSame(90.0, (float) $contract['revenue']);
        $this->assertSame(30.0, (float) $contract['driver_cost'], 'three logged days at 10.000');
        $this->assertSame(60.0, (float) $contract['contribution']);
        $this->assertSame(66.7, (float) $contract['margin_pct']);
        $this->assertSame(2, $contract['drivers_count']);
        $this->assertFalse($contract['sheet_is_approved']);

        $rowA = $this->row($report['drivers'], 'employee_number', 'D-A');
        $rowB = $this->row($report['drivers'], 'employee_number', 'D-B');
        $this->assertSame([50.0, 10.0, 40.0], [(float) $rowA['revenue'], (float) $rowA['driver_cost'], (float) $rowA['contribution']]);
        $this->assertSame([40.0, 20.0, 20.0], [(float) $rowB['revenue'], (float) $rowB['driver_cost'], (float) $rowB['contribution']]);
        $this->assertSame(20, $rowB['unpriced_orders']);
        $this->assertSame(
            (float) $contract['revenue'],
            round((float) $rowA['revenue'] + (float) $rowB['revenue'], 3),
            'the driver rows are the contract row'
        );

        // Same figure the dashboard and the profitability report quote for the contract.
        $logs = DailyLog::withoutGlobalScopes()->with('vehicle:id,vehicle_type_id')->where('contract_id', $zone->id)->get();
        $this->assertSame(90.0, ContractRevenueService::forContractMonth($zone, $logs)['revenue']);

        // Ranked by contribution: A (40) above B (20).
        $this->assertSame(['D-A', 'D-B'], array_column($report['drivers'], 'employee_number'));
    }

    public function test_a_flat_fee_is_shared_by_days_and_a_tier_rate_is_the_contracts_not_the_drivers(): void
    {
        $flat = $this->contract('ثابت', ['payment_method' => 'fixed', 'fixed_amount' => 300]);
        $c = $this->driverOn($flat, 'D-C');
        $d = $this->driverOn($flat, 'D-D');
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
            $this->log($c, $flat, $date, 10);
        }
        $this->log($d, $flat, '2026-09-04', 5);

        $tiers = $this->contract('شرائح', ['payment_method' => 'tiers', 'tiers' => [
            ['min' => 1, 'max' => 100, 'price' => 0.2],
            ['min' => 101, 'max' => null, 'price' => 0.3],
        ]]);
        $e = $this->driverOn($tiers, 'D-E');
        $f = $this->driverOn($tiers, 'D-F');
        $this->log($e, $tiers, '2026-09-08', 80, ['any' => 80]);
        $this->log($f, $tiers, '2026-09-08', 70, ['any' => 70]);

        $report = $this->report();

        // 300.000 over four logged days: three to C, one to D.
        $rowC = $this->row($report['drivers'], 'employee_number', 'D-C');
        $rowD = $this->row($report['drivers'], 'employee_number', 'D-D');
        $this->assertSame(225.0, (float) $rowC['revenue']);
        $this->assertSame(75.0, (float) $rowD['revenue']);
        $this->assertSame(195.0, (float) $rowC['contribution'], '225 less three days at 10.000');
        $this->assertSame(65.0, (float) $rowD['contribution']);
        $flatRow = $this->row($report['contracts'], 'contract_name', 'ثابت');
        $this->assertSame(300.0, (float) $flatRow['revenue']);
        $this->assertSame(260.0, (float) $flatRow['contribution']);

        // 150 orders on the contract fall in the 0.300 band; alone, each driver would have been
        // priced at 0.200. The rate is the contract's.
        $rowE = $this->row($report['drivers'], 'employee_number', 'D-E');
        $rowF = $this->row($report['drivers'], 'employee_number', 'D-F');
        $this->assertSame(24.0, (float) $rowE['revenue']);
        $this->assertSame(21.0, (float) $rowF['revenue']);
        $tierRow = $this->row($report['contracts'], 'contract_name', 'شرائح');
        $this->assertSame(45.0, (float) $tierRow['revenue']);
        $this->assertSame(25.0, (float) $tierRow['contribution']);

        $this->assertSame(345.0, (float) $report['totals']['revenue']);
        $this->assertSame(60.0, (float) $report['totals']['driver_cost']);
        $this->assertSame(285.0, (float) $report['totals']['contribution']);
        $this->assertSame(2, $report['totals']['contracts']);
        $this->assertSame(4, $report['totals']['drivers']);
    }

    public function test_grades_read_a_personal_target_first_and_the_contract_average_otherwise(): void
    {
        $zone = $this->contract('زون', ['payment_method' => 'zones', 'zones' => [['id' => 'z1', 'name' => 'الفئة 1', 'price' => 0.5]]]);
        $a = $this->driverOn($zone, 'D-A');                 // 100 orders in one day
        $b = $this->driverOn($zone, 'D-B');                 // 60 orders over two days
        $t = $this->driverOn($zone, 'D-T', 100);            // a personal target of 100: 80 done → B
        $this->log($a, $zone, '2026-09-10', 100, ['z1' => 100]);
        $this->log($b, $zone, '2026-09-10', 40, ['z1' => 40]);
        $this->log($b, $zone, '2026-09-11', 20, ['z1' => 20]);
        $this->log($t, $zone, '2026-09-12', 80, ['z1' => 80]);

        $drivers = $this->report()['drivers'];

        $rowT = $this->row($drivers, 'employee_number', 'D-T');
        $this->assertSame(100, $rowT['target']);
        $this->assertSame(80.0, (float) $rowT['achievement_pct']);
        $this->assertSame('B', $rowT['grade']);
        $this->assertSame('personal_target', $rowT['grade_basis']);

        // Colleagues without a target: the contract averages 240 orders over 4 days = 60 a day.
        // A does 100 a day (167%) and B 30 a day (50%).
        $rowA = $this->row($drivers, 'employee_number', 'D-A');
        $rowB = $this->row($drivers, 'employee_number', 'D-B');
        $this->assertSame('A', $rowA['grade']);
        $this->assertSame(166.7, (float) $rowA['achievement_pct']);
        $this->assertSame('contract_average', $rowA['grade_basis']);
        $this->assertSame('D', $rowB['grade']);
        $this->assertSame(50.0, (float) $rowB['achievement_pct']);
    }

    public function test_a_lone_driver_gets_no_relative_grade_and_the_contract_filter_narrows_both_tables(): void
    {
        $zone = $this->contract('زون', ['payment_method' => 'zones', 'zones' => [['id' => 'z1', 'name' => 'الفئة 1', 'price' => 0.5]]]);
        $flat = $this->contract('ثابت', ['payment_method' => 'fixed', 'fixed_amount' => 300]);
        $a = $this->driverOn($zone, 'D-A');
        $c = $this->driverOn($flat, 'D-C');
        $this->log($a, $zone, '2026-09-10', 100, ['z1' => 100]);
        $this->log($c, $flat, '2026-09-10', 10);

        $all = $this->report();
        $this->assertCount(2, $all['contracts']);
        $this->assertCount(2, $all['drivers']);
        $this->assertNull($this->row($all['drivers'], 'employee_number', 'D-A')['grade'], 'nobody to compare with');
        $this->assertEqualsCanonicalizing(['زون', 'ثابت'], array_column($all['contract_options'], 'name'));

        $only = $this->report(['contract_id' => $zone->id]);
        $this->assertSame(['زون'], array_column($only['contracts'], 'contract_name'));
        $this->assertSame(['D-A'], array_column($only['drivers'], 'employee_number'));
        $this->assertSame($zone->id, $only['contract_id']);
        $this->assertCount(2, $only['contract_options'], 'the filter list still offers every contract');
        $this->assertSame(50.0, (float) $only['totals']['revenue']);
    }

    public function test_the_contract_row_carries_the_recorded_expenses_and_the_profit_after_them(): void
    {
        $zone = $this->contract('زون', ['payment_method' => 'zones', 'zones' => [['id' => 'z1', 'name' => 'الفئة 1', 'price' => 0.5]]]);
        $a = $this->driverOn($zone, 'D-A');
        $this->log($a, $zone, '2026-09-10', 100, ['z1' => 100]);   // 50.000 against 10.000
        VehicleExpense::create([
            'company_id' => $this->company->id,
            'vehicle_id' => $this->vehicle->id,
            'expense_type' => 'Fuel',
            'amount' => 15,
            'expense_date' => '2026-09-10',        // the day the vehicle worked this contract
        ]);

        $report = $this->report();
        $row = $this->row($report['contracts'], 'contract_name', 'زون');

        $this->assertSame(40.0, (float) $row['contribution']);
        $this->assertSame(15.0, (float) $row['vehicle_costs']);
        $this->assertSame(15.0, (float) $row['other_expenses']);
        $this->assertSame(25.0, (float) $row['profit'], 'contribution 40 less the 15 of fuel on record');
        $this->assertSame(50.0, (float) $row['profit_margin_pct']);
        $this->assertSame(25.0, (float) $report['totals']['profit']);
        $this->assertSame(15.0, (float) $report['totals']['other_expenses']);

        // The very figure the dashboard's profitability section shows for the contract.
        $dashboard = ContractProfitabilityService::forCompanyMonth($this->company->id, 2026, 9)[$zone->id];
        $this->assertSame((float) $row['profit'], (float) $dashboard['profit']);
        $this->assertSame((float) $row['revenue'], (float) $dashboard['revenue']);
    }

    public function test_the_revenue_endpoint_prices_the_month_without_building_sheets(): void
    {
        $zone = $this->contract('زون', ['payment_method' => 'zones', 'zones' => [['id' => 'z1', 'name' => 'الفئة 1', 'price' => 0.5]]]);
        $a = $this->driverOn($zone, 'D-A');
        $this->log($a, $zone, '2026-09-10', 100, ['z1' => 100]);
        $this->log($a, $zone, '2026-09-11', 10, []);   // no zone: counted, not billed

        $json = $this->getJson('/api/reports/contract-revenue?year=2026&month=9')->assertOk()->json();

        $this->assertCount(1, $json['contracts']);
        $this->assertSame('زون', $json['contracts'][0]['contract_name']);
        $this->assertSame($zone->id, $json['contracts'][0]['contract_id']);
        $this->assertSame(50.0, (float) $json['contracts'][0]['revenue']);
        $this->assertSame(110, $json['contracts'][0]['total_orders']);
        $this->assertSame(10, $json['contracts'][0]['unpriced_orders']);
        $this->assertSame(50.0, (float) $json['totals']['revenue']);
    }

    public function test_the_report_needs_the_reports_permission(): void
    {
        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@contrib.test',
            'password' => bcrypt('password'),
            'role' => 'driver',
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($viewer);
        $this->getJson('/api/reports/contribution?year=2026&month=9')->assertStatus(403);
    }
}
