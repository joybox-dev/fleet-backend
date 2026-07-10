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
use App\Models\CurrencyExchangeRate;
use App\Http\Controllers\Api\PayrollController;
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

        $this->company = Company::create(['name' => 'Test Fleet Company']);
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->admin);

        // Define company context
        app()->bind('current_company_id', fn() => $this->company->id);

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
    public function contract_assignment_validates_compatibility_and_duplicates()
    {
        // 1. Create a driver
        $driver = Employee::create([
            'name' => 'Compatible Driver',
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
    public function daily_log_validates_contract_vehicle_compatibility()
    {
        $driver = Employee::create([
            'name' => 'Driver Log compatibility',
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

    /** @test */
    public function mid_month_joiner_proration_calculation()
    {
        $driver = Employee::create([
            'name' => 'Mid Month Driver',
            'employee_number' => 'EMP201',
            'company_id' => $this->company->id,
            'employee_type' => 'driver',
            'status' => 'active',
            'pay_type' => 'fixed',
            'actual_salary' => 200.0,
        ]);

        // Monthly salary: 300 KWD, required valid days: 26, absence divisor: 26
        $contract = Contract::create([
            'company_id' => $this->company->id,
            'client_id' => 1,
            'contract_number' => 'CON-FIXED',
            'name' => 'Fixed Salary Contract',
            'client_name' => 'Client A',
            'status' => 'active',
            'payment_type' => 'fixed',
            'start_date' => '2026-06-01',
            'default_fixed_salary' => 300.000,
            'default_required_valid_days' => 26,
            'default_absence_divisor' => 26,
        ]);

        // Driver assigned starting mid-month (June 16th to June 30th -> exactly 15 days out of 30 days)
        // Ratio R = 15 / 30 = 0.5
        $assignment = ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $contract->id,
            'start_date' => '2026-06-16',
            'end_date' => '2026-06-30',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        // Record 10 valid daily logs (driver was absent for 3 days of the 13 prorated required valid days)
        for ($day = 16; $day <= 25; $day++) {
            DailyLog::create([
                'employee_id' => $driver->id,
                'vehicle_id' => 1,
                'contract_id' => $contract->id,
                'log_date' => "2026-06-{$day}",
                'orders_count' => 10,
                'orders_online' => 10,
                'orders_cash' => 0,
                'shift_valid' => true,
                'is_valid' => true,
                'created_by' => $this->admin->id,
                'company_id' => $this->company->id,
            ]);
        }

        // Run payroll calculation
        $allDailyLogs = collect([$driver->id => DailyLog::where('employee_id', $driver->id)->get()]);
        $allAssignments = collect([$assignment]);

        $slipData = PayrollController::calculateDriverSlipData(
            $driver,
            2026,
            6,
            '2026-06-01',
            '2026-06-30',
            $allDailyLogs,
            collect(),
            collect(),
            collect(),
            collect(),
            collect(),
            $allAssignments
        );

        // Calculations:
        // Prorated fixed salary = 300 * 0.5 = 150
        // Prorated required valid days = round(26 * 0.5) = 13
        // Worked valid days = 10
        // Absent days = 13 - 10 = 3
        // Absence deduction = 3 * (300 / 26) = 34.615 KWD
        // Expected base actual = 150 - 34.615 = 115.385 KWD
        
        $this->assertEquals(115.385, round($slipData['base_actual_salary'], 3));
        $this->assertEquals(34.615, round($slipData['total_absence_deduction'], 3));
    }

    /** @test */
    public function mid_month_vehicle_type_transition_segment_splitting()
    {
        $driver = Employee::create([
            'name' => 'Transition Driver',
            'employee_number' => 'EMP301',
            'company_id' => $this->company->id,
            'employee_type' => 'driver',
            'status' => 'active',
            'pay_type' => 'hybrid',
            'actual_salary' => 100.0,
        ]);

        // Create contracts with zone-tier pricing
        $bikePricingRules = [
            [
                'zone' => 'Hawally',
                'tiers' => [
                    ['min' => 1, 'max' => 10, 'price' => 0.500],
                    ['min' => 11, 'max' => 50, 'price' => 0.600],
                ]
            ]
        ];

        $carPricingRules = [
            [
                'zone' => 'Hawally',
                'tiers' => [
                    ['min' => 1, 'max' => 10, 'price' => 0.400],
                    ['min' => 11, 'max' => 50, 'price' => 0.450],
                ]
            ]
        ];

        $bikeContract = Contract::create([
            'company_id' => $this->company->id,
            'client_id' => 1,
            'contract_number' => 'CON-SEG-BIKE',
            'name' => 'Segment Bike Contract',
            'client_name' => 'Client A',
            'status' => 'active',
            'payment_type' => 'per_order',
            'driver_payment_method' => 'zones_tiers',
            'driver_pricing_rules' => $bikePricingRules,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'vehicle_type_id' => $this->bikeType->id,
        ]);

        $carContract = Contract::create([
            'company_id' => $this->company->id,
            'client_id' => 1,
            'contract_number' => 'CON-SEG-CAR',
            'name' => 'Segment Car Contract',
            'client_name' => 'Client A',
            'status' => 'active',
            'payment_type' => 'per_order',
            'driver_payment_method' => 'zones_tiers',
            'driver_pricing_rules' => $carPricingRules,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'vehicle_type_id' => $this->carType->id,
        ]);

        // Vehicle assignments
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

        // Days 1 to 10: assigned to bike
        VehicleAssignment::create([
            'vehicle_id' => $bike->id,
            'employee_id' => $driver->id,
            'assigned_date' => '2026-07-01',
            'unassigned_date' => '2026-07-10',
            'is_active' => false,
            'company_id' => $this->company->id,
        ]);

        // Days 11 to 31: assigned to car
        VehicleAssignment::create([
            'vehicle_id' => $car->id,
            'employee_id' => $driver->id,
            'assigned_date' => '2026-07-11',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        // Contract assignments
        $assign1 = ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $bikeContract->id,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-10',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        $assign2 = ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $carContract->id,
            'start_date' => '2026-07-11',
            'end_date' => '2026-07-31',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        // Daily logs:
        // Days 1 to 10 (10 days, ratio = 10 / 31):
        // Total orders = 12 in zone Hawally.
        // Hawally tiers boundaries scaled by 10/31:
        // Tier 1: min = 0, max = round(10 * 10/31) = 3
        // Tier 2: min = round(11 * 10/31) = 4, max = round(50 * 10/31) = 16
        // Reached Tier 2 (orders 12 >= 4). Rate = 0.600.
        // Payout = 12 * 0.600 = 7.200 KWD
        for ($day = 1; $day <= 10; $day++) {
            DailyLog::create([
                'employee_id' => $driver->id,
                'vehicle_id' => $bike->id,
                'contract_id' => $bikeContract->id,
                'log_date' => sprintf("2026-07-%02d", $day),
                'orders_count' => ($day === 1 ? 12 : 0),
                'orders_online' => ($day === 1 ? 12 : 0),
                'orders_cash' => 0,
                'zone' => 'Hawally',
                'created_by' => $this->admin->id,
                'company_id' => $this->company->id,
            ]);
        }

        // Days 11 to 31 (21 days, ratio = 21 / 31):
        // Total orders = 25 in zone Hawally.
        // Hawally tiers boundaries scaled by 21/31:
        // Tier 1: min = 0, max = round(10 * 21/31) = 7
        // Tier 2: min = round(11 * 21/31) = 7, max = round(50 * 21/31) = 34
        // Reached Tier 2 (orders 25 >= 7). Rate = 0.450.
        // Payout = 25 * 0.450 = 11.250 KWD
        for ($day = 11; $day <= 31; $day++) {
            DailyLog::create([
                'employee_id' => $driver->id,
                'vehicle_id' => $car->id,
                'contract_id' => $carContract->id,
                'log_date' => sprintf("2026-07-%02d", $day),
                'orders_count' => ($day === 11 ? 25 : 0),
                'orders_online' => ($day === 11 ? 25 : 0),
                'orders_cash' => 0,
                'zone' => 'Hawally',
                'created_by' => $this->admin->id,
                'company_id' => $this->company->id,
            ]);
        }

        // Run calculations
        $allDailyLogs = collect([$driver->id => DailyLog::where('employee_id', $driver->id)->get()]);
        $allAssignments = collect([$assign1, $assign2]);

        $slipData = PayrollController::calculateDriverSlipData(
            $driver,
            2026,
            7,
            '2026-07-01',
            '2026-07-31',
            $allDailyLogs,
            collect(),
            collect(),
            collect(),
            collect(),
            collect(),
            $allAssignments
        );

        // Expected base actual = 7.200 (segment 1) + 11.250 (segment 2) = 18.450 KWD
        $this->assertEquals(18.450, round($slipData['base_actual_salary'], 3));
    }
}
