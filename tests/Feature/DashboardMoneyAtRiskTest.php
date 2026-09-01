<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\MoneyAtRiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard counted vehicles, employees, today's orders and pending cash — all true, none of
 * it about where money goes. This band answers that, and every figure in it came out of a real
 * fault found in this data.
 */
class DashboardMoneyAtRiskTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Vehicle $vehicle;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Risk Co',
            'code' => 'riskco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@riskco.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        $this->client = Client::create([
            'name' => 'Risk Client',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->driver = Employee::create([
            'name' => 'Risk Driver',
            'employee_number' => 'EMP-RK-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-RK-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);
    }

    private function contract(): Contract
    {
        return Contract::create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'contract_number' => 'C-RK-'.uniqid(),
            'name' => 'عقد المخاطر',
            'start_date' => '2026-01-01',
            'is_active' => true,
            'payment_type' => 'per_order',
            'rate_per_order' => 0,
            'client_pricing_rules' => [
                '2' => [
                    'payment_method' => 'zones',
                    'zones' => [['id' => 'z1', 'name' => 'الفئة 1', 'price' => '2.000']],
                ],
            ],
        ]);
    }

    private function log(Contract $contract, int $orders, ?array $zones, string $date): void
    {
        DailyLog::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'contract_id' => $contract->id,
            'log_date' => $date,
            'orders_count' => $orders,
            'orders_online' => $orders,
            'orders_cash' => 0,
            'income_amount' => 0,
            'created_by' => $this->user->id,
            'notes' => $zones === null ? null : json_encode(['zone_orders' => $zones]),
        ]);
    }

    public function test_it_values_the_orders_that_can_be_neither_billed_nor_paid(): void
    {
        $contract = $this->contract();
        $this->log($contract, 100, ['z1' => 100], '2026-08-05');   // priced at 2.000
        $this->log($contract, 40, [], '2026-08-06');               // no zone at all

        $risk = MoneyAtRiskService::forMonth($this->company->id, 2026, 8);
        $unbilled = $risk['unbilled_orders'];

        $this->assertSame(40, $unbilled['orders']);
        $this->assertSame(140, $unbilled['total_orders']);
        $this->assertSame(29, $unbilled['share'], '40 of 140');
        // Valued at what the priced orders actually fetched: 200.000 / 100 = 2.000 each.
        $this->assertSame(80.0, $unbilled['estimated_value']);
        $this->assertSame('عقد المخاطر', $unbilled['contracts'][0]['name']);
    }

    public function test_the_margin_is_revenue_against_driver_cost(): void
    {
        $contract = $this->contract();
        $this->log($contract, 100, ['z1' => 100], '2026-08-05');

        $margin = MoneyAtRiskService::forMonth($this->company->id, 2026, 8)['margin'];

        $this->assertSame(200.0, $margin['revenue']);
        $this->assertSame(0.0, $margin['driver_cost'], 'no approved contract run in this month');
        $this->assertSame(200.0, $margin['net']);
    }

    /**
     * A fine is resolved only inside its own month, so one left behind is not collectable later —
     * which is exactly why it needs saying out loud rather than sitting in a list.
     */
    public function test_it_separates_fines_that_can_no_longer_be_collected(): void
    {
        foreach ([['2026-06-10', 30.0], ['2026-07-14', 20.0], ['2026-08-03', 15.0]] as [$date, $amount]) {
            Violation::create([
                'company_id' => $this->company->id,
                'employee_id' => $this->driver->id,
                'vehicle_id' => $this->vehicle->id,
                'created_by' => $this->user->id,
                'violation_date' => $date,
                'violation_type' => 'وقوف ممنوع',
                'amount' => $amount,
                'driver_deduction' => $amount,
                'driver_share' => $amount,
                'is_driver_liable' => 1,
                'is_deducted' => 0,
            ]);
        }

        $risk = MoneyAtRiskService::forMonth($this->company->id, 2026, 8);

        $this->assertSame(65.0, $risk['uncollected_charges']['amount'], 'all three are still owed');
        $this->assertSame(3, $risk['uncollected_charges']['items']);
        $this->assertSame(1, $risk['uncollected_charges']['employees']);

        $this->assertSame(2, $risk['unreachable_fines']['count'], 'June and July are out of reach');
        $this->assertSame(50.0, $risk['unreachable_fines']['amount']);
    }

    /**
     * A band of zeros on the first of a month answers nothing, so it reports the last month that
     * had orders and says which one.
     */
    public function test_a_month_with_no_orders_falls_back_to_the_last_one_that_had_some(): void
    {
        $contract = $this->contract();
        $this->log($contract, 100, ['z1' => 100], '2026-08-05');
        $this->log($contract, 0, null, '2026-09-01');   // the month is open but nothing delivered

        $risk = MoneyAtRiskService::forMonth($this->company->id, 2026, 9);

        $this->assertTrue($risk['is_fallback']);
        $this->assertSame(['year' => 2026, 'month' => 9], $risk['requested_period']);
        $this->assertSame(['year' => 2026, 'month' => 8], $risk['period']);
        $this->assertSame(200.0, $risk['margin']['revenue'], 'August figures, not an empty September');
    }

    public function test_a_month_with_orders_is_reported_as_asked(): void
    {
        $contract = $this->contract();
        $this->log($contract, 10, ['z1' => 10], '2026-08-05');

        $risk = MoneyAtRiskService::forMonth($this->company->id, 2026, 8);

        $this->assertFalse($risk['is_fallback']);
        $this->assertSame(['year' => 2026, 'month' => 8], $risk['period']);
    }
}
