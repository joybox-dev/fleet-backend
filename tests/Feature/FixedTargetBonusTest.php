<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\DriverContractOverride;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ContractPayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A fixed salary with a monthly target offers two bonuses for beating it, and the form names
 * both: «بونص مقطوع» — one amount, paid once when the target is reached — and «عمولة على الطلب»
 * — a rate paid on every order above it. The strategy used to pay per order whatever was chosen
 * (at the surplus rate, else the deficit rate), so a lump sum typed into the form was never paid
 * and the driver got a per-order bonus nobody had set.
 *
 * Every contract here: 260.000 a month over 26 working days with a 260-order target, i.e.
 * 10.000 and 10 orders a day.
 */
class FixedTargetBonusTest extends TestCase
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
            'name' => 'Fixed Bonus Co', 'code' => 'fixbonus', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin', 'email' => 'admin@fixbonus.test', 'password' => bcrypt('password'),
            'role' => 'admin', 'company_id' => $this->company->id,
        ]);
        $this->client = Client::create(['name' => 'C', 'company_id' => $this->company->id]);
        $this->driver = Employee::create([
            'name' => 'Target Driver', 'employee_number' => 'EMP-FB-1', 'company_id' => $this->company->id,
            'status' => 'active', 'date_of_joining' => '2026-01-01',
        ]);
        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-FB-1', 'make' => 'Toyota', 'status' => 'working',
            'company_id' => $this->company->id, 'vehicle_type_id' => 2,
        ]);
    }

    public function test_a_lump_sum_is_paid_once_however_far_the_target_is_beaten(): void
    {
        $contract = $this->contractWith(['fixed_bonus_type' => 'lump_sum', 'fixed_surplus_bonus' => '20.000', 'fixed_deficit_rate' => '0.250']);
        $this->logDays($contract, 26, 12);

        $result = $this->calculate($contract);

        $this->assertSame(312, (int) $result['orders_count']);
        $this->assertSame(260, (int) $result['required_target']);
        $this->assertSame(20.0, (float) $result['surplus_bonus'], 'once — not 52 orders × anything');
        $this->assertSame(280.0, (float) $result['gross_contract_earnings']);
        $this->assertSame('بونص مقطوع لتحقيق التارغت = 20 د.ك', collect($result['calculation_details'])->firstWhere('type', 'surplus')['formula']);
    }

    public function test_a_lump_sum_is_earned_by_reaching_the_target_exactly(): void
    {
        $contract = $this->contractWith(['fixed_bonus_type' => 'lump_sum', 'fixed_surplus_bonus' => '20.000', 'fixed_deficit_rate' => '0.250']);
        $this->logDays($contract, 26, 10);

        $this->assertSame(280.0, (float) $this->calculate($contract)['gross_contract_earnings']);
    }

    public function test_a_lump_sum_is_not_paid_below_the_target_and_the_deficit_still_is(): void
    {
        $contract = $this->contractWith(['fixed_bonus_type' => 'lump_sum', 'fixed_surplus_bonus' => '20.000', 'fixed_deficit_rate' => '0.250']);
        $this->logDays($contract, 26, 9);

        $result = $this->calculate($contract);

        $this->assertSame(0.0, (float) $result['surplus_bonus']);
        $this->assertSame(6.5, (float) $result['deficit_deduction'], '26 orders short × 0.250');
        $this->assertSame(253.5, (float) $result['gross_contract_earnings']);
    }

    public function test_a_per_order_bonus_is_paid_on_every_order_above_the_target(): void
    {
        $contract = $this->contractWith(['fixed_bonus_type' => 'per_order', 'fixed_surplus_rate' => '0.500', 'fixed_deficit_rate' => '0.250']);
        $this->logDays($contract, 26, 12);

        $result = $this->calculate($contract);

        $this->assertSame(26.0, (float) $result['surplus_bonus'], '52 orders × 0.500');
        $this->assertSame(286.0, (float) $result['gross_contract_earnings']);
    }

    /** A rule written before the choice existed keeps the reading it always had. */
    public function test_a_rule_naming_no_bonus_keeps_paying_per_order_at_the_deficit_rate(): void
    {
        $contract = $this->contractWith(['fixed_deficit_rate' => '0.250']);
        $this->logDays($contract, 26, 12);

        $this->assertSame(13.0, (float) $this->calculate($contract)['surplus_bonus'], '52 orders × 0.250');
    }

    /**
     * Keeta's contract as it stands: «بونص مقطوع 0.650» over a 0.750 deficit rate. It paid
     * 0.750 on every extra order; it now pays what it says.
     */
    public function test_the_lump_sum_written_on_the_form_is_what_is_paid(): void
    {
        $contract = $this->contractWith(['fixed_bonus_type' => 'lump_sum', 'fixed_surplus_bonus' => '.650', 'fixed_deficit_rate' => '.750', 'fixed_surplus_rate' => null]);
        $this->logDays($contract, 26, 12);

        $this->assertSame(0.65, (float) $this->calculate($contract)['surplus_bonus']);
    }

    public function test_a_drivers_own_override_pays_its_lump_sum_once(): void
    {
        $contract = $this->contractWith(['fixed_bonus_type' => 'per_order', 'fixed_surplus_rate' => '0.500']);
        $this->logDays($contract, 26, 12);

        $override = (new DriverContractOverride)->forceFill([
            'override_type' => 'fixed',
            'custom_pricing_rules' => [
                'fixed_amount' => 260, 'fixed_target' => 260, 'fixed_deficit_rate' => 0.25,
                'fixed_bonus_type' => 'lump_sum', 'fixed_surplus_bonus' => 15,
            ],
        ]);

        $result = $this->calculate($contract, $override);

        $this->assertSame(15.0, (float) $result['surplus_bonus']);
        $this->assertSame(275.0, (float) $result['gross_contract_earnings']);
    }

    /** An override that names no bonus — as every override did before the choice — is unchanged. */
    public function test_an_override_naming_no_bonus_keeps_paying_per_order(): void
    {
        $contract = $this->contractWith(['fixed_bonus_type' => 'lump_sum', 'fixed_surplus_bonus' => '20.000']);
        $this->logDays($contract, 26, 12);

        $override = (new DriverContractOverride)->forceFill([
            'override_type' => 'fixed',
            'custom_pricing_rules' => ['fixed_amount' => 260, 'fixed_target' => 260, 'fixed_deficit_rate' => 0.1],
        ]);

        $this->assertSame(5.2, (float) $this->calculate($contract, $override)['surplus_bonus'], '52 orders × 0.100');
    }

    /**
     * @param  array<string, mixed>  $bonus
     */
    private function contractWith(array $bonus): Contract
    {
        return Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-FB-'.uniqid(),
            'name' => 'Fixed Target Contract',
            'payment_type' => 'fixed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => 26,
            'driver_pricing_rules' => [
                2 => array_merge([
                    'vehicle_type_id' => '2',
                    'payment_method' => 'fixed',
                    'fixed_amount' => '260',
                    'fixed_target' => '260',
                ], $bonus),
            ],
        ]);
    }

    private function logDays(Contract $contract, int $days, int $ordersPerDay): void
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
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function calculate(Contract $contract, ?DriverContractOverride $override = null): array
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
            $override,
            DailyLog::where('contract_id', $contract->id)->get(),
            2
        );
    }
}
