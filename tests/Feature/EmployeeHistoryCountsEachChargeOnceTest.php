<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustodyItem;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Services\CompanyDeductionService;
use App\Services\EmployeeLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The employee profile's financial-account tab lists one row per month and sums those rows. It
 * resolved each open month's deductions with the payroll question — "what is collectable when I
 * close this month" — under which an uncollected charge stays collectable and so comes back every
 * month. A single 20.000 KWD expense dated 09/07 was therefore reported in July, August and
 * September, and the total read 40.000 against a driver who owed 20.000, rising with every month
 * that opened.
 *
 * A history has to attribute a charge to one month. Payroll must not: its behaviour is pinned here
 * too, because the tempting fix — bounding the query for everyone — would turn a display fault
 * into money never collected.
 */
class EmployeeHistoryCountsEachChargeOnceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'History Co',
            'code' => 'histco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@histco.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        $this->driver = Employee::create([
            'name' => 'History Driver',
            'employee_number' => 'EMP-H-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
        ]);
    }

    private function julyExpense(): DriverExpense
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

    /** @return array<string, float> month label => deduction total */
    private function historyByMonth(): array
    {
        $history = EmployeeLedgerService::history($this->driver);
        $out = [];
        foreach ($history['months'] ?? [] as $m) {
            $out[$m['label']] = round((float) ($m['deductions_total'] ?? 0), 3);
        }

        return $out;
    }

    public function test_a_charge_is_counted_in_its_own_month_only(): void
    {
        $this->julyExpense();

        $months = $this->historyByMonth();

        $this->assertSame(20.0, $months['07/2026'] ?? null, 'the month it arose in carries it');

        foreach ($months as $label => $total) {
            if ($label !== '07/2026') {
                $this->assertSame(0.0, $total, "{$label} must not repeat a July charge");
            }
        }
    }

    public function test_the_total_is_what_the_driver_owes_not_a_multiple_of_it(): void
    {
        $this->julyExpense();

        $history = EmployeeLedgerService::history($this->driver);

        $this->assertSame(
            20.0,
            round((float) $history['totals']['deductions_total'], 3),
            'one 20.000 charge totals 20.000 however many months are open'
        );
    }

    /**
     * The reason the fix is a flag and not a change to the query everyone shares.
     */
    public function test_payroll_can_still_collect_a_charge_in_a_later_month(): void
    {
        $this->julyExpense();

        foreach ([['2026-07-01', '2026-07-31', 7], ['2026-08-01', '2026-08-31', 8], ['2026-09-01', '2026-09-30', 9]] as [$start, $end, $month]) {
            $pending = CompanyDeductionService::pendingFor([$this->driver->id], $start, $end, 2026, $month);

            $this->assertSame(
                20.0,
                round((float) ($pending[$this->driver->id]['total'] ?? 0), 3),
                "payroll must still be able to collect the July expense when closing month {$month}"
            );
        }
    }

    /**
     * An advance is the one cumulative source that must keep repeating: each month is a different
     * instalment, not the same charge seen again. Bounding it would delete every instalment after
     * the first, and with most advances written as a single instalment that would go unnoticed.
     */
    public function test_advance_instalments_still_fall_in_every_month_of_the_schedule(): void
    {
        SalaryAdvance::create([
            'employee_id' => $this->driver->id,
            'approved_by' => $this->user->id,
            'company_id' => $this->company->id,
            'amount' => 300.000,
            'monthly_installment' => 100.000,
            'total_installments' => 3,
            'paid_installments' => 0,
            'remaining_balance' => 300.000,
            'advance_date' => '2026-07-01',
            'status' => 'active',
        ]);

        foreach ([7, 8, 9] as $month) {
            $end = Carbon::create(2026, $month, 1)->endOfMonth()->toDateString();
            $start = sprintf('2026-%02d-01', $month);

            $history = CompanyDeductionService::pendingFor(
                [$this->driver->id], $start, $end, 2026, $month, true
            );

            $this->assertSame(
                100.0,
                round((float) ($history[$this->driver->id]['total'] ?? 0), 3),
                "month {$month} carries its own instalment even in history mode"
            );
        }
    }

    /**
     * Custody and maintenance share the expense's shape, so they share its fault.
     */
    public function test_a_custody_charge_is_also_counted_once(): void
    {
        CustodyItem::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'issued_by' => $this->user->id,
            'item_type' => 'clothing',
            'value' => 40.000,
            'issued_date' => '2026-06-01',
            'returned_date' => '2026-07-20',
            'status' => 'returned',
            'return_condition' => 'lost',
            'deduction_amount' => 15.000,
        ]);

        $history = CompanyDeductionService::pendingFor(
            [$this->driver->id], '2026-08-01', '2026-08-31', 2026, 8, true
        );

        $this->assertSame(
            0.0,
            round((float) ($history[$this->driver->id]['total'] ?? 0), 3),
            'a July custody charge does not belong to August'
        );

        $july = CompanyDeductionService::pendingFor(
            [$this->driver->id], '2026-07-01', '2026-07-31', 2026, 7, true
        );

        $this->assertSame(15.0, round((float) ($july[$this->driver->id]['total'] ?? 0), 3));
    }
}
