<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\Violation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An expense, a repair or a fine recorded on a vehicle shows in the vehicle profitability report,
 * each kind in its own column: whatever the vehicle's status today, and a repair still waiting for
 * approval shown as waiting rather than charged.
 */
class VehicleProfitabilityReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_vehicle_that_carried_a_cost_is_listed_and_every_kind_has_its_column(): void
    {
        $company = Company::create([
            'name' => 'Fleet Co', 'code' => 'fleetco', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true,
        ]);
        app()->instance('current_company_id', $company->id);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@fleet.test', 'password' => bcrypt('password'),
            'role' => 'admin', 'company_id' => $company->id,
        ]);
        $driver = Employee::create([
            'name' => 'Driver', 'company_id' => $company->id, 'status' => 'active', 'role_category' => 'driver',
            'date_of_joining' => '2026-01-01', 'actual_salary' => 0, 'official_salary' => 100,
        ]);

        // Idle, never logged a day in May, and still spent on.
        $idle = Vehicle::create(['plate_number' => 'IDLE-1', 'make' => 'Nissan', 'status' => 'idle', 'company_id' => $company->id]);
        // Held by an authority with nothing recorded on it: nothing to report.
        Vehicle::create(['plate_number' => 'HELD-1', 'make' => 'Kia', 'status' => 'reserved', 'company_id' => $company->id]);

        VehicleExpense::create(['company_id' => $company->id, 'vehicle_id' => $idle->id, 'expense_type' => 'tires', 'amount' => 25, 'expense_date' => '2026-05-03']);
        VehicleExpense::create(['company_id' => $company->id, 'vehicle_id' => $idle->id, 'expense_type' => 'بنزين', 'amount' => 7.5, 'expense_date' => '2026-05-09']);
        MaintenanceRecord::create([
            'company_id' => $company->id, 'vehicle_id' => $idle->id, 'reported_by' => $admin->id, 'maintenance_type' => 'repair',
            'maintenance_date' => '2026-05-04', 'status' => 'pending', 'estimated_cost' => 60, 'is_driver_liable' => false,
        ]);
        MaintenanceRecord::create([
            'company_id' => $company->id, 'vehicle_id' => $idle->id, 'reported_by' => $admin->id, 'maintenance_type' => 'accident',
            'maintenance_date' => '2026-05-06', 'status' => 'approved', 'estimated_cost' => 80, 'actual_cost' => 80,
            'is_driver_liable' => true, 'liable_employee_id' => $driver->id, 'driver_deduction' => 30,
        ]);
        Violation::create([
            'company_id' => $company->id, 'employee_id' => $driver->id, 'vehicle_id' => $idle->id, 'created_by' => $admin->id,
            'violation_date' => '2026-05-05 12:00:00', 'violation_type' => 'parking', 'amount' => 10,
            'is_driver_liable' => false, 'driver_deduction' => 0,
        ]);
        // April's expense is April's.
        VehicleExpense::create(['company_id' => $company->id, 'vehicle_id' => $idle->id, 'expense_type' => 'tires', 'amount' => 99, 'expense_date' => '2026-04-30']);

        $report = $this->actingAs($admin)->getJson('/api/reports/vehicle-profitability?year=2026&month=5')->assertOk()->json();

        $plates = array_column($report['vehicles'], 'plate_number');
        $this->assertContains('IDLE-1', $plates);
        $this->assertNotContains('HELD-1', $plates);

        $row = collect($report['vehicles'])->firstWhere('plate_number', 'IDLE-1');
        $this->assertSame('idle', $row['status']);
        $this->assertEquals(['tires' => 25.0, 'بنزين' => 7.5], $row['expenses_by_type']);
        $this->assertEquals(32.5, $row['vehicle_expenses']);
        $this->assertEquals(50.0, $row['total_maintenance'], 'the approved repair less the driver\'s 30');
        $this->assertEquals(30.0, $row['maintenance_driver_share']);
        $this->assertEquals(60.0, $row['maintenance_pending'], 'waiting for approval: shown, not charged');
        $this->assertEquals(10.0, $row['total_violations']);
        $this->assertEquals(0.0, $row['violations_driver_share']);
        $this->assertEquals(92.5, $row['company_costs']);
        $this->assertEquals(-92.5, $row['net_profit']);

        $this->assertEquals([
            ['key' => 'tires', 'label' => 'إطارات', 'total' => 25.0],
            ['key' => 'بنزين', 'label' => 'بنزين', 'total' => 7.5],
        ], $report['expense_types']);
        $this->assertEquals(60.0, $report['totals']['maintenance_pending']);
        $this->assertEquals(92.5, $report['totals']['company_costs']);
    }
}
