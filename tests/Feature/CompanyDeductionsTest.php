<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ConsolidatedPayrollDeduction;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\CustodyItem;
use App\Models\DailyLog;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The contract payroll path replaces the legacy PayrollRun, so it has to collect everything
 * the legacy path did: driver-liable maintenance, damaged or lost custody, driver-borne
 * expenses and unpaid leave — on top of fines and advance instalments.
 *
 * Maintenance and custody carry no `is_deducted` column of their own, which is why the legacy
 * path re-collected them every single month. The deduction ledger is what stops that here.
 */
class CompanyDeductionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Vehicle $vehicle;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Deduction Sources Co',
            'code' => 'dedsrc',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@dedsrc.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'C', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Deduction Driver',
            'employee_number' => 'EMP-DS-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-DS-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-DS',
            'name' => 'Deduction Sources Contract',
            'payment_type' => 'fixed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed',
            'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 2600, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);

        ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        // 3 worked days at 2600/26 = 100.000 per day => 300.000 gross.
        foreach (['2026-03-02', '2026-03-03', '2026-03-04'] as $date) {
            DailyLog::create([
                'employee_id' => $this->driver->id,
                'contract_id' => $this->contract->id,
                'vehicle_id' => $this->vehicle->id,
                'log_date' => $date,
                'driver_status' => 'working',
                'orders_count' => 0,
                'company_id' => $this->company->id,
                'created_by' => $this->user->id,
            ]);
        }

        $this->actingAs($this->user);
    }

    private function sheet(int $year = 2026, int $month = 3): array
    {
        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", compact('year', 'month'))->assertOk();

        return $this->getJson("/api/payroll/consolidated/{$year}/{$month}")->assertOk()->json();
    }

    private function driverRow(array $sheet): array
    {
        foreach ($sheet['drivers'] as $row) {
            if ((int) $row['employee_id'] === $this->driver->id) {
                return $row;
            }
        }
        $this->fail('driver missing');
    }

    private function addMaintenance(float $driverDeduction, string $date = '2026-02-15'): MaintenanceRecord
    {
        return MaintenanceRecord::create([
            'vehicle_id' => $this->vehicle->id,
            'maintenance_type' => 'brakes',
            'maintenance_date' => $date,
            'status' => 'approved',
            'is_driver_liable' => true,
            'liable_employee_id' => $this->driver->id,
            'driver_deduction' => $driverDeduction,
            'reported_by' => $this->user->id,
            'company_id' => $this->company->id,
        ]);
    }

    private function addCustody(float $deduction): CustodyItem
    {
        return CustodyItem::create([
            'employee_id' => $this->driver->id,
            'item_type' => 'device',
            'item_description' => 'جهاز تتبع',
            'value' => 60.000,
            'issued_date' => '2026-01-05',
            'returned_date' => '2026-03-05',
            'is_returned' => true,
            'status' => 'returned',
            'return_condition' => 'damaged',
            'deduction_amount' => $deduction,
            'issued_by' => $this->user->id,
            'company_id' => $this->company->id,
        ]);
    }

    private function addDriverExpense(float $driverAmount): DriverExpense
    {
        return DriverExpense::create([
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'expense_type' => 'fuel',
            'amount' => $driverAmount,
            'driver_amount' => $driverAmount,
            'company_amount' => 0,
            'borne_by' => 'driver',
            'expense_date' => '2026-03-08',
            'is_deducted' => false,
            'company_id' => $this->company->id,
        ]);
    }

    private function addUnpaidLeave(float $deduction): EmployeeLeave
    {
        $type = \App\Models\LeaveType::firstOrCreate(
            ['company_id' => $this->company->id, 'name' => 'Unpaid Leave'],
            ['name_ar' => 'إجازة بدون راتب', 'is_paid' => false]
        );

        return EmployeeLeave::create([
            'employee_id' => $this->driver->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-22',
            'days_count' => 3,
            'status' => 'approved',
            'is_paid' => false,
            'total_deduction' => $deduction,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_all_four_new_sources_are_reported_as_pending_and_not_charged(): void
    {
        $this->addMaintenance(40.000);
        $this->addCustody(25.000);
        $this->addDriverExpense(12.000);
        $this->addUnpaidLeave(30.000);

        $row = $this->driverRow($this->sheet());

        $this->assertSame(40.0, (float) $row['pending_maintenance_deduction']);
        $this->assertSame(25.0, (float) $row['pending_custody_deduction']);
        $this->assertSame(12.0, (float) $row['pending_driver_expenses_deduction']);
        $this->assertSame(30.0, (float) $row['pending_leaves_deduction']);
        $this->assertSame(107.0, (float) $row['pending_deductions_total']);

        // Nothing charged before approval.
        $this->assertSame(0.0, (float) $row['maintenance_deduction']);
        $this->assertSame(0.0, (float) $row['custody_deduction']);
        $this->assertSame(0.0, (float) $row['deductions_total']);
        $this->assertSame(300.0, (float) $row['final_net_payout']);
    }

    public function test_approval_charges_all_four_and_records_them_in_the_ledger(): void
    {
        $this->addMaintenance(40.000);
        $this->addCustody(25.000);
        $this->addDriverExpense(12.000);
        $this->addUnpaidLeave(30.000);

        $this->sheet();
        $this->postJson('/api/payroll/consolidated/2026/3/approve')->assertOk();

        $row = $this->driverRow($this->getJson('/api/payroll/consolidated/2026/3')->assertOk()->json());

        $this->assertSame(107.0, (float) $row['deductions_total']);
        $this->assertSame(193.0, (float) $row['final_net_payout'], '300 − 107');

        $this->assertSame(1, ConsolidatedPayrollDeduction::where('source_type', 'maintenance')->count());
        $this->assertSame(1, ConsolidatedPayrollDeduction::where('source_type', 'custody')->count());
        $this->assertSame(1, ConsolidatedPayrollDeduction::where('source_type', 'leave')->count());
        $this->assertSame(1, ConsolidatedPayrollDeduction::where('source_type', 'driver_expense')->count());
    }

    /**
     * The defect this ledger exists to prevent: maintenance and custody have no flag of their
     * own, so without it a February repair is re-collected in March, April and every month after.
     */
    public function test_maintenance_and_custody_are_not_collected_again_the_following_month(): void
    {
        $this->addMaintenance(40.000);
        $this->addCustody(25.000);

        $this->sheet(2026, 3);
        $this->postJson('/api/payroll/consolidated/2026/3/approve')->assertOk();

        // A worked day in April so the driver appears on that month's sheet too.
        DailyLog::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'vehicle_id' => $this->vehicle->id,
            'log_date' => '2026-04-02',
            'driver_status' => 'working',
            'orders_count' => 0,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);

        $april = $this->driverRow($this->sheet(2026, 4));

        $this->assertSame(0.0, (float) $april['pending_maintenance_deduction'], 'already collected in March');
        $this->assertSame(0.0, (float) $april['pending_custody_deduction'], 'already collected in March');
        $this->assertSame(100.0, (float) $april['final_net_payout'], 'one worked day, nothing re-charged');
    }

    public function test_unapproving_makes_every_source_outstanding_again(): void
    {
        $this->addMaintenance(40.000);
        $this->addCustody(25.000);
        $expense = $this->addDriverExpense(12.000);

        $this->sheet();
        $this->postJson('/api/payroll/consolidated/2026/3/approve')->assertOk();
        $this->assertTrue((bool) $expense->fresh()->is_deducted);

        $this->postJson('/api/payroll/consolidated/2026/3/unapprove')->assertOk();

        $this->assertSame(0, ConsolidatedPayrollDeduction::count(), 'ledger cleared by cascade');
        $this->assertFalse((bool) $expense->fresh()->is_deducted);

        $row = $this->driverRow($this->getJson('/api/payroll/consolidated/2026/3')->assertOk()->json());
        $this->assertSame(77.0, (float) $row['pending_deductions_total'], 'all outstanding again');
        $this->assertSame(300.0, (float) $row['final_net_payout']);
    }
}
