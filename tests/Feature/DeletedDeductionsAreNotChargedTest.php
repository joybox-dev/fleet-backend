<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustodyItem;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\CompanyDeductionService;
use App\Services\DeductionsReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `withoutGlobalScopes()` is used to cross the company scope, but it takes SoftDeletes with it.
 * Every deduction source was therefore reading deleted rows, so a fine or an advance that had been
 * deleted kept being charged to the driver — on one production copy that was 240 deleted advances
 * worth 250,942 KWD standing against drivers who owed none of it.
 */
class DeletedDeductionsAreNotChargedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Soft Delete Co',
            'code' => 'softdel',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@softdel.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        $this->driver = Employee::create([
            'name' => 'Deleted Charges Driver',
            'employee_number' => 'EMP-SD-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-01-01',
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-SD-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);
    }

    private function pendingTotal(): float
    {
        $result = CompanyDeductionService::pendingFor(
            [$this->driver->id],
            '2026-07-01',
            '2026-07-31',
            2026,
            7
        );

        return (float) ($result[$this->driver->id]['total'] ?? 0.0);
    }

    public function test_a_deleted_fine_is_not_charged(): void
    {
        $violation = Violation::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'created_by' => $this->user->id,
            'violation_date' => '2026-07-14',
            'violation_type' => 'تجاوز السرعة',
            'amount' => 30.000,
            'driver_deduction' => 30.000,
            'is_driver_liable' => 1,
            'is_deducted' => 0,
        ]);

        $this->assertSame(30.0, $this->pendingTotal(), 'a live fine is owed');

        $violation->delete();

        $this->assertSame(0.0, $this->pendingTotal(), 'a deleted fine is not owed');
    }

    public function test_a_deleted_advance_is_not_charged(): void
    {
        $advance = SalaryAdvance::create([
            'employee_id' => $this->driver->id,
            'approved_by' => $this->user->id,
            'company_id' => $this->company->id,
            'amount' => 400.000,
            'monthly_installment' => 100.000,
            'total_installments' => 4,
            'paid_installments' => 0,
            'remaining_balance' => 400.000,
            'advance_date' => '2026-07-01',
            'status' => 'active',
        ]);

        $this->assertSame(100.0, $this->pendingTotal(), 'the month instalment is owed');

        $advance->delete();

        $this->assertSame(0.0, $this->pendingTotal(), 'a deleted advance owes nothing');
    }

    public function test_a_deleted_custody_charge_and_expense_are_not_charged(): void
    {
        $custody = CustodyItem::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'issued_by' => $this->user->id,
            'item_type' => 'clothing',
            'value' => 20.000,
            'issued_date' => '2026-06-01',
            'returned_date' => '2026-07-20',
            'status' => 'returned',
            'return_condition' => 'lost',
            'deduction_amount' => 12.000,
        ]);

        $expense = DriverExpense::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'expense_type' => 'مخالفة وقوف',
            'amount' => 8.000,
            'borne_by' => 'driver',
            'driver_amount' => 8.000,
            'expense_date' => '2026-07-18',
            'is_deducted' => 0,
        ]);

        $this->assertSame(20.0, $this->pendingTotal(), '12.000 custody + 8.000 expense');

        $custody->delete();
        $expense->delete();

        $this->assertSame(0.0, $this->pendingTotal());
    }

    /**
     * The report answers the same question for an accountant, so it must not disagree with payroll.
     */
    public function test_the_deductions_report_also_ignores_deleted_rows(): void
    {
        $violation = Violation::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'created_by' => $this->user->id,
            'violation_date' => '2026-07-14',
            'violation_type' => 'وقوف ممنوع',
            'amount' => 25.000,
            'driver_deduction' => 25.000,
            'is_driver_liable' => 1,
            'is_deducted' => 0,
        ]);

        $report = DeductionsReportService::build($this->company->id);
        $this->assertSame(25.0, (float) $report['totals']['pending']);

        $violation->delete();

        $report = DeductionsReportService::build($this->company->id);
        $this->assertSame(0.0, (float) $report['totals']['pending']);
        $this->assertSame([], $report['employees']);
    }
}
