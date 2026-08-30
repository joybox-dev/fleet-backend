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
use App\Services\ContractPayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Only `fixed` and `zones` carry a target: the contract form offers the target/deficit/surplus
 * fields for those two methods and no other. `fixed` applied them; `zones` returned a hardcoded
 * deficit_deduction of 0.0, so the payroll sheet paid the raw zone total while the dashboard
 * showed the target applied.
 *
 * The target is a monthly figure earned day by day. A 300-order target on a 30-day contract is
 * 10 a day, so a driver who worked 5 days is judged against 50 — never against the full 300.
 */
class ZonesTargetDeficitTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Vehicle $vehicle;

    private Client $client;

    private const ZONE_A = 'z-alpha';

    private const ZONE_B = 'z-beta';

    /** Contract working days for every contract built here, so 300 ÷ 30 = 10 a day. */
    private const WORKING_DAYS = 30;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Zones Target Co',
            'code' => 'zonetgt',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@zonetgt.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        $this->client = Client::create(['name' => 'C', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Zone Driver',
            'employee_number' => 'EMP-ZT-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-01-01',
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-ZT-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);
    }

    /**
     * @param  array<string, mixed>  $ruleOverrides
     */
    private function contractWith(array $ruleOverrides, string $method = 'zones'): Contract
    {
        return Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-ZT-'.uniqid(),
            'name' => 'Zones Target Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => self::WORKING_DAYS,
            'driver_pricing_rules' => [
                2 => array_merge([
                    'vehicle_type_id' => '2',
                    'payment_method' => $method,
                    'zones' => [
                        ['id' => self::ZONE_A, 'name' => 'الفئة 1', 'price' => '0.500'],
                        ['id' => self::ZONE_B, 'name' => 'الفئة 2', 'price' => '1.000'],
                    ],
                    'tiers' => [],
                    'zones_tiers' => [],
                ], $ruleOverrides),
            ],
        ]);
    }

    /** Log `$days` consecutive working days, each with `$ordersPerDay` orders in one zone. */
    private function logDays(Contract $contract, int $days, int $ordersPerDay, string $zoneId = self::ZONE_A): void
    {
        foreach (range(1, $days) as $day) {
            DailyLog::create([
                'employee_id' => $this->driver->id,
                'contract_id' => $contract->id,
                'vehicle_id' => $this->vehicle->id,
                'log_date' => sprintf('2026-07-%02d', $day),
                'driver_status' => 'working',
                'orders_count' => $ordersPerDay,
                'company_id' => $this->company->id,
                'created_by' => $this->user->id,
                'notes' => json_encode(['zone_orders' => [$zoneId => $ordersPerDay]]),
            ]);
        }
    }

    private function calculate(Contract $contract): array
    {
        $assignment = ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $contract->id,
            'start_date' => '2026-07-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        return ContractPayrollService::calculateDriverContractPayroll(
            $this->driver->fresh(),
            $contract->fresh(),
            $assignment,
            null,
            DailyLog::where('contract_id', $contract->id)->get(),
            2
        );
    }

    /**
     * The worked example: a 30-day contract with a 300-order target is 10 orders a day, so five
     * worked days are judged against 50 — not against the full 300.
     */
    public function test_the_target_is_prorated_by_the_days_the_driver_actually_worked(): void
    {
        $contract = $this->contractWith([
            'zone_target_orders' => '300',
            'zone_deficit_rate' => '0.500',
            'zone_bonus_type' => 'per_order',
            'zone_surplus_rate' => '0.250',
        ]);

        // 5 days × 6 orders in zone A (0.500) = 30 orders, 15.000 KWD of zone pay.
        $this->logDays($contract, 5, 6);

        $result = $this->calculate($contract);

        $this->assertSame(30, (int) $result['orders_count']);
        $this->assertSame(50, (int) $result['required_target'], '5 days × (300 ÷ 30)');
        $this->assertSame(15.0, (float) $result['orders_bonus'], 'zone pricing itself is unchanged');
        $this->assertSame(10.0, (float) $result['deficit_deduction'], '20 orders short × 0.500');
        $this->assertSame(5.0, (float) $result['gross_contract_earnings'], '15.000 − 10.000');
    }

    /**
     * The same driver against the un-prorated target would have been charged
     * (300 − 30) × 0.500 = 135.000 and finished at −120.000. That is the bug this pins shut.
     */
    public function test_a_short_month_is_not_charged_against_the_whole_monthly_target(): void
    {
        $contract = $this->contractWith([
            'zone_target_orders' => '300',
            'zone_deficit_rate' => '0.500',
            'zone_bonus_type' => 'per_order',
            'zone_surplus_rate' => '0.250',
        ]);

        $this->logDays($contract, 5, 6);

        $result = $this->calculate($contract);

        $this->assertGreaterThan(
            0,
            (float) $result['gross_contract_earnings'],
            'a driver who met 60% of his prorated target must not finish the month in debt'
        );
        $this->assertNotSame(135.0, (float) $result['deficit_deduction']);
    }

    public function test_a_full_month_is_judged_against_the_whole_target_and_earns_the_surplus(): void
    {
        $contract = $this->contractWith([
            'zone_target_orders' => '300',
            'zone_deficit_rate' => '0.500',
            'zone_bonus_type' => 'per_order',
            'zone_surplus_rate' => '0.250',
        ]);

        // 30 days × 12 orders = 360 orders, 180.000 KWD of zone pay, against a full 300 target.
        $this->logDays($contract, 30, 12);

        $result = $this->calculate($contract);

        $this->assertSame(300, (int) $result['required_target'], '30 days × (300 ÷ 30)');
        $this->assertSame(0.0, (float) $result['deficit_deduction']);
        $this->assertSame(15.0, (float) $result['surplus_bonus'], '60 orders over × 0.250');
        $this->assertSame(195.0, (float) $result['gross_contract_earnings'], '180.000 + 15.000');
    }

    public function test_a_lump_sum_bonus_is_paid_whole_not_per_order(): void
    {
        $contract = $this->contractWith([
            'zone_target_orders' => '300',
            'zone_deficit_rate' => '0.500',
            'zone_bonus_type' => 'lump_sum',
            'zone_target_bonus' => '25.000',
            'zone_surplus_rate' => '0.250',
        ]);

        $this->logDays($contract, 30, 12);

        $result = $this->calculate($contract);

        $this->assertSame(25.0, (float) $result['surplus_bonus'], 'the flat bonus, not 60 × 0.250');
        $this->assertSame(205.0, (float) $result['gross_contract_earnings']);
    }

    /**
     * Nothing worked means nothing to judge. Against a flat monthly target this driver would have
     * been charged 300 × 0.500 = 150.000 for a month he was never on the road.
     */
    public function test_a_driver_who_worked_no_days_owes_nothing(): void
    {
        $contract = $this->contractWith([
            'zone_target_orders' => '300',
            'zone_deficit_rate' => '0.500',
        ]);

        $result = $this->calculate($contract);

        $this->assertSame(0, (int) $result['required_target']);
        $this->assertSame(0.0, (float) $result['deficit_deduction']);
        $this->assertSame(0.0, (float) $result['gross_contract_earnings']);
    }

    public function test_a_zones_contract_with_no_target_is_unaffected(): void
    {
        $contract = $this->contractWith([]);

        $this->logDays($contract, 5, 6);

        $result = $this->calculate($contract);

        $this->assertSame(0.0, (float) $result['deficit_deduction']);
        $this->assertSame(0.0, (float) $result['surplus_bonus']);
        $this->assertSame(15.0, (float) $result['gross_contract_earnings']);
    }

    /**
     * The form offers a target for `fixed` and `zones` only. A leftover zone_target_orders on a
     * zones_tiers rule is not configuration anyone entered for that method, and must stay inert.
     */
    public function test_a_stale_target_on_a_zones_tiers_rule_is_ignored(): void
    {
        $contract = $this->contractWith([
            'payment_method' => 'zones_tiers',
            // Residue from when this rule was `zones`.
            'zone_target_orders' => '420',
            'zone_deficit_rate' => '0.500',
            'zones' => [],
            'zones_tiers' => [
                ['id' => self::ZONE_A, 'name' => 'الفئة 1', 'tiers' => [
                    ['min' => '1', 'max' => '500', 'price' => '0.300'],
                ]],
            ],
        ], 'zones_tiers');

        $this->logDays($contract, 5, 20);

        $result = $this->calculate($contract);

        $this->assertSame(30.0, (float) $result['gross_contract_earnings'], '100 × 0.300, no deficit');
        $this->assertSame(0.0, (float) $result['deficit_deduction'], 'the stale 420 target must not bite');
    }
}
