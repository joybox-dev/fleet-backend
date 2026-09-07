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
 * A fixed salary is paid over the contract's own working days and capped there, so a 31-day month
 * against a 28-day contract pays 28 days and not 110% of the salary.
 *
 * A month split across two vehicle types is priced one stretch at a time, and each stretch applied
 * that cap to ITSELF. Twenty days on one vehicle and fifteen on another are each under 28, so both
 * passed it whole and the month paid THIRTY-FIVE days of a salary the contract pays 28 for. The cap
 * is monthly; splitting a month must not enlarge it.
 */
class TheMonthlyDayCapSurvivesAVehicleChangeTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT_DAYS = 28;

    private const SALARY = 280.000;

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
            'name' => 'Cap Co',
            'code' => 'capco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Cap Admin',
            'email' => 'admin@cap.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        foreach ([[2, 'Small Car', 'سيارة صغيرة'], [3, 'Large Car', 'سيارة كبيرة']] as [$id, $name, $nameAr]) {
            \DB::table('vehicle_types')->updateOrInsert(['id' => $id], [
                'company_id' => $this->company->id,
                'name' => $name,
                'name_ar' => $nameAr,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $client = Client::create(['name' => 'Cap Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Cap Driver',
            'employee_number' => 'EMP-CAP-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->smallCar = Vehicle::create([
            'plate_number' => 'CAP-SMALL',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);

        $this->largeCar = Vehicle::create([
            'plate_number' => 'CAP-LARGE',
            'make' => 'Nissan',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 3,
        ]);

        // Both types pay the same fixed salary, so the only thing under test is the day count.
        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-CAP',
            'name' => 'عقد السقف',
            'payment_type' => 'per_order',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => self::CONTRACT_DAYS,
            'is_validity_enabled' => false,
            'driver_pricing_rules' => [
                '2' => ['payment_method' => 'fixed', 'fixed_amount' => self::SALARY, 'fixed_target' => 0],
                '3' => ['payment_method' => 'fixed', 'fixed_amount' => self::SALARY, 'fixed_target' => 0],
            ],
            'client_pricing_rules' => [
                '2' => ['payment_method' => 'fixed', 'fixed_amount' => 900],
                '3' => ['payment_method' => 'fixed', 'fixed_amount' => 900],
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

    /** @param int[] $days */
    private function work(Vehicle $vehicle, array $days): void
    {
        foreach ($days as $day) {
            DailyLog::create([
                'employee_id' => $this->driver->id,
                'contract_id' => $this->contract->id,
                'vehicle_id' => $vehicle->id,
                'log_date' => sprintf('2026-08-%02d', $day),
                'driver_status' => 'working',
                'orders_count' => 5,
                'company_id' => $this->company->id,
                'created_by' => $this->user->id,
            ]);
        }
    }

    private function gross(): float
    {
        $row = collect($this->getJson("/api/payroll/contract-sheet/{$this->contract->id}?year=2026&month=8")
            ->assertOk()->json('drivers'))->firstWhere('employee_id', $this->driver->id);

        $this->assertNotNull($row, 'driver missing from the contract sheet');

        return round((float) $row['gross_contract_earnings'], 3);
    }

    public function test_a_month_split_over_two_vehicles_is_still_capped_at_the_contracts_days(): void
    {
        // Twenty days on the large car, then eleven on the small: thirty-one paid days in a month
        // the contract pays twenty-eight of. Each stretch alone is under the cap.
        $this->work($this->largeCar, range(1, 20));
        $this->work($this->smallCar, range(21, 31));

        $this->assertSame(
            round(self::SALARY, 3),
            $this->gross(),
            'a 31-day month may not pay more than the contract\'s own 28 days, split or not'
        );
    }

    public function test_a_month_under_the_cap_is_untouched_by_the_split(): void
    {
        // Four days then eighteen — twenty-two paid days, the shape of محمد الحسن on آرض الطبيعة.
        $this->work($this->smallCar, range(1, 4));
        $this->work($this->largeCar, range(5, 22));

        $daily = self::SALARY / self::CONTRACT_DAYS;

        $this->assertSame(round(22 * $daily, 3), $this->gross());
    }

    public function test_one_vehicle_all_month_is_capped_as_it_always_was(): void
    {
        $this->work($this->smallCar, range(1, 31));

        $this->assertSame(round(self::SALARY, 3), $this->gross());
    }
}
