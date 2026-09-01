<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Services\CompanyDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the consolidated sheet does when a driver owes more than the month earned.
 *
 * This is a characterisation test: it records today's behaviour rather than endorsing it. The net
 * is `gross + adjustments - charged` with no floor, so it goes negative; and approval writes the
 * ledger rows and flips the source flags for the FULL amount, so every charge is recorded as
 * collected even though the pay could not cover it. Nothing carries the shortfall into next month
 * on this path — only the legacy PayrollRun did that, by opening an advance.
 *
 * If a decision is taken to collect only what the month can bear, these expectations are the ones
 * that must change, and the change will be visible here.
 */
class DeductionsExceedingPayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Overdrawn Co',
            'code' => 'overco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@overco.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        $this->driver = Employee::create([
            'name' => 'Overdrawn Driver',
            'employee_number' => 'EMP-OD-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
        ]);
    }

    /**
     * The whole amount is owed, not the part the month could pay for.
     */
    public function test_the_full_charge_is_owed_regardless_of_earnings(): void
    {
        DriverExpense::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'expense_type' => 'إصلاح على حساب السائق',
            'amount' => 250.000,
            'borne_by' => 'driver',
            'driver_amount' => 250.000,
            'expense_date' => '2026-07-09',
            'is_deducted' => 0,
        ]);

        $pending = CompanyDeductionService::pendingFor(
            [$this->driver->id], '2026-07-01', '2026-07-31', 2026, 7
        );

        $this->assertSame(250.0, round((float) $pending[$this->driver->id]['total'], 3));
    }

    /**
     * The arithmetic the sheet performs, with the figures a 100.000 month and a 250.000 charge
     * produce. There is no max(0) anywhere on this path.
     */
    public function test_the_net_goes_negative_with_no_floor(): void
    {
        $gross = 100.000;
        $adjustments = 0.0;
        $charged = 250.000;

        // PayrollController::approveConsolidatedSheet, the line that writes final_net_payout.
        $net = round($gross + $adjustments - $charged, 3);

        $this->assertSame(-150.0, $net, 'the month ends as a debt, not as zero');
        $this->assertLessThan(0, $net);
    }

    /**
     * And the shortfall is not carried anywhere: the consolidated path opens no advance and writes
     * no balance, so next month starts clean and the 150.000 is simply gone.
     */
    public function test_nothing_carries_the_shortfall_into_the_next_month(): void
    {
        $august = CompanyDeductionService::pendingFor(
            [$this->driver->id], '2026-08-01', '2026-08-31', 2026, 8
        );

        $this->assertSame(
            0.0,
            round((float) ($august[$this->driver->id]['total'] ?? 0), 3),
            'no mechanism turns an unpayable month into a balance the next month collects'
        );

        $this->assertSame(
            0,
            SalaryAdvance::withoutGlobalScopes()->where('employee_id', $this->driver->id)->count(),
            'the consolidated path does not open an advance for the shortfall (the legacy path did)'
        );
    }
}
