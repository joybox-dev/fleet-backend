<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ContractRevenueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client revenue read 0.000 on every contract while driver cost computed normally.
 *
 * The readers summed `daily_logs.income_amount`, which DailyLogController fills from
 * `contracts.rate_per_order` — a single flat rate that `client_pricing_rules` replaced and which
 * is 0.000 on every live contract. The stored column was therefore 0 on all 2,209 logs, and the
 * profitability screen showed 1,823.398 of driver cost against no revenue at all.
 *
 * Pricing is done from the rules at read time so past months come out right without a data
 * migration. Orders whose zone matches no rule are reported, never given an invented price — the
 * old contract-dashboard fallback averaged the zone prices, which bills a client for a rate
 * nobody agreed.
 */
class ContractRevenueIsPricedFromRulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $driver;

    private Vehicle $vehicle;

    private Client $client;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Revenue Co',
            'code' => 'revco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@revco.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        $this->driver = Employee::create([
            'name' => 'Revenue Driver',
            'employee_number' => 'EMP-R-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
        ]);

        $this->client = Client::create([
            'name' => 'Revenue Client',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-R-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function contract(array $rules): Contract
    {
        return Contract::create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'contract_number' => 'C-'.uniqid(),
            'name' => 'عقد اختبار',
            'start_date' => '2026-01-01',
            'is_active' => true,
            'payment_type' => 'per_order',
            'rate_per_order' => 0,          // exactly the state every live contract is in
            'client_pricing_rules' => $rules,
        ]);
    }

    /**
     * @param  array<string, int>|null  $zoneOrders
     */
    private function log(Contract $contract, int $orders, ?array $zoneOrders = null, string $date = '2026-08-05'): DailyLog
    {
        return DailyLog::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'contract_id' => $contract->id,
            'log_date' => $date,
            'orders_count' => $orders,
            'orders_online' => $orders,
            'orders_cash' => 0,
            'income_amount' => 0,           // the stale column the readers used to trust
            'created_by' => $this->user->id,
            'notes' => $zoneOrders === null ? null : json_encode(['zone_orders' => $zoneOrders]),
        ]);
    }

    private function bill(Contract $contract): array
    {
        return ContractRevenueService::forContractMonth(
            $contract,
            DailyLog::with('vehicle:id,vehicle_type_id')->where('contract_id', $contract->id)->get()
        );
    }

    public function test_zone_priced_orders_are_billed_at_the_agreed_rate(): void
    {
        $contract = $this->contract([
            '2' => [
                'payment_method' => 'zones',
                'zones' => [
                    ['id' => 'z1', 'name' => 'الفئة 1', 'price' => '0.850'],
                    ['id' => 'z2', 'name' => 'الفئة 2', 'price' => '1.350'],
                ],
            ],
        ]);

        $this->log($contract, 30, ['z1' => 20, 'z2' => 10]);

        $billed = $this->bill($contract);

        // 20 × 0.850 + 10 × 1.350 = 17.000 + 13.500
        $this->assertSame(30.5, $billed['revenue']);
        $this->assertSame(30, $billed['orders']);
        $this->assertSame(0, $billed['unpriced_orders']);
    }

    public function test_a_tiered_contract_bills_the_band_the_month_lands_in(): void
    {
        $contract = $this->contract([
            '2' => [
                'payment_method' => 'tiers',
                'tiers' => [
                    ['min' => '1', 'max' => '99', 'price' => '1.250'],
                    ['min' => '100', 'max' => '500', 'price' => '1.000'],
                ],
            ],
        ]);

        $this->log($contract, 60, null, '2026-08-05');
        $this->log($contract, 60, null, '2026-08-06');

        // 120 orders lands in the second band, and the whole month is priced at it.
        $this->assertSame(120.0, $this->bill($contract)['revenue']);
    }

    public function test_orders_with_no_zone_are_reported_not_invented(): void
    {
        $contract = $this->contract([
            '2' => [
                'payment_method' => 'zones',
                'zones' => [
                    ['id' => 'z1', 'name' => 'الفئة 1', 'price' => '0.850'],
                    ['id' => 'z2', 'name' => 'الفئة 2', 'price' => '1.350'],
                ],
            ],
        ]);

        $this->log($contract, 40, []);

        $billed = $this->bill($contract);

        $this->assertSame(0.0, $billed['revenue'], 'an average of the zone prices would be a rate nobody agreed');
        $this->assertSame(40, $billed['unpriced_orders']);
        $this->assertTrue($billed['details'][0]['is_unpriced']);
    }

    /**
     * A day can be attributed in part — the remainder carries no price and must still be visible.
     */
    public function test_a_partly_attributed_day_bills_only_what_carries_a_zone(): void
    {
        $contract = $this->contract([
            '2' => [
                'payment_method' => 'zones',
                'zones' => [['id' => 'z1', 'name' => 'الفئة 1', 'price' => '2.000']],
            ],
        ]);

        $this->log($contract, 50, ['z1' => 20]);

        $billed = $this->bill($contract);

        $this->assertSame(40.0, $billed['revenue'], '20 priced orders at 2.000');
        $this->assertSame(30, $billed['unpriced_orders'], 'the other 30 carry no zone');
    }

    public function test_a_vehicle_type_with_no_client_rule_bills_nothing_and_says_so(): void
    {
        $contract = $this->contract([
            '9' => ['payment_method' => 'zones', 'zones' => [['id' => 'z1', 'price' => '5.000']]],
        ]);

        $this->log($contract, 25, ['z1' => 25]);

        $billed = $this->bill($contract);

        $this->assertSame(0.0, $billed['revenue']);
        $this->assertSame(25, $billed['unpriced_orders']);
        $this->assertStringContainsString('لا توجد قاعدة تسعير', $billed['details'][0]['label']);
    }

    /**
     * The regression that started this: the stored column is 0 everywhere and must not be trusted.
     */
    public function test_the_stale_income_column_is_not_what_is_reported(): void
    {
        $contract = $this->contract([
            '2' => [
                'payment_method' => 'zones',
                'zones' => [['id' => 'z1', 'name' => 'الفئة 1', 'price' => '1.750']],
            ],
        ]);

        $this->log($contract, 100, ['z1' => 100]);

        $this->assertSame(
            0.0,
            round((float) DailyLog::where('contract_id', $contract->id)->sum('income_amount'), 3),
            'the column the readers used to sum'
        );
        $this->assertSame(175.0, $this->bill($contract)['revenue']);
    }

    /**
     * A contract set up before the payment method was demanded can still be edited.
     *
     * The method is stored twice — a column on the contract, and a `payment_method` inside every
     * pricing rule — and the edit screen only ever writes the rules. Requiring the COLUMN therefore
     * rejected the very save that was completing the contract, with "The client payment method
     * field is required" on a form that shows no such field. The owner hit it on five live
     * contracts while filling in the working days their payroll could not be computed without.
     */
    public function test_a_contract_states_its_payment_method_through_its_pricing_rules(): void
    {
        // Shaped as the live rows are: the column blank, the method named only inside the rules.
        $contract = Contract::create([
            'client_id' => $this->client->id,
            'name' => 'عقد قديم',
            'contract_number' => 'LEGACY-1',
            'company_id' => $this->company->id,
            'payment_type' => 'per_order',
            'start_date' => '2026-01-01',
            'client_payment_method' => null,
            'driver_payment_method' => null,
            'client_pricing_rules' => ['2' => ['payment_method' => 'zones', 'zones' => [['id' => 'z1', 'name' => 'الطلب', 'price' => '1.750']]]],
            'driver_pricing_rules' => ['2' => ['payment_method' => 'fixed', 'fixed_amount' => '250', 'fixed_target' => '50']],
        ]);

        $this->assertNull($contract->client_payment_method, 'the fixture must start with the column blank');

        $response = $this->actingAs($this->user)->putJson("/api/contracts/{$contract->id}", [
            'name' => 'عقد قديم',
            'client_id' => $this->client->id,
            'default_required_work_days' => 26,
            'client_pricing_rules' => ['2' => ['payment_method' => 'zones', 'zones' => [['id' => 'z1', 'name' => 'الطلب', 'price' => '1.750']]]],
            'driver_pricing_rules' => ['2' => ['payment_method' => 'fixed', 'fixed_amount' => '250', 'fixed_target' => '50']],
        ]);

        $response->assertOk();

        $contract->refresh();
        $this->assertSame(26, (int) $contract->default_required_work_days, 'the field being filled in has to save');

        // The column is filled from the rules, so the contract stops disagreeing with itself.
        $this->assertSame('zones', $contract->client_payment_method);
        $this->assertSame('fixed', $contract->driver_payment_method);
    }

    /**
     * Reading the method off the rules is not a guess: a contract pricing its vehicle types by
     * DIFFERENT methods has a real disagreement, and has to be told which one it runs on.
     */
    public function test_a_contract_pricing_two_types_two_ways_must_still_say_which_it_uses(): void
    {
        $contract = Contract::create([
            'client_id' => $this->client->id,
            'name' => 'عقد مختلط',
            'contract_number' => 'LEGACY-2',
            'company_id' => $this->company->id,
            'payment_type' => 'per_order',
            'start_date' => '2026-01-01',
            'client_payment_method' => null,
            'driver_payment_method' => null,
        ]);

        $this->actingAs($this->user)->putJson("/api/contracts/{$contract->id}", [
            'name' => 'عقد مختلط',
            'client_id' => $this->client->id,
            'default_required_work_days' => 26,
            'client_pricing_rules' => [
                '2' => ['payment_method' => 'fixed', 'fixed_amount' => '900'],
                '3' => ['payment_method' => 'tiers', 'tiers' => [['min' => 1, 'max' => null, 'price' => '0.400']]],
            ],
            'driver_pricing_rules' => ['2' => ['payment_method' => 'fixed', 'fixed_amount' => '250']],
        ])->assertStatus(422)->assertJsonValidationErrors(['client_payment_method']);
    }
}
