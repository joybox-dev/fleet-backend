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
 * Pricing rules are held per vehicle type. A driver who spends part of the month on a small car and
 * the rest on a large one therefore has two prices, and the month used to resolve to no type at all
 * — which paid the whole month 0.000, every order unpriced, even where the contract had a rule for
 * both types. On screen it read as a badge saying "أكثر من نوع" beside a bare zero.
 *
 * Refusing to guess a single type was right; paying nothing was not. Each stretch of days is now
 * priced by the rule for the vehicle actually driven in it, and the owner's ruling is that a tier
 * is chosen by the orders run on that vehicle — "كل نوع على شريحتو" — not by the month's total.
 */
class MixedVehicleTypeIsPricedPerTypeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Contract $contract;

    private Vehicle $smallCar;

    private Vehicle $largeCar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Mixed Type Co',
            'code' => 'mixedco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Mixed Admin',
            'email' => 'admin@mixed.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        // Written straight to the table: `id` is not fillable, so create() would auto-increment
        // and the pricing rules — which are keyed by vehicle type id — would point at nothing.
        foreach ([[2, 'Small Car', 'سيارة صغيرة'], [3, 'Large Car', 'سيارة كبيرة']] as [$id, $name, $nameAr]) {
            $this->vehicleType($id, $name, $nameAr);
        }

        $client = Client::create(['name' => 'Mixed Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Mixed Driver',
            'employee_number' => 'EMP-MIX-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->smallCar = Vehicle::create([
            'plate_number' => 'V-MIX-S',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);

        $this->largeCar = Vehicle::create([
            'plate_number' => 'V-MIX-L',
            'make' => 'Nissan',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 3,
        ]);

        // Both types are priced, and the second band is deliberately far richer than the first, so
        // charging the month's combined volume would show up as a much larger number.
        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-MIX',
            'name' => 'Mixed Type Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed',
            'driver_payment_method' => 'tiers',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => 26,
            'is_validity_enabled' => false,
            'client_pricing_rules' => ['2' => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_pricing_rules' => [
                '2' => ['payment_method' => 'tiers', 'tiers' => [
                    ['min' => 1, 'max' => 100, 'price' => 0.500],
                    ['min' => 101, 'max' => null, 'price' => 1.000],
                ]],
                '3' => ['payment_method' => 'tiers', 'tiers' => [
                    ['min' => 1, 'max' => 100, 'price' => 0.400],
                    ['min' => 101, 'max' => null, 'price' => 0.900],
                ]],
            ],
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

    private function vehicleType(int $id, string $name, string $nameAr): void
    {
        \DB::table('vehicle_types')->updateOrInsert(
            ['id' => $id],
            [
                'company_id' => $this->company->id,
                'name' => $name,
                'name_ar' => $nameAr,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function log(string $date, Vehicle $vehicle, int $orders): void
    {
        DailyLog::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'vehicle_id' => $vehicle->id,
            'log_date' => $date,
            'driver_status' => 'working',
            'orders_count' => $orders,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function row(): array
    {
        $response = $this->getJson("/api/payroll/contract-sheet/{$this->contract->id}?year=2026&month=3")->assertOk();
        $row = collect($response->json('drivers'))->firstWhere('employee_id', $this->driver->id);
        $this->assertNotNull($row, 'driver missing from the contract sheet');

        return $row;
    }

    /** 60 orders on the small car and 60 on the large one, six days each. */
    private function halfAndHalf(): void
    {
        foreach (range(2, 7) as $day) {
            $this->log(sprintf('2026-03-%02d', $day), $this->smallCar, 10);
        }
        foreach (range(10, 15) as $day) {
            $this->log(sprintf('2026-03-%02d', $day), $this->largeCar, 10);
        }
    }

    public function test_a_month_split_across_two_vehicle_types_is_paid_not_zeroed(): void
    {
        $this->halfAndHalf();

        $row = $this->row();

        $this->assertTrue((bool) $row['vehicle_type_is_mixed'], 'the month really does span two types');
        $this->assertGreaterThan(0.0, (float) $row['gross_contract_earnings'], 'a priceable month must not read 0.000');
        $this->assertFalse((bool) $row['unresolved_vehicle_type'], 'both types are priced by this contract');
    }

    public function test_each_type_is_charged_at_its_own_rule(): void
    {
        $this->halfAndHalf();

        // 60 on the small car at 0.500 = 30.000, 60 on the large at 0.400 = 24.000.
        $this->assertEqualsWithDelta(54.0, (float) $this->row()['gross_contract_earnings'], 0.001);
    }

    public function test_the_tier_follows_the_orders_run_on_that_vehicle_not_the_months_total(): void
    {
        $this->halfAndHalf();

        // 120 orders in the month, but neither vehicle carried more than 100, so neither reaches
        // the second band. Charging the combined volume would have paid 120.000 or 108.000.
        $gross = (float) $this->row()['gross_contract_earnings'];

        $this->assertEqualsWithDelta(54.0, $gross, 0.001);
        $this->assertLessThan(100.0, $gross, 'the month total must not decide either tier');
    }

    public function test_a_vehicle_that_does_cross_its_own_band_is_charged_the_higher_rate(): void
    {
        // 110 orders on the small car — past its 100 — and 20 on the large.
        foreach (range(2, 12) as $day) {
            $this->log(sprintf('2026-03-%02d', $day), $this->smallCar, 10);
        }
        foreach (range(15, 16) as $day) {
            $this->log(sprintf('2026-03-%02d', $day), $this->largeCar, 10);
        }

        // 110 × 1.000 = 110.000, plus 20 × 0.400 = 8.000.
        $this->assertEqualsWithDelta(118.0, (float) $this->row()['gross_contract_earnings'], 0.001);
    }

    public function test_a_single_type_month_is_unchanged(): void
    {
        foreach (range(2, 7) as $day) {
            $this->log(sprintf('2026-03-%02d', $day), $this->smallCar, 10);
        }

        $row = $this->row();

        $this->assertFalse((bool) $row['vehicle_type_is_mixed']);
        $this->assertEqualsWithDelta(30.0, (float) $row['gross_contract_earnings'], 0.001);
    }

    public function test_a_type_the_contract_has_no_rule_for_earns_nothing_and_says_so(): void
    {
        $this->vehicleType(9, 'Truck', 'شاحنة');

        $truck = Vehicle::create([
            'plate_number' => 'V-MIX-T',
            'make' => 'Isuzu',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 9,
        ]);

        foreach (range(2, 7) as $day) {
            $this->log(sprintf('2026-03-%02d', $day), $this->smallCar, 10);
        }
        foreach (range(10, 12) as $day) {
            $this->log(sprintf('2026-03-%02d', $day), $truck, 10);
        }

        $row = $this->row();

        // The small car's days are still paid; only the unpriced truck days earn nothing, and the
        // row says so rather than letting the reader assume the 30.000 is the whole story.
        $this->assertEqualsWithDelta(30.0, (float) $row['gross_contract_earnings'], 0.001);
        $this->assertTrue((bool) $row['unresolved_vehicle_type'], 'the truck has no rule and must be flagged');
    }

    public function test_the_breakdown_names_the_vehicle_type_of_each_line(): void
    {
        $this->halfAndHalf();

        $labels = collect($this->row()['calculation_details'] ?? [])->pluck('label')->implode(' | ');

        $this->assertStringContainsString('سيارة صغيرة', $labels);
        $this->assertStringContainsString('سيارة كبيرة', $labels);
    }
}
