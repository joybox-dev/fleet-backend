<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every daily rate in the system divides a monthly figure by the contract's working days, and the
 * number was being invented in four different places: payroll divided by 28, the contract form
 * opened pre-filled with 26 and saved it as though someone had chosen it, the contract list printed
 * 30, and an unpaid leave day was priced at salary ÷ 30 whatever the contract said.
 *
 * The field was blank on 15 of the owner's 18 contracts, so on most of them the hidden number was
 * the one actually paying people: a 280.000 driver came out at 260.000 or 280.000 depending on
 * whether anyone had happened to open the edit form.
 *
 * It is now required on the contract, and a month on a contract without it is refused rather than
 * priced against a number nobody chose.
 */
class WorkingDaysAreNeverInventedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Client $client;

    private Employee $driver;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Working Days Co',
            'code' => 'wdays',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@wdays.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        $this->client = Client::create(['name' => 'Working Days Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Working Days Driver',
            'employee_number' => 'EMP-WD-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 260.000,
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-WD-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);

        $this->actingAs($this->user);
    }

    private function contract(?int $workingDays): Contract
    {
        return Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-WD-'.($workingDays ?? 'none'),
            'name' => 'عقد أيام العمل',
            'payment_type' => 'fixed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed',
            'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => $workingDays,
            'is_validity_enabled' => false,
            'client_pricing_rules' => ['2' => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_pricing_rules' => ['2' => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
        ]);
    }

    private function workTenDays(Contract $contract): void
    {
        ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $contract->id,
            'start_date' => '2026-03-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        foreach (range(2, 11) as $day) {
            DailyLog::create([
                'employee_id' => $this->driver->id,
                'contract_id' => $contract->id,
                'vehicle_id' => $this->vehicle->id,
                'log_date' => sprintf('2026-03-%02d', $day),
                'driver_status' => 'working',
                'orders_count' => 10,
                'company_id' => $this->company->id,
                'created_by' => $this->user->id,
            ]);
        }
    }

    private function row(Contract $contract): array
    {
        $sheet = $this->getJson("/api/payroll/contract-sheet/{$contract->id}?year=2026&month=3")
            ->assertOk()->json();

        return $sheet['drivers'][0];
    }

    public function test_a_contract_that_names_its_working_days_is_priced_by_them(): void
    {
        $contract = $this->contract(26);
        $this->workTenDays($contract);

        // 260.000 ÷ 26 = 10.000 a day, ten days worked.
        $this->assertSame(100.000, round((float) $this->row($contract)['gross_contract_earnings'], 3));
    }

    public function test_a_different_number_of_working_days_gives_a_different_salary(): void
    {
        $contract = $this->contract(20);
        $this->workTenDays($contract);

        // 260.000 ÷ 20 = 13.000 a day. The divisor is not decoration.
        $this->assertSame(130.000, round((float) $this->row($contract)['gross_contract_earnings'], 3));
    }

    public function test_a_contract_without_working_days_is_refused_not_priced_at_28(): void
    {
        $contract = $this->contract(null);
        $this->workTenDays($contract);

        $row = $this->row($contract);

        // The old fallback of 28 would have paid 260.000 ÷ 28 × 10 = 92.857.
        $this->assertSame(0.000, round((float) $row['gross_contract_earnings'], 3));
        $this->assertNotSame(92.857, round((float) $row['gross_contract_earnings'], 3));
        $this->assertSame(100, (int) $row['orders_count'], 'the work is still counted and reported');

        $labels = collect($row['calculation_details'] ?? [])->pluck('label')->implode(' | ');
        $this->assertStringContainsString('أيام العمل المطلوبة غير محددة', $labels, 'and the row says exactly why');
    }

    public function test_the_contract_form_refuses_to_save_without_working_days(): void
    {
        $this->postJson('/api/contracts', [
            'client_id' => $this->client->id,
            'contract_number' => 'CON-WD-BLANK',
            'name' => 'عقد بلا أيام عمل',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed',
            'driver_payment_method' => 'fixed',
        ])->assertStatus(422)->assertJsonValidationErrors('default_required_work_days');
    }

    public function test_an_unpaid_leave_day_costs_what_a_worked_day_pays(): void
    {
        $contract = $this->contract(26);
        ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $contract->id,
            'start_date' => '2026-03-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        $type = LeaveType::create([
            'company_id' => $this->company->id,
            'name' => 'Unpaid Leave',
            'name_ar' => 'إجازة بدون راتب',
            'is_paid' => false,
            'is_active' => true,
            'requires_approval' => false,
            'penalty_multiplier' => 1,
        ]);

        $this->postJson('/api/leaves', [
            'employee_id' => $this->driver->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-03-16',
            'end_date' => '2026-03-17',
        ])->assertStatus(201);

        $leave = EmployeeLeave::withoutGlobalScopes()->latest('id')->first();

        // 260.000 ÷ 26 = 10.000 a day — the same rate the contract pays him for working it.
        // A flat 30 would have made the day 8.667 and the two days 17.333.
        $this->assertSame(10.000, round((float) $leave->daily_rate, 3));
        $this->assertSame(20.000, round((float) $leave->total_deduction, 3));
    }
}
