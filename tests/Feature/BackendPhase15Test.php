<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackendPhase15Test extends TestCase
{
    use RefreshDatabase;

    protected $company;

    protected $admin;

    protected $bikeType;

    protected $carType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Test Fleet Company',
            'subdomain' => 'test-fleet',
            'code' => 'TFC',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->admin);

        // Define company context
        app()->bind('current_company_id', fn () => $this->company->id);

        // Create Vehicle Types
        $this->bikeType = VehicleType::create([
            'company_id' => $this->company->id,
            'name' => 'Bike',
            'name_ar' => 'دراجة نارية',
        ]);

        $this->carType = VehicleType::create([
            'company_id' => $this->company->id,
            'name' => 'Car',
            'name_ar' => 'سيارة',
        ]);
    }

    /** @test */
    public function test_contract_assignment_validates_compatibility_and_duplicates()
    {
        // 1. Create a driver
        $driver = Employee::create([
            'name' => 'Compatible Driver',
            'date_of_joining' => '2026-07-01',
            'employee_number' => 'EMP101',
            'company_id' => $this->company->id,
            'employee_type' => 'driver',
            'status' => 'active',
            'pay_type' => 'fixed',
            'actual_salary' => 200.0,
        ]);

        // 2. Assign driver to a bike
        $bike = Vehicle::create([
            'plate_number' => 'BIKE-101',
            'company_id' => $this->company->id,
            'status' => 'available',
            'vehicle_type_id' => $this->bikeType->id,
        ]);

        VehicleAssignment::create([
            'vehicle_id' => $bike->id,
            'employee_id' => $driver->id,
            'assigned_date' => '2026-07-01',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        // 3. Create contracts
        $bikeContract = Contract::create([
            'company_id' => $this->company->id,
            'client_id' => 1,
            'contract_number' => 'CON-BIKE',
            'name' => 'Bike Contract',
            'client_name' => 'Client A',
            'status' => 'active',
            'payment_type' => 'fixed',
            'start_date' => '2026-07-01',
            'vehicle_type_id' => $this->bikeType->id,
        ]);

        $carContract = Contract::create([
            'company_id' => $this->company->id,
            'client_id' => 1,
            'contract_number' => 'CON-CAR',
            'name' => 'Car Contract',
            'client_name' => 'Client A',
            'status' => 'active',
            'payment_type' => 'fixed',
            'start_date' => '2026-07-01',
            'vehicle_type_id' => $this->carType->id,
        ]);

        // 4. Try to assign the bike-driver to the car contract (should fail)
        $response = $this->postJson('/api/contract-assignments', [
            'employee_id' => $driver->id,
            'contract_id' => $carContract->id,
            'start_date' => '2026-07-01',
            'status' => 'active',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('contract_id');
        $this->assertStringContainsString('نوع المركبة الحالية للسائق لا يتوافق', $response->json('message'));

        // 5. Assign to bike contract (should succeed)
        $response = $this->postJson('/api/contract-assignments', [
            'employee_id' => $driver->id,
            'contract_id' => $bikeContract->id,
            'start_date' => '2026-07-01',
            'status' => 'active',
        ]);

        $response->assertStatus(201);

        // 6. Try duplicate assignment on same contract (should fail)
        $response = $this->postJson('/api/contract-assignments', [
            'employee_id' => $driver->id,
            'contract_id' => $bikeContract->id,
            'start_date' => '2026-07-15',
            'status' => 'active',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('contract_id');
        $this->assertStringContainsString('السائق معين بالفعل', $response->json('message'));
    }

    /** @test */
    public function test_daily_log_validates_contract_vehicle_compatibility()
    {
        $driver = Employee::create([
            'name' => 'Driver Log compatibility',
            'date_of_joining' => '2026-07-01',
            'employee_number' => 'EMP102',
            'company_id' => $this->company->id,
            'employee_type' => 'driver',
            'status' => 'active',
            'pay_type' => 'fixed',
            'actual_salary' => 200.0,
        ]);

        $bike = Vehicle::create([
            'plate_number' => 'BIKE-102',
            'company_id' => $this->company->id,
            'status' => 'available',
            'vehicle_type_id' => $this->bikeType->id,
        ]);

        $car = Vehicle::create([
            'plate_number' => 'CAR-102',
            'company_id' => $this->company->id,
            'status' => 'available',
            'vehicle_type_id' => $this->carType->id,
        ]);

        $bikeContract = Contract::create([
            'company_id' => $this->company->id,
            'client_id' => 1,
            'contract_number' => 'CON-BIKE-2',
            'name' => 'Bike Contract 2',
            'client_name' => 'Client A',
            'status' => 'active',
            'payment_type' => 'fixed',
            'start_date' => '2026-07-01',
            'vehicle_type_id' => $this->bikeType->id,
        ]);

        ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $bikeContract->id,
            'start_date' => '2026-07-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        // Try to create daily log with bike contract but car vehicle (should fail)
        $response = $this->postJson('/api/daily-logs', [
            'employee_id' => $driver->id,
            'vehicle_id' => $car->id,
            'contract_id' => $bikeContract->id,
            'log_date' => '2026-07-01',
            'orders_count' => 10,
            'orders_online' => 5,
            'orders_cash' => 5,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('contract_id');
        $this->assertStringContainsString('فئة هذه المركبة غير مدعومة في هذا العقد', $response->json('message'));
    }

    /**
     * A driver who joins mid-month is paid for the days he worked, each at the contract's daily
     * rate — read from the contract payroll sheet, the one place that prices a month.
     *
     * @test
     */
    public function test_mid_month_joiner_is_paid_for_his_own_days()
    {
        $driver = Employee::create([
            'name' => 'Mid Month Driver',
            'date_of_joining' => '2026-06-16',
            'employee_number' => 'EMP201',
            'company_id' => $this->company->id,
            'employee_type' => 'driver',
            'role_category' => 'driver',
            'status' => 'active',
        ]);

        $bike = Vehicle::create([
            'plate_number' => 'BIKE-201',
            'company_id' => $this->company->id,
            'status' => 'available',
            'vehicle_type_id' => $this->bikeType->id,
        ]);

        // A 300.000 monthly salary over the contract's 26 working days.
        $contract = Contract::create([
            'company_id' => $this->company->id,
            'client_id' => 1,
            'contract_number' => 'CON-FIXED',
            'name' => 'Fixed Salary Contract',
            'client_name' => 'Client A',
            'status' => 'active',
            'start_date' => '2026-06-01',
            'default_required_work_days' => 26,
            'driver_payment_method' => 'fixed',
            'driver_pricing_rules' => [
                (string) $this->bikeType->id => ['payment_method' => 'fixed', 'fixed_amount' => 300],
            ],
        ]);

        // Assigned from the 16th, so the first half of the month is not his.
        ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $contract->id,
            'start_date' => '2026-06-16',
            'end_date' => '2026-06-30',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        foreach (range(16, 25) as $day) {
            DailyLog::create([
                'employee_id' => $driver->id,
                'vehicle_id' => $bike->id,
                'contract_id' => $contract->id,
                'log_date' => "2026-06-{$day}",
                'orders_count' => 10,
                'driver_status' => 'working',
                'created_by' => $this->admin->id,
                'company_id' => $this->company->id,
            ]);
        }

        $row = collect($this->getJson("/api/payroll/contract-sheet/{$contract->id}?year=2026&month=6")
            ->assertOk()
            ->json('drivers'))
            ->firstWhere('employee_id', $driver->id);

        // 10 paid days × (300 ÷ 26 = 11.538) = 115.385
        $this->assertSame(10, (int) $row['paid_days']);
        $this->assertSame(10, (int) $row['payable_days']);
        $this->assertEquals(115.385, round((float) $row['base_salary'], 3));
        $this->assertEquals(115.385, round((float) $row['gross_contract_earnings'], 3));
    }

    /**
     * A driver who moves from a bike to a car mid-month has two contracts, two vehicle types and
     * two prices. Each contract's sheet pays for the stretch on its own vehicle, and the driver's
     * statement — priced by the same routine — adds the two together.
     *
     * @test
     */
    public function test_mid_month_vehicle_type_transition_is_priced_per_stretch()
    {
        $driver = Employee::create([
            'name' => 'Transition Driver',
            'date_of_joining' => '2026-07-01',
            'employee_number' => 'EMP301',
            'company_id' => $this->company->id,
            'employee_type' => 'driver',
            'role_category' => 'driver',
            'status' => 'active',
        ]);

        $hawallyTiers = fn (float $low, float $high) => [
            [
                'id' => 'hawally',
                'name' => 'Hawally',
                'zone' => 'Hawally',
                'tiers' => [
                    ['min' => 1, 'max' => 10, 'price' => $low],
                    ['min' => 11, 'max' => 50, 'price' => $high],
                ],
            ],
        ];

        $bikeContract = Contract::create([
            'company_id' => $this->company->id,
            'client_id' => 1,
            'contract_number' => 'CON-SEG-BIKE',
            'name' => 'Segment Bike Contract',
            'client_name' => 'Client A',
            'status' => 'active',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'default_required_work_days' => 26,
            'driver_payment_method' => 'zones_tiers',
            'driver_pricing_rules' => [
                (string) $this->bikeType->id => ['payment_method' => 'zones_tiers', 'zones_tiers' => $hawallyTiers(0.500, 0.600)],
            ],
        ]);

        $carContract = Contract::create([
            'company_id' => $this->company->id,
            'client_id' => 1,
            'contract_number' => 'CON-SEG-CAR',
            'name' => 'Segment Car Contract',
            'client_name' => 'Client A',
            'status' => 'active',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'default_required_work_days' => 26,
            'driver_payment_method' => 'zones_tiers',
            'driver_pricing_rules' => [
                (string) $this->carType->id => ['payment_method' => 'zones_tiers', 'zones_tiers' => $hawallyTiers(0.400, 0.450)],
            ],
        ]);

        $bike = Vehicle::create([
            'plate_number' => 'BIKE-301',
            'company_id' => $this->company->id,
            'status' => 'available',
            'vehicle_type_id' => $this->bikeType->id,
        ]);

        $car = Vehicle::create([
            'plate_number' => 'CAR-301',
            'company_id' => $this->company->id,
            'status' => 'available',
            'vehicle_type_id' => $this->carType->id,
        ]);

        ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $bikeContract->id,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-10',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $carContract->id,
            'start_date' => '2026-07-11',
            'end_date' => '2026-07-31',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        // Days 1-10 on the bike: 12 orders in Hawally land in the 11-50 band at 0.600 = 7.200.
        foreach (range(1, 10) as $day) {
            DailyLog::create([
                'employee_id' => $driver->id,
                'vehicle_id' => $bike->id,
                'contract_id' => $bikeContract->id,
                'log_date' => sprintf('2026-07-%02d', $day),
                'orders_count' => $day === 1 ? 12 : 0,
                'driver_status' => 'working',
                'zone' => 'Hawally',
                'created_by' => $this->admin->id,
                'company_id' => $this->company->id,
            ]);
        }

        // Days 11-31 on the car: 25 orders in Hawally land in the 11-50 band at 0.450 = 11.250.
        foreach (range(11, 31) as $day) {
            DailyLog::create([
                'employee_id' => $driver->id,
                'vehicle_id' => $car->id,
                'contract_id' => $carContract->id,
                'log_date' => sprintf('2026-07-%02d', $day),
                'orders_count' => $day === 11 ? 25 : 0,
                'driver_status' => 'working',
                'zone' => 'Hawally',
                'created_by' => $this->admin->id,
                'company_id' => $this->company->id,
            ]);
        }

        $rowOn = fn (Contract $contract) => collect(
            $this->getJson("/api/payroll/contract-sheet/{$contract->id}?year=2026&month=7")->assertOk()->json('drivers')
        )->firstWhere('employee_id', $driver->id);

        $this->assertEquals(7.200, round((float) $rowOn($bikeContract)['gross_contract_earnings'], 3));
        $this->assertEquals(11.250, round((float) $rowOn($carContract)['gross_contract_earnings'], 3));

        // The driver's own statement is priced by the same routine, so it reads the same 18.450.
        $history = $this->getJson("/api/employees/{$driver->id}/history?from=2026-07&to=2026-07")->assertOk()->json();
        $this->assertEquals(18.450, round((float) $history['totals']['gross_earnings'], 3));
    }
}
