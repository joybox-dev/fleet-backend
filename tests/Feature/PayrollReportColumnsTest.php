<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\ContractPayrollRun;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The accountant's payroll report: per driver the job title, the month's required and paid days,
 * the monthly salary and the share of it the days earned, incentives, the hand adjustments both
 * ways and absence — columns the consolidated sheet never carried although its contract sheets
 * hold every one. They are read from the approved contract sheets and change no figure paid.
 *
 * April 2026 (30 days). A fixed contract pays 260.000 a month over 26 working days, 10.000 a day.
 */
class PayrollReportColumnsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Contract $contract;

    private Employee $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-03 10:00:00');

        $this->company = Company::create([
            'name' => 'Report Columns Co', 'code' => 'repcols',
            'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@repcols.test', 'password' => bcrypt('password'),
            'role' => 'admin', 'company_id' => $this->company->id,
        ]);
        $this->actingAs($this->admin);

        $client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);
        $vehicle = Vehicle::create([
            'plate_number' => 'RC-1', 'status' => 'working',
            'company_id' => $this->company->id, 'vehicle_type_id' => 1,
        ]);
        $this->contract = Contract::create([
            'client_id' => $client->id, 'contract_number' => 'CON-RC', 'name' => 'عقد الأعمدة',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed', 'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id, 'currency' => 'KWD', 'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);

        $this->driver = Employee::create([
            'name' => 'سائق التقرير', 'employee_number' => 'EMP-RC', 'company_id' => $this->company->id,
            'status' => 'active', 'role_category' => 'driver', 'date_of_joining' => '2026-01-01',
            'job_title' => 'سائق توصيل',
        ]);
        ContractAssignment::create([
            'employee_id' => $this->driver->id, 'contract_id' => $this->contract->id,
            'start_date' => '2026-01-01', 'status' => 'active', 'company_id' => $this->company->id,
        ]);

        // Twenty working days of April.
        foreach (range(1, 20) as $day) {
            DailyLog::create([
                'employee_id' => $this->driver->id, 'contract_id' => $this->contract->id, 'vehicle_id' => $vehicle->id,
                'log_date' => sprintf('2026-04-%02d', $day), 'driver_status' => 'working', 'orders_count' => 5,
                'company_id' => $this->company->id, 'created_by' => $this->admin->id,
            ]);
        }

        foreach ([['addition', 15, 'مكافأة'], ['deduction', 5, 'تأخير']] as [$type, $amount, $reason]) {
            $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/adjustments", [
                'employee_id' => $this->driver->id, 'year' => 2026, 'month' => 4,
                'type' => $type, 'amount' => $amount, 'reason' => $reason,
            ])->assertSuccessful();
        }

        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", ['year' => 2026, 'month' => 4])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $sheet = $this->getJson('/api/payroll/consolidated/2026/4')->assertOk()->json();

        return collect($sheet['drivers'])->firstWhere('employee_id', $this->driver->id);
    }

    public function test_each_driver_row_carries_the_report_columns(): void
    {
        $row = $this->row();
        $report = $row['report'];

        $this->assertSame('سائق توصيل', $report['job_title']);
        $this->assertSame(26, $report['required_days']);
        $this->assertEquals(20, $report['payable_days']);
        $this->assertEquals(260.0, $report['base_salary_monthly']);
        $this->assertEquals(200.0, $report['base_salary_earned']);
        $this->assertEquals(15.0, $report['adjustment_additions']);
        $this->assertEquals(5.0, $report['adjustment_deductions']);
        // Assigned the whole month, so all 26 days were expected: 6 not paid, at 10.000 a day.
        $this->assertEquals(6, $report['absence_days']);
        $this->assertEquals(60.0, $report['absence_amount']);

        // The figures the sheet pays are the ones they were: 200 + 15 − 5.
        $this->assertEquals(200.0, $row['gross_contract_earnings']);
        $this->assertEquals(210.0, $row['final_net_payout']);
    }

    public function test_an_approved_month_and_a_sheet_approved_before_the_field_existed_read_the_same(): void
    {
        $this->postJson('/api/payroll/consolidated/2026/4/approve')->assertOk();
        $this->assertEquals(260.0, $this->row()['report']['base_salary_monthly']);

        // A contract sheet frozen before it kept the monthly salary: read from its own formula.
        $run = ContractPayrollRun::withoutGlobalScopes()->where('contract_id', $this->contract->id)->first();
        $snapshot = $run->snapshot_data;
        foreach ($snapshot['drivers'] as $i => $driver) {
            unset($snapshot['drivers'][$i]['base_salary_monthly']);
        }
        $run->update(['snapshot_data' => $snapshot]);

        $report = $this->row()['report'];
        $this->assertEquals(260.0, $report['base_salary_monthly']);
        $this->assertEquals(60.0, $report['absence_amount']);
    }

    public function test_a_driver_with_no_title_reads_as_a_driver_and_the_title_is_saved_through_the_form(): void
    {
        $this->driver->update(['job_title' => null]);
        $this->assertSame('سائق', $this->row()['report']['job_title']);

        $this->putJson("/api/employees/{$this->driver->id}", ['job_title' => 'كابتن'])->assertOk();
        $this->assertSame('كابتن', $this->driver->fresh()->job_title);
    }
}
