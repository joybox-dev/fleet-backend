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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The contract payroll sheet used to pay for days the driver was not assigned, count soft-deleted
 * logs, price a whole month by the first log's vehicle type, and stretch a one-day override across
 * all 31 days. Each of those made the sheet disagree with the contract dashboard, which is how a
 * driver's pay could differ depending on which screen you opened.
 */
class ContractSheetScopingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Contract $contract;

    private Vehicle $car;

    private Vehicle $bike;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Sheet Scoping Co',
            'code' => 'scoping',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@scoping.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        $client = Client::create(['name' => 'C', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Scoped Driver',
            'employee_number' => 'EMP-SC-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-01-01',
        ]);

        $this->car = Vehicle::create([
            'plate_number' => 'V-SC-CAR',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);

        $this->bike = Vehicle::create([
            'plate_number' => 'V-SC-BIKE',
            'make' => 'Honda',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        // 1.000 KWD per order for the car, so every figure below reads as its order count.
        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-SC',
            'name' => 'Scoping Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => 30,
            'driver_pricing_rules' => [
                2 => [
                    'vehicle_type_id' => '2',
                    'payment_method' => 'tiers',
                    'tiers' => [['min' => '1', 'max' => null, 'price' => '1.000']],
                ],
                1 => [
                    'vehicle_type_id' => '1',
                    'payment_method' => 'tiers',
                    'tiers' => [['min' => '1', 'max' => null, 'price' => '5.000']],
                ],
            ],
        ]);

        $this->actingAs($this->user);
    }

    private function assign(string $start, ?string $end = null, string $status = 'active'): ContractAssignment
    {
        return ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'start_date' => $start,
            'end_date' => $end,
            'status' => $status,
            'company_id' => $this->company->id,
        ]);
    }

    private function logDay(string $date, int $orders, ?Vehicle $vehicle = null): DailyLog
    {
        return DailyLog::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'vehicle_id' => ($vehicle ?? $this->car)->id,
            'log_date' => $date,
            'driver_status' => 'working',
            'orders_count' => $orders,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function sheetRow(): array
    {
        $response = $this->getJson('/api/payroll/contract-sheet/'.$this->contract->id.'?year=2026&month=7')
            ->assertOk();

        return $response->json('drivers.0');
    }

    public function test_a_day_before_the_assignment_starts_is_excluded_and_named(): void
    {
        $this->assign('2026-07-10');
        $this->logDay('2026-07-05', 40);   // before the assignment
        $this->logDay('2026-07-12', 10);   // inside it

        $row = $this->sheetRow();

        $this->assertSame(10, (int) $row['orders_count'], 'only the assigned day counts');
        $this->assertSame(10.0, (float) $row['gross_contract_earnings']);
        $this->assertSame(40, (int) $row['out_of_window_orders'], 'and the excluded work is reported');
        $this->assertSame(['2026-07-05'], $row['out_of_window_dates']);
    }

    public function test_a_day_after_the_assignment_ends_is_excluded_too(): void
    {
        $this->assign('2026-07-01', '2026-07-15');
        $this->logDay('2026-07-10', 10);
        $this->logDay('2026-07-20', 25);

        $row = $this->sheetRow();

        $this->assertSame(10, (int) $row['orders_count']);
        $this->assertSame(25, (int) $row['out_of_window_orders']);
    }

    public function test_a_soft_deleted_log_is_not_paid(): void
    {
        $this->assign('2026-07-01');
        $this->logDay('2026-07-10', 10);
        $this->logDay('2026-07-11', 90)->delete();

        $row = $this->sheetRow();

        $this->assertSame(10, (int) $row['orders_count'], 'the deleted log must not reach the sheet');
        $this->assertSame(10.0, (float) $row['gross_contract_earnings']);
    }

    /**
     * Two vehicle types in one month has no single answer, so neither screen may invent one.
     */
    public function test_a_month_split_across_two_vehicle_types_is_flagged_not_guessed(): void
    {
        $this->assign('2026-07-01');
        $this->logDay('2026-07-02', 10, $this->bike);
        $this->logDay('2026-07-20', 10, $this->car);

        $row = $this->sheetRow();

        $this->assertTrue($row['vehicle_type_is_mixed'], 'the month used two types');
        $this->assertSame(0.0, (float) $row['gross_contract_earnings'], 'no rule is assumed');
        // Taking the first log's vehicle would have paid the whole month at the bike's 5.000.
        $this->assertNotSame(100.0, (float) $row['gross_contract_earnings']);
    }

    /**
     * An override covers the dates it declares. It used to reprice every day of any month it
     * merely touched.
     */
    public function test_an_override_prices_only_the_days_it_covers(): void
    {
        $assignment = $this->assign('2026-07-01');

        DriverContractOverride::create([
            'contract_assignment_id' => $assignment->id,
            'company_id' => $this->company->id,
            'override_type' => 'tiers',
            'customization_reason' => 'اختبار نطاق سريان الاستثناء',
            'effective_from' => '2026-07-01',
            'effective_to' => '2026-07-05',
            'custom_pricing_rules' => [
                'tiers' => [['min' => '1', 'max' => null, 'price' => '10.000']],
            ],
        ]);

        $this->logDay('2026-07-03', 2);   // inside the override  -> 2 × 10.000 = 20.000
        $this->logDay('2026-07-20', 7);   // outside it           -> 7 ×  1.000 =  7.000

        $row = $this->sheetRow();

        $this->assertSame(9, (int) $row['orders_count']);
        $this->assertSame(27.0, (float) $row['gross_contract_earnings'], '20.000 + 7.000');
        // The old behaviour applied the override to the whole month: 9 × 10.000 = 90.000.
        $this->assertNotSame(90.0, (float) $row['gross_contract_earnings']);
    }

    /**
     * An inactive assignment is reported, never silently unpaid: the driver did the work.
     */
    public function test_an_inactive_assignment_is_still_paid_but_marked(): void
    {
        $this->assign('2026-07-01', null, 'inactive');
        $this->logDay('2026-07-10', 12);

        $row = $this->sheetRow();

        $this->assertSame('inactive', $row['assignment_status']);
        $this->assertSame(12.0, (float) $row['gross_contract_earnings'], 'work done is work paid');
    }
}
