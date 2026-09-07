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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عقد الدوائية prices a driver from one table: «إجمالي الطلبات بالشهر يحدد الشريحة»، and inside
 * that band each zone has its own price, with a lump-sum bonus for the band. The numbers here are
 * the client's own sheet for سائقي الصالون:
 *
 *   1-250    سيء        زون1 300  زون2 400  زون3 500  زون4 600   فلس   بونص 0
 *   251-350  متوسط      زون1 400  زون2 500  زون3 600  زون4 850   فلس   بونص 5 د.ك
 *   351-500  جيد        زون1 500  زون2 600  زون3 700  زون4 900   فلس   بونص 20 د.ك
 *   501+     جيد جداً    زون1 550  زون2 650  زون3 750  زون4 1000  فلس   بونص 30 د.ك
 *
 * The existing zones_tiers method cannot express it: it bands each zone on that zone's own count,
 * so a 400-order month spread over four zones lands every one of them on «سيء» and pays 180.000
 * where the contract says 270.000 plus a 20.000 bonus.
 */
class TieredZonesPaysTheMonthsBandTest extends TestCase
{
    use RefreshDatabase;

    private const ZONES = ['z1' => 'زون 1', 'z2' => 'زون 2', 'z3' => 'زون 3', 'z4' => 'زون 4'];

    private Company $company;

    private User $user;

    private Employee $driver;

    private Vehicle $vehicle;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Dawaiya Co',
            'code' => 'dawaiya',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Dawaiya Admin',
            'email' => 'admin@dawaiya.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        \DB::table('vehicle_types')->updateOrInsert(['id' => 2], [
            'company_id' => $this->company->id,
            'name' => 'Saloon',
            'name_ar' => 'صالون',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $client = Client::create(['name' => 'Dawaiya Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Saloon Driver',
            'employee_number' => 'EMP-DWA-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'PLATE-DWA',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);

        $zones = [];
        foreach (self::ZONES as $id => $name) {
            $zones[] = ['id' => $id, 'name' => $name, 'price' => 1.000];
        }

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-DWA',
            'name' => 'عقد الدوائية',
            'payment_type' => 'per_order',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => 26,
            'is_validity_enabled' => false,
            'client_payment_method' => 'zones',
            'driver_payment_method' => 'tiered_zones',
            'client_pricing_rules' => ['2' => ['payment_method' => 'zones', 'zones' => $zones]],
            'driver_pricing_rules' => ['2' => [
                'payment_method' => 'tiered_zones',
                'tiered_zones' => [
                    ['id' => 't1', 'min' => 1, 'max' => 250, 'label' => 'سيء', 'bonus' => 0,
                        'prices' => ['z1' => 0.300, 'z2' => 0.400, 'z3' => 0.500, 'z4' => 0.600]],
                    ['id' => 't2', 'min' => 251, 'max' => 350, 'label' => 'متوسط', 'bonus' => 5,
                        'prices' => ['z1' => 0.400, 'z2' => 0.500, 'z3' => 0.600, 'z4' => 0.850]],
                    ['id' => 't3', 'min' => 351, 'max' => 500, 'label' => 'جيد', 'bonus' => 20,
                        'prices' => ['z1' => 0.500, 'z2' => 0.600, 'z3' => 0.700, 'z4' => 0.900]],
                    ['id' => 't4', 'min' => 501, 'max' => null, 'label' => 'جيد جداً', 'bonus' => 30,
                        'prices' => ['z1' => 0.550, 'z2' => 0.650, 'z3' => 0.750, 'z4' => 1.000]],
                ],
            ]],
        ]);

        ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user);
    }

    /**
     * @param  array<string, int>  $zoneOrders
     */
    private function day(int $day, array $zoneOrders): void
    {
        DailyLog::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'vehicle_id' => $this->vehicle->id,
            'log_date' => sprintf('2026-03-%02d', $day),
            'driver_status' => 'working',
            'orders_count' => array_sum($zoneOrders),
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
            'notes' => json_encode(['zone_orders' => $zoneOrders], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array<string, mixed> */
    private function sheetRow(): array
    {
        $response = $this->getJson("/api/payroll/contract-sheet/{$this->contract->id}?year=2026&month=3")->assertOk();
        $row = collect($response->json('drivers'))->firstWhere('employee_id', $this->driver->id);
        $this->assertNotNull($row, 'driver missing from the contract sheet');

        return $row;
    }

    /** 400 orders, 100 in each zone, over ten days. */
    private function fourHundredEvenly(): void
    {
        for ($day = 1; $day <= 10; $day++) {
            $this->day($day, ['z1' => 10, 'z2' => 10, 'z3' => 10, 'z4' => 10]);
        }
    }

    public function test_the_months_total_picks_the_band_not_each_zones_own_count(): void
    {
        $this->fourHundredEvenly();

        $row = $this->sheetRow();

        // 400 lands on «جيد» (351-500): 100 × (0.500 + 0.600 + 0.700 + 0.900) = 270.000, +20 bonus.
        $this->assertSame(400, $row['orders_count']);
        $this->assertEqualsWithDelta(290.000, (float) $row['gross_contract_earnings'], 0.0005);

        // Every zone must be quoted at that band's price, though each holds only 100 orders — which
        // on its own would fall in «سيء».
        $rates = collect($row['calculation_details'])
            ->filter(fn ($l) => isset($l['rate']))
            ->mapWithKeys(fn ($l) => [$l['label'] => round((float) $l['rate'], 3)]);
        $this->assertSame(0.500, $rates->first(fn ($v, $k) => str_contains($k, 'زون 1')));
        $this->assertSame(0.900, $rates->first(fn ($v, $k) => str_contains($k, 'زون 4')));
    }

    public function test_the_band_bonus_is_paid_once_for_the_month_not_once_per_zone(): void
    {
        $this->fourHundredEvenly();

        $bonusLines = collect($this->sheetRow()['calculation_details'])
            ->filter(fn ($l) => str_contains($l['label'], 'بونص'))
            ->values();

        $this->assertCount(1, $bonusLines, 'four zones must not each collect the band bonus');
        $this->assertEqualsWithDelta(20.000, (float) $bonusLines[0]['amount'], 0.0005);
    }

    public function test_a_heavier_month_climbs_to_the_top_band(): void
    {
        // 600 orders: 400 in زون 1 and 200 in زون 2.
        for ($day = 1; $day <= 10; $day++) {
            $this->day($day, ['z1' => 40, 'z2' => 20]);
        }

        $row = $this->sheetRow();

        // 501+ «جيد جداً»: 400 × 0.550 + 200 × 0.650 = 350.000, +30 bonus.
        $this->assertSame(600, $row['orders_count']);
        $this->assertEqualsWithDelta(380.000, (float) $row['gross_contract_earnings'], 0.0005);
    }

    public function test_the_lowest_band_carries_no_bonus(): void
    {
        // 200 orders, all in زون 3 → «سيء», 0.500 an order, no bonus.
        for ($day = 1; $day <= 10; $day++) {
            $this->day($day, ['z3' => 20]);
        }

        $row = $this->sheetRow();

        $this->assertEqualsWithDelta(100.000, (float) $row['gross_contract_earnings'], 0.0005);
        $this->assertCount(
            0,
            collect($row['calculation_details'])->filter(fn ($l) => str_contains($l['label'], 'بونص')),
            'the سيء band pays no bonus'
        );
    }

    public function test_orders_with_no_zone_are_reported_and_not_quietly_paid(): void
    {
        // A day whose orders were never attributed to a zone.
        $this->day(1, ['z1' => 100]);
        DailyLog::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'vehicle_id' => $this->vehicle->id,
            'log_date' => '2026-03-02',
            'driver_status' => 'working',
            'orders_count' => 60,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);

        $row = $this->sheetRow();

        $this->assertSame(160, $row['orders_count'], 'the unattributed orders still count toward the band');
        // 100 × 0.300 (سيء, زون 1) and nothing for the 60 that name no zone.
        $this->assertEqualsWithDelta(30.000, (float) $row['gross_contract_earnings'], 0.0005);

        $unpriced = collect($row['calculation_details'])->firstWhere('is_unpriced', true);
        $this->assertNotNull($unpriced, 'the unattributed orders must appear on the sheet');
        $this->assertSame(60, $unpriced['orders']);
    }

    public function test_the_sheet_names_the_band_it_used(): void
    {
        $this->fourHundredEvenly();

        $labels = collect($this->sheetRow()['calculation_details'])->pluck('label')->implode(' | ');

        $this->assertStringContainsString('شريحة جيد (351-500 طلب بالشهر)', $labels);
    }

    public function test_it_reaches_the_contract_sheet_and_the_consolidated_sheet_alike(): void
    {
        $this->fourHundredEvenly();

        // The contract sheet names the method rather than printing its raw key.
        $row = $this->sheetRow();
        $this->assertSame('tiered_zones', $row['payment_method']);
        $this->assertSame('فئات بشريحة الشهر (Monthly Tier × Zones)', $row['payment_method_label']);

        // Approving the contract carries the same figure into the consolidated sheet.
        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", [
            'year' => 2026, 'month' => 3,
        ])->assertOk();

        $consolidated = $this->getJson('/api/payroll/consolidated/2026/3')->assertOk();
        $driver = collect($consolidated->json('drivers'))->firstWhere('employee_id', $this->driver->id);

        $this->assertNotNull($driver, 'driver missing from the consolidated sheet');
        $this->assertEqualsWithDelta(290.000, (float) $driver['gross_contract_earnings'], 0.0005);
        $this->assertSame(400, $driver['orders_count']);

        $worked = collect($driver['contracts_worked'])->firstWhere('contract_id', $this->contract->id);
        $this->assertNotNull($worked, 'the contract must be listed under the driver');
        $this->assertEqualsWithDelta(290.000, (float) $worked['gross'], 0.0005);
    }
}
