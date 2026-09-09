<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\Violation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The contract's own dashboard, the main dashboard and the two profitability reports each used
 * to price the month their own way. On the client's data, contract «مركز سلطان» in 9/2026 read a
 * profit of −85.500 on one screen and +277.000 on the next, and «آرض الطبيعة» −57.679 against
 * +179.821 with identical revenue and driver cost. They all read ContractProfitabilityService
 * now; this pins that the four screens say the same thing about the same month.
 */
class ProfitabilityScreensAgreeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Contract $contract;

    private Employee $driver;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Agree Co',
            'code' => 'agreeco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@agree.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);
        $this->actingAs($this->admin);

        $client = Client::create(['name' => 'Agree Client', 'company_id' => $this->company->id]);
        $type = VehicleType::create(['company_id' => $this->company->id, 'name' => 'Car', 'name_ar' => 'سيارة']);

        // The client pays a flat 600.000 for the type; the driver is paid 260.000 over 26 days.
        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-AGREE',
            'name' => 'عقد الاتفاق',
            'status' => 'active',
            'is_active' => true,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => 26,
            'client_payment_method' => 'fixed',
            'client_pricing_rules' => [(string) $type->id => ['payment_method' => 'fixed', 'fixed_amount' => 600]],
            'driver_payment_method' => 'fixed',
            'driver_pricing_rules' => [(string) $type->id => ['payment_method' => 'fixed', 'fixed_amount' => 260]],
        ]);

        $this->driver = Employee::create([
            'name' => 'سائق الاتفاق',
            'employee_number' => 'EMP-AGREE',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-AGREE',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => $type->id,
        ]);

        ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        // Ten working days of ten orders: 10 × (260 ÷ 26) = 100.000 of pay.
        foreach (range(1, 10) as $day) {
            DailyLog::create([
                'employee_id' => $this->driver->id,
                'contract_id' => $this->contract->id,
                'vehicle_id' => $this->vehicle->id,
                'log_date' => sprintf('2026-05-%02d', $day),
                'orders_count' => 10,
                'driver_status' => 'working',
                'company_id' => $this->company->id,
                'created_by' => $this->admin->id,
            ]);
        }

        // A repair the company paid for, on a day the vehicle worked this contract.
        MaintenanceRecord::create([
            'company_id' => $this->company->id,
            'vehicle_id' => $this->vehicle->id,
            'reported_by' => $this->admin->id,
            'maintenance_type' => 'repair',
            'maintenance_date' => '2026-05-05',
            'status' => 'approved',
            'estimated_cost' => 40,
            'actual_cost' => 40,
            'is_driver_liable' => false,
        ]);

        // A fine of 20.000 of which the driver bears 15.000: only the 5.000 is the contract's cost.
        Violation::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'created_by' => $this->admin->id,
            'violation_date' => '2026-05-06',
            'violation_type' => 'speeding',
            'amount' => 20,
            'is_driver_liable' => true,
            'driver_share' => 15,
            'contract_share' => 5,
            'driver_deduction' => 15,
        ]);
    }

    public function test_the_four_screens_report_the_same_month(): void
    {
        $sheet = $this->getJson("/api/payroll/contract-sheet/{$this->contract->id}?year=2026&month=5")->assertOk()->json();
        $contractDashboard = $this->getJson("/api/contracts/{$this->contract->id}/dashboard?year=2026&month=5")->assertOk()->json();
        $mainDashboard = collect($this->getJson('/api/dashboard/contracts-profitability?period=monthly&year=2026&month=5')->assertOk()->json('contracts'))
            ->firstWhere('id', $this->contract->id);
        $report = collect($this->getJson('/api/reports/contract-profitability?year=2026&month=5')->assertOk()->json('contracts'))
            ->firstWhere('contract_id', $this->contract->id);
        $vehicleReport = $this->getJson('/api/reports/vehicle-profitability?year=2026&month=5')->assertOk()->json();

        // Revenue: the client's flat 600.000.
        $this->assertEquals(600.0, $contractDashboard['financials']['actual']['revenue']);
        $this->assertEquals(600.0, $mainDashboard['actual_revenue']);
        $this->assertEquals(600.0, $report['revenue']);

        // Driver cost: the payroll sheet's own figure, on every screen.
        $this->assertEquals(100.0, $sheet['summary']['total_gross_earnings']);
        $this->assertEquals(100.0, $contractDashboard['direct_expenses']['driver_salaries'] + $contractDashboard['direct_expenses']['driver_commissions']);
        $this->assertEquals(100.0, $mainDashboard['driver_cost']);
        $this->assertEquals(100.0, $report['driver_cost']);

        // The company's share of the repair and of the fine, charged where the vehicle worked.
        $this->assertEquals(40.0, $contractDashboard['direct_expenses']['accidents_cost']);
        $this->assertEquals(40.0, $mainDashboard['maintenance_cost']);
        $this->assertEquals(40.0, $report['maintenance_cost']);
        $this->assertEquals(5.0, $contractDashboard['direct_expenses']['violations_cost']);
        $this->assertEquals(5.0, $mainDashboard['violations_cost']);
        $this->assertEquals(5.0, $report['violations_cost']);

        // And so one profit: 600 − 100 − 40 − 5.
        $this->assertEquals(455.0, $contractDashboard['financials']['actual']['profit']);
        $this->assertEquals(455.0, $mainDashboard['actual_profit']);
        $this->assertEquals(455.0, $report['net_profit']);

        // The vehicle carried all of it, so its row is the contract's row.
        $row = collect($vehicleReport['vehicles'])->firstWhere('vehicle_id', $this->vehicle->id);
        $this->assertEquals(600.0, $row['revenue']);
        $this->assertEquals(100.0, $row['driver_cost']);
        $this->assertEquals(40.0, $row['total_maintenance']);
        $this->assertEquals(5.0, $row['total_violations']);
        $this->assertEquals(455.0, $row['net_profit']);
        $this->assertEquals(455.0, $vehicleReport['totals']['net_profit']);
    }

    public function test_the_contract_dashboard_lists_the_sheets_drivers_and_what_they_bill(): void
    {
        $dashboard = $this->getJson("/api/contracts/{$this->contract->id}/dashboard?year=2026&month=5")->assertOk()->json();

        $row = collect($dashboard['drivers'])->firstWhere('employee_id', $this->driver->id);

        $this->assertNotNull($row, 'the drivers on the screen are the payroll sheet rows');
        $this->assertEquals(100.0, $row['gross_contract_earnings']);
        $this->assertSame(10, (int) $row['paid_days']);
        $this->assertSame(100, (int) $row['orders_count']);
        $this->assertEquals(600.0, $row['client_revenue']);
        $this->assertFalse($dashboard['sheet']['is_approved']);
    }
}
