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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each driver row of a contract's dashboard says what his own orders billed the client and how
 * many of them were billed nothing. «آرض الطبيعة» in 8/2026 warned of 231 unbilled orders while no
 * row said whose: the row's marker was his PAY's, and a tier-paid driver's orders price for his pay
 * with no zone at all. And the rows are the contract's bill divided, not each driver priced alone —
 * «مركز سلطان»'s rows came to 3,278.500 against the 2,040.400 it billed.
 *
 * Fixture: drivers paid 0.500 an order whatever the zone, so their pay never lacks a price.
 */
class ContractDashboardBillsEachDriverTest extends TestCase
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
            'name' => 'Billing Co',
            'code' => 'billco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@billing.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-BILL-1',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $clientRule */
    private function contract(array $clientRule): Contract
    {
        return Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-'.uniqid(),
            'name' => 'عقد الفوترة',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'client_payment_method' => $clientRule['payment_method'],
            'driver_payment_method' => 'tiers',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'is_active' => true,
            'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => $clientRule],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'tiers', 'tiers' => [['min' => 1, 'max' => null, 'price' => 0.5]]]],
            'is_validity_enabled' => false,
        ]);
    }

    private function driverOn(Contract $contract, string $number): Employee
    {
        $driver = Employee::create([
            'name' => "Driver {$number}",
            'employee_number' => $number,
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
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
            'created_by' => $this->admin->id,
            'notes' => $zones === null ? null : json_encode(['zone_orders' => $zones]),
        ]);
    }

    /** @return array<string, mixed> */
    private function dashboard(Contract $contract): array
    {
        return $this->getJson("/api/contracts/{$contract->id}/dashboard?year=2026&month=9")->assertOk()->json();
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @return array<string, mixed>
     */
    private function row(array $dashboard, Employee $driver): array
    {
        $row = collect($dashboard['drivers'])->firstWhere('employee_id', $driver->id);
        $this->assertNotNull($row, "no row for {$driver->employee_number}");

        return $row;
    }

    public function test_a_drivers_orders_with_no_zone_are_named_on_his_own_row(): void
    {
        $contract = $this->contract(['payment_method' => 'zones', 'zones' => [
            ['id' => 'z1', 'name' => 'اكسبريس', 'price' => 1.6],
            ['id' => 'z2', 'name' => 'العادي', 'price' => 1.35],
        ]]);
        $unzoned = $this->driverOn($contract, 'D-1');
        $zoned = $this->driverOn($contract, 'D-2');
        $this->log($unzoned, $contract, '2026-09-01', 10, ['z1' => 10]);   // 16.000
        $this->log($unzoned, $contract, '2026-09-02', 15);                 // no zone: billed nothing
        $this->log($zoned, $contract, '2026-09-01', 20, ['z2' => 20]);     // 27.000

        $dashboard = $this->dashboard($contract);

        $row = $this->row($dashboard, $unzoned);
        $this->assertSame(15, $row['client_unpriced_orders']);
        $this->assertSame(0, (int) $row['unpriced_orders'], 'his pay prices all 25 orders: it goes by volume, not zone');
        $this->assertEquals(16.0, $row['client_revenue']);

        $row = $this->row($dashboard, $zoned);
        $this->assertSame(0, $row['client_unpriced_orders']);
        $this->assertEquals(27.0, $row['client_revenue']);

        // The contract's warning and the rows count the same orders.
        $this->assertSame(15, $dashboard['revenue']['unpriced_orders']);
        $this->assertContains('unpriced_orders', array_column($dashboard['alerts'], 'type'));
    }

    public function test_a_tier_priced_contracts_rows_add_up_to_what_it_billed(): void
    {
        // Less per order the more the month carries, as «مركز سلطان»'s bands are.
        $contract = $this->contract(['payment_method' => 'tiers', 'tiers' => [
            ['min' => 1, 'max' => 100, 'price' => 0.3],
            ['min' => 101, 'max' => null, 'price' => 0.2],
        ]]);
        $a = $this->driverOn($contract, 'D-A');
        $b = $this->driverOn($contract, 'D-B');
        $this->log($a, $contract, '2026-09-01', 80);
        $this->log($b, $contract, '2026-09-01', 70);

        $dashboard = $this->dashboard($contract);

        // 150 orders on the contract fall in the 0.200 band. Priced alone, each driver sat in the
        // 0.300 one: 24.000 + 21.000 = 45.000 on a contract that billed 30.000.
        $this->assertEquals(30.0, $dashboard['financials']['actual']['revenue']);
        $this->assertEquals(16.0, $this->row($dashboard, $a)['client_revenue']);
        $this->assertEquals(14.0, $this->row($dashboard, $b)['client_revenue']);
        $this->assertSame(0, $this->row($dashboard, $a)['client_unpriced_orders']);
    }

    public function test_a_flat_fee_is_shared_by_days_not_billed_on_every_row(): void
    {
        $contract = $this->contract(['payment_method' => 'fixed', 'fixed_amount' => 300]);
        $a = $this->driverOn($contract, 'D-A');
        $b = $this->driverOn($contract, 'D-B');
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
            $this->log($a, $contract, $date, 10);
        }
        $this->log($b, $contract, '2026-09-04', 5);

        $dashboard = $this->dashboard($contract);

        // 300.000 over four logged days: three to A, one to B — it read 300.000 on each row.
        $this->assertEquals(300.0, $dashboard['financials']['actual']['revenue']);
        $this->assertEquals(225.0, $this->row($dashboard, $a)['client_revenue']);
        $this->assertEquals(75.0, $this->row($dashboard, $b)['client_revenue']);
    }
}
