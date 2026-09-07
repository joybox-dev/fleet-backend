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
use App\Models\VehicleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The contract grid reads a driver's vehicle type off the month's daily logs, and rightly so: a
 * month must not be repriced because an assignment was closed afterwards. But a driver with NO log
 * that month has no month to protect, and he read «غير محدد» although a vehicle had been assigned
 * to him the whole time — which also left the grid unable to find his pricing rule or his zone
 * columns when the operator opened the day to enter his first order.
 *
 * The dashboard now also reports the type each driver HELD DURING THE MONTH, from the vehicle
 * assignment whose dates covered it. One of the three drivers this was reported for had his
 * assignment closed since, so «assigned right now» would still have shown him as unknown.
 */
class AnIdleDriversVehicleTypeComesFromHisAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Contract $contract;

    private Vehicle $smallCar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Idle Co',
            'code' => 'idleco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Idle Admin',
            'email' => 'admin@idle.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        \DB::table('vehicle_types')->updateOrInsert(['id' => 2], [
            'company_id' => $this->company->id,
            'name' => 'Small Car',
            'name_ar' => 'سيارة صغيرة',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $client = Client::create(['name' => 'Idle Client', 'company_id' => $this->company->id]);

        $this->smallCar = Vehicle::create([
            'plate_number' => '23/45500',
            'make' => 'Yaris',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-IDLE',
            'name' => 'عقد',
            'payment_type' => 'per_order',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => 26,
            'is_validity_enabled' => false,
            'driver_pricing_rules' => ['2' => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
            'client_pricing_rules' => ['2' => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
        ]);

        $this->actingAs($this->user);
    }

    private function hire(string $name): Employee
    {
        $driver = Employee::create([
            'name' => $name,
            'employee_number' => 'EMP-'.substr(md5($name), 0, 6),
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $this->contract->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        return $driver;
    }

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        return $this->getJson("/api/contracts/{$this->contract->id}/dashboard?year=2026&month=8")
            ->assertOk()
            ->json();
    }

    public function test_a_driver_with_no_logged_day_still_reports_the_type_he_was_assigned(): void
    {
        $driver = $this->hire('محمد بلا سجلات');
        VehicleAssignment::create([
            'company_id' => $this->company->id,
            'vehicle_id' => $this->smallCar->id,
            'employee_id' => $driver->id,
            'contract_id' => $this->contract->id,
            'assigned_date' => '2026-07-29',
            'is_active' => true,
        ]);

        $this->assertSame([2], $this->dashboard()['vehicle_types_by_employee'][$driver->id]);
    }

    public function test_an_assignment_closed_after_the_month_still_answers_for_it(): void
    {
        // Assigned through August, unassigned in September — «active right now» is false, and the
        // question the grid is asking is about August.
        $driver = $this->hire('منصور انتهى تعيينه');
        VehicleAssignment::create([
            'company_id' => $this->company->id,
            'vehicle_id' => $this->smallCar->id,
            'employee_id' => $driver->id,
            'contract_id' => $this->contract->id,
            'assigned_date' => '2026-08-02',
            'unassigned_date' => '2026-09-03',
            'is_active' => false,
        ]);

        $this->assertSame([2], $this->dashboard()['vehicle_types_by_employee'][$driver->id]);
    }

    public function test_an_assignment_that_never_touched_the_month_does_not_answer_for_it(): void
    {
        $driver = $this->hire('سائق تعيينه انتهى قبل الشهر');
        VehicleAssignment::create([
            'company_id' => $this->company->id,
            'vehicle_id' => $this->smallCar->id,
            'employee_id' => $driver->id,
            'contract_id' => $this->contract->id,
            'assigned_date' => '2026-05-01',
            'unassigned_date' => '2026-06-30',
            'is_active' => false,
        ]);

        $this->assertArrayNotHasKey($driver->id, $this->dashboard()['vehicle_types_by_employee']);
    }

    public function test_no_figure_of_pay_moves_with_it(): void
    {
        $driver = $this->hire('سائق بلا عمل');
        VehicleAssignment::create([
            'company_id' => $this->company->id,
            'vehicle_id' => $this->smallCar->id,
            'employee_id' => $driver->id,
            'contract_id' => $this->contract->id,
            'assigned_date' => '2026-07-29',
            'is_active' => true,
        ]);

        // He is on the sheet, and he is paid nothing — an idle month has no days and no orders,
        // whichever vehicle type the screen now manages to name for him.
        $row = collect($this->getJson("/api/payroll/contract-sheet/{$this->contract->id}?year=2026&month=8")
            ->assertOk()->json('drivers'))->firstWhere('employee_id', $driver->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(0.0, (float) $row['gross_contract_earnings'], 0.0005);
        $this->assertSame(0, (int) $row['orders_count']);
    }

    public function test_a_month_that_was_worked_is_still_read_from_its_own_logs(): void
    {
        $bike = Vehicle::create([
            'plate_number' => '4/2851',
            'make' => 'Honda',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $driver = $this->hire('سائق اشتغل على سيكل');
        // He worked August on the BIKE, and has since been assigned the small car. The month must
        // answer with the bike — that is the invariant the log-based reading exists to protect.
        DailyLog::create([
            'employee_id' => $driver->id,
            'contract_id' => $this->contract->id,
            'vehicle_id' => $bike->id,
            'log_date' => '2026-08-05',
            'driver_status' => 'working',
            'orders_count' => 10,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);
        VehicleAssignment::create([
            'company_id' => $this->company->id,
            'vehicle_id' => $this->smallCar->id,
            'employee_id' => $driver->id,
            'contract_id' => $this->contract->id,
            'assigned_date' => '2026-08-01',
            'is_active' => true,
        ]);

        $logVehicle = DailyLog::where('employee_id', $driver->id)->first()->vehicle_id;
        $this->assertSame($bike->id, $logVehicle, 'the month keeps the vehicle it was worked on');
    }
}
