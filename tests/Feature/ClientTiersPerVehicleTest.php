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
use App\Services\ContractRevenueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A client's volume bands are read on the contract's whole month unless its rule says
 * `tiers_per_vehicle`; then each car is banded on its own month. «مركز سلطان»'s bands (1–391,
 * 392–475 … 812+) are one car's month — 14 to 29 orders a day over 28 days — so on the whole
 * contract every order fell in the last band: 2,040.400 billed in 8/2026 against 3,278.500 car by
 * car. The owner asked for a switch in the client's pricing, off by default.
 *
 * Fixture: bands of 0.300 an order up to 100 orders and 0.200 above; drivers paid 0.500 an order.
 */
class ClientTiersPerVehicleTest extends TestCase
{
    use RefreshDatabase;

    private const BANDS = [
        ['min' => 1, 'max' => 100, 'price' => 0.3],
        ['min' => 101, 'max' => null, 'price' => 0.2],
    ];

    private Company $company;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->company = Company::create([
            'name' => 'Bands Co',
            'code' => 'bandsco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@bands.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);
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
            'name' => 'عقد الشرائح',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'client_payment_method' => 'tiers',
            'driver_payment_method' => 'tiers',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'is_active' => true,
            'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => $clientRule + ['payment_method' => 'tiers']],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'tiers', 'tiers' => [['min' => 1, 'max' => null, 'price' => 0.5]]]],
            'is_validity_enabled' => false,
        ]);
    }

    private function car(string $plate, int $typeId = 1): Vehicle
    {
        return Vehicle::create([
            'plate_number' => $plate,
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => $typeId,
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

    private function log(Employee $driver, Contract $contract, Vehicle $car, string $date, int $orders): void
    {
        DailyLog::create([
            'company_id' => $this->company->id,
            'employee_id' => $driver->id,
            'vehicle_id' => $car->id,
            'contract_id' => $contract->id,
            'log_date' => $date,
            'driver_status' => 'working',
            'orders_count' => $orders,
            'created_by' => $this->admin->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function billed(Contract $contract): array
    {
        $logs = DailyLog::withoutGlobalScopes()->with('vehicle')->where('contract_id', $contract->id)->get();

        return ContractRevenueService::forContractMonth($contract->fresh(), $logs);
    }

    /** @return array<string, mixed> */
    private function split(Contract $contract): array
    {
        $logs = DailyLog::withoutGlobalScopes()->with('vehicle')->where('contract_id', $contract->id)->get();

        return ContractRevenueService::forContractDrivers($contract->fresh(), $logs);
    }

    /**
     * Two cars, 80 and 70 orders.
     *
     * @return array{0: Contract, 1: Employee, 2: Employee}
     */
    private function twoCars(array $clientRule): array
    {
        $contract = $this->contract($clientRule);
        $a = $this->driverOn($contract, "D-A-{$contract->id}");
        $b = $this->driverOn($contract, "D-B-{$contract->id}");
        $this->log($a, $contract, $this->car("CAR-A-{$contract->id}"), '2026-09-01', 80);
        $this->log($b, $contract, $this->car("CAR-B-{$contract->id}"), '2026-09-01', 70);

        return [$contract, $a, $b];
    }

    public function test_bands_stay_on_the_contracts_whole_month_unless_the_rule_says_otherwise(): void
    {
        // No switch at all — every contract saved before it existed — and the switch saved off.
        foreach ([['tiers' => self::BANDS], ['tiers' => self::BANDS, 'tiers_per_vehicle' => false]] as $i => $rule) {
            [$contract] = $this->twoCars($rule);

            // 150 orders on the contract: the 0.200 band.
            $billed = $this->billed($contract);
            $this->assertSame(30.0, $billed['revenue'], "case {$i}");
            $this->assertSame(0, $billed['unpriced_orders']);
            $this->assertSame('شريحة (101–∞)', $billed['details'][0]['label']);
        }
    }

    public function test_the_switch_bands_each_car_on_its_own_month(): void
    {
        [$contract, $a, $b] = $this->twoCars(['tiers' => self::BANDS, 'tiers_per_vehicle' => true]);

        // Each car alone is in the 0.300 band: 24.000 + 21.000.
        $billed = $this->billed($contract);
        $this->assertSame(45.0, $billed['revenue']);
        $this->assertSame(150, $billed['orders']);
        $this->assertSame(0, $billed['unpriced_orders']);
        $this->assertCount(1, $billed['details']);
        $this->assertSame('شريحة (1–100) لكل سيارة — عدد السيارات 2', $billed['details'][0]['label']);
        $this->assertSame(150, $billed['details'][0]['orders']);
        $this->assertSame(0.3, $billed['details'][0]['rate']);

        $split = $this->split($contract);
        $this->assertSame(45.0, $split['contract']['revenue']);
        $this->assertSame(24.0, $split['drivers'][$a->id]['revenue']);
        $this->assertSame(21.0, $split['drivers'][$b->id]['revenue']);
    }

    public function test_two_drivers_on_one_car_share_its_band_and_a_driver_on_two_cars_gets_each_cars_rate(): void
    {
        $contract = $this->contract(['tiers' => self::BANDS, 'tiers_per_vehicle' => true]);
        $shared = $this->car('CAR-SHARED');
        $own = $this->car('CAR-OWN');
        $a = $this->driverOn($contract, 'D-A');
        $b = $this->driverOn($contract, 'D-B');
        $this->log($a, $contract, $shared, '2026-09-01', 60);
        $this->log($b, $contract, $shared, '2026-09-02', 60);   // the car carried 120: the 0.200 band
        $this->log($b, $contract, $own, '2026-09-03', 90);      // this one 90: the 0.300 band

        $billed = $this->billed($contract);
        $this->assertSame(51.0, $billed['revenue'], '120 × 0.200 + 90 × 0.300');
        $this->assertSame(
            ['شريحة (1–100) لكل سيارة — عدد السيارات 1', 'شريحة (101–∞) لكل سيارة — عدد السيارات 1'],
            array_column($billed['details'], 'label'),
            'one line a band, in the order the contract lists them'
        );

        $split = $this->split($contract);
        $this->assertSame(12.0, $split['drivers'][$a->id]['revenue']);
        $this->assertSame(39.0, $split['drivers'][$b->id]['revenue'], '60 × 0.200 on the shared car + 90 × 0.300 on his own');
        $this->assertSame(51.0, $split['contract']['revenue']);

        // The vehicle report gives each car its own bill, not a share of the total by orders
        // (which would be 29.143 and 21.857).
        $vehicles = collect($this->getJson('/api/reports/vehicle-profitability?year=2026&month=9')->assertOk()->json('vehicles'));
        $this->assertEquals(24.0, $vehicles->firstWhere('vehicle_id', $shared->id)['revenue']);
        $this->assertEquals(27.0, $vehicles->firstWhere('vehicle_id', $own->id)['revenue']);
    }

    public function test_a_car_no_band_covers_is_counted_and_named_not_priced(): void
    {
        $contract = $this->contract(['tiers' => [['min' => 1, 'max' => 50, 'price' => 0.3]], 'tiers_per_vehicle' => true]);
        $a = $this->driverOn($contract, 'D-A');
        $b = $this->driverOn($contract, 'D-B');
        $this->log($a, $contract, $this->car('CAR-A'), '2026-09-01', 40);
        $this->log($b, $contract, $this->car('CAR-B'), '2026-09-01', 70);   // above the only band

        $billed = $this->billed($contract);
        $this->assertSame(12.0, $billed['revenue']);
        $this->assertSame(70, $billed['unpriced_orders']);
        $this->assertTrue($billed['details'][1]['is_unpriced']);

        $dashboard = $this->getJson("/api/contracts/{$contract->id}/dashboard?year=2026&month=9")->assertOk()->json();
        $rows = collect($dashboard['drivers']);
        $this->assertSame(70, $rows->firstWhere('employee_id', $b->id)['client_unpriced_orders']);
        $this->assertSame(0, $rows->firstWhere('employee_id', $a->id)['client_unpriced_orders']);
        $this->assertEquals(12.0, $rows->firstWhere('employee_id', $a->id)['client_revenue']);
        $this->assertSame(70, $dashboard['revenue']['unpriced_orders']);
    }

    public function test_a_contract_banding_one_type_per_car_and_billing_another_flat_gives_each_car_the_right_money(): void
    {
        [$contract, $a, $b] = $this->twoCars(['tiers' => self::BANDS, 'tiers_per_vehicle' => true]);
        $contract->update([
            'client_pricing_rules' => $contract->client_pricing_rules + ['2' => ['payment_method' => 'fixed', 'fixed_amount' => 600]],
            'driver_pricing_rules' => $contract->driver_pricing_rules + ['2' => ['payment_method' => 'tiers', 'tiers' => [['min' => 1, 'max' => null, 'price' => 0.5]]]],
        ]);
        $this->log($a, $contract, $this->car('CAR-C', 2), '2026-09-02', 30);
        $this->log($b, $contract, $this->car('CAR-D', 2), '2026-09-02', 10);

        // 24.000 + 21.000 car by car, and the other type's flat 600.000.
        $this->assertSame(645.0, $this->billed($contract)['revenue']);
        $this->assertSame(645.0, $this->split($contract)['contract']['revenue']);

        // The banded cars keep their own bills; only the flat fee is shared, by the orders of
        // the cars it covers (30 and 10) — never by the banded cars' orders.
        $vehicles = collect($this->getJson('/api/reports/vehicle-profitability?year=2026&month=9')->assertOk()->json('vehicles'))
            ->pluck('revenue', 'plate_number');
        $this->assertEquals(24.0, $vehicles["CAR-A-{$contract->id}"]);
        $this->assertEquals(21.0, $vehicles["CAR-B-{$contract->id}"]);
        $this->assertEquals(450.0, $vehicles['CAR-C']);
        $this->assertEquals(150.0, $vehicles['CAR-D']);
    }

    public function test_the_switch_left_on_a_rule_that_is_no_longer_banded_changes_nothing(): void
    {
        // The form keeps the key when the method moves away from bands.
        [$contract] = $this->twoCars(['payment_method' => 'fixed', 'fixed_amount' => 300, 'tiers' => self::BANDS, 'tiers_per_vehicle' => true]);

        $this->assertSame(300.0, $this->billed($contract)['revenue']);
        $this->assertSame(300.0, $this->split($contract)['contract']['revenue']);
    }

    public function test_the_switch_is_saved_with_the_contract_and_every_screen_reads_it(): void
    {
        [$contract] = $this->twoCars(['tiers' => self::BANDS]);

        $before = $this->getJson("/api/contracts/{$contract->id}/dashboard?year=2026&month=9")->assertOk()->json();
        $this->assertEquals(30.0, $before['financials']['actual']['revenue']);

        // What the contract form sends when the box is ticked.
        $rules = $contract->client_pricing_rules;
        $rules['1']['tiers_per_vehicle'] = true;
        $this->putJson("/api/contracts/{$contract->id}", [
            'client_pricing_rules' => $rules,
            'driver_pricing_rules' => $contract->driver_pricing_rules,
        ])->assertOk();
        $this->assertTrue($contract->fresh()->client_pricing_rules['1']['tiers_per_vehicle']);

        $after = $this->getJson("/api/contracts/{$contract->id}/dashboard?year=2026&month=9")->assertOk()->json();
        $this->assertEquals(45.0, $after['financials']['actual']['revenue']);
        $this->assertEquals(45.0, collect($after['drivers'])->sum('client_revenue'));

        $report = collect($this->getJson('/api/reports/contract-profitability?year=2026&month=9')->assertOk()->json('contracts'))
            ->firstWhere('contract_id', $contract->id);
        $this->assertEquals(45.0, $report['revenue']);
    }
}
