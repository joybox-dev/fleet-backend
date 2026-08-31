<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PayrollController;
use App\Models\Company;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\PayrollSlip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\CompanyDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Building or rebuilding the legacy payroll sheet used to flag every fine and expense
 * `is_deducted`, on a run created as a draft that nobody had approved. A flagged charge is
 * excluded from the consolidated sheet — the only place that actually charges a driver and writes
 * a ledger row — so the charge became uncollectable while looking collected.
 *
 * The rebuild is not a deliberate act: DailyLogObserver::recalculateDraftRun() calls it whenever a
 * daily log is saved, so ordinary daily-log work burned the charges. On one production copy that
 * was 896.000 KWD across 34 fines and expenses, all of them still showing "✅ مخصوم" with no
 * payslip anywhere recording the money.
 */
class DraftPayrollDoesNotCollectDeductionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Vehicle $vehicle;

    private PayrollRun $draftRun;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Draft Payroll Co',
            'code' => 'draftpay',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@draftpay.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        $this->driver = Employee::create([
            'name' => 'Draft Run Driver',
            'employee_number' => 'EMP-DR-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-DR-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);

        $this->draftRun = PayrollRun::create([
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
            'year' => 2026,
            'month' => 7,
            'status' => 'draft',
        ]);
    }

    private function makeFine(): Violation
    {
        return Violation::create([
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
    }

    private function makeExpense(): DriverExpense
    {
        return DriverExpense::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'expense_type' => 'بنزين / محروقات',
            'amount' => 20.000,
            'borne_by' => 'driver',
            'driver_amount' => 20.000,
            'expense_date' => '2026-07-09',
            'is_deducted' => 0,
        ]);
    }

    /**
     * Saving a fine or an expense rebuilds the draft run on its own (Violation::booted and
     * DailyLogObserver both call recalculateRun), so the slip is already there by the time a
     * test wants one.
     */
    private function slipForDriver(): PayrollSlip
    {
        return PayrollSlip::withoutGlobalScopes()->firstOrCreate([
            'payroll_run_id' => $this->draftRun->id,
            'employee_id' => $this->driver->id,
        ], [
            'company_id' => $this->company->id,
        ]);
    }

    /** Stage the exact state the old code left behind, bypassing the model hooks. */
    private function burn($charge, PayrollSlip $slip): void
    {
        DB::table($charge->getTable())->where('id', $charge->id)->update([
            'is_deducted' => true,
            'payroll_slip_id' => $slip->id,
        ]);
    }

    public function test_rebuilding_a_draft_run_does_not_mark_charges_collected(): void
    {
        $fine = $this->makeFine();
        $expense = $this->makeExpense();

        PayrollController::recalculateRun($this->draftRun);

        $this->assertFalse(
            (bool) $fine->fresh()->is_deducted,
            'a draft rebuild must not claim the fine has been collected'
        );
        $this->assertFalse(
            (bool) $expense->fresh()->is_deducted,
            'a draft rebuild must not claim the expense has been collected'
        );
    }

    /**
     * The rebuild releases whatever an earlier build had claimed, keyed on the slip it wrote. That
     * is what returns the already-burned charges to the consolidated sheet without a data fix.
     */
    public function test_rebuilding_releases_charges_an_earlier_build_had_claimed(): void
    {
        $fine = $this->makeFine();
        $expense = $this->makeExpense();

        $slip = $this->slipForDriver();

        // Written straight to the table: Violation::saved rebuilds the run, and the rebuild now
        // releases the charge, so the burned state cannot be staged through the model.
        $this->burn($fine, $slip);
        $this->burn($expense, $slip);

        PayrollController::recalculateRun($this->draftRun);

        $this->assertFalse((bool) $fine->fresh()->is_deducted, 'the fine is owed again');
        $this->assertNull($fine->fresh()->payroll_slip_id);
        $this->assertFalse((bool) $expense->fresh()->is_deducted, 'the expense is owed again');
        $this->assertNull($expense->fresh()->payroll_slip_id);
    }

    /**
     * The charge must land back in what payroll would collect — being unflagged is only useful if
     * the consolidated sheet can see it again.
     */
    public function test_a_released_charge_is_collectable_again(): void
    {
        $fine = $this->makeFine();

        $slip = $this->slipForDriver();
        $this->burn($fine, $slip);

        $burned = CompanyDeductionService::pendingFor(
            [$this->driver->id], '2026-07-01', '2026-07-31', 2026, 7
        );
        $this->assertSame(0.0, (float) ($burned[$this->driver->id]['total'] ?? 0.0),
            'while flagged, the fine is invisible to payroll');

        PayrollController::recalculateRun($this->draftRun);

        $released = CompanyDeductionService::pendingFor(
            [$this->driver->id], '2026-07-01', '2026-07-31', 2026, 7
        );
        $this->assertSame(30.0, (float) ($released[$this->driver->id]['total'] ?? 0.0),
            'once released it is owed again');
    }
}
