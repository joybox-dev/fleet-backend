<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\CustodyItem;
use App\Models\DailyLog;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\MaintenanceRecord;
use App\Models\Role;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\Violation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The consolidated month taken apart for the owner's reconciliation: every deduction line behind
 * each column, every record of the month with where the driver's share went, and each column's
 * bridge to its page — so «the expenses page says 1000, is the column 1000?» has an answer.
 *
 * April 2026. «On» works ten days on a fixed contract and is on the sheet; «Off» has no contract
 * and is not. Between them the month holds every case the report has to tell apart.
 */
class PayrollDeductionReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Contract $contract;

    private Vehicle $vehicle;

    private Employee $on;

    private Employee $off;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-03 10:00:00');

        $this->company = Company::create([
            'name' => 'Report Co', 'code' => 'reportco',
            'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@report.test', 'password' => bcrypt('password'),
            'role' => 'admin', 'company_id' => $this->company->id,
        ]);

        $client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);
        $this->vehicle = Vehicle::create([
            'plate_number' => 'R-1', 'make' => 'Toyota', 'status' => 'working',
            'company_id' => $this->company->id, 'vehicle_type_id' => 1,
        ]);
        $this->contract = Contract::create([
            'client_id' => $client->id, 'contract_number' => 'CON-REP', 'name' => 'Report Contract',
            'payment_type' => 'fixed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed', 'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id, 'currency' => 'KWD', 'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);

        $this->on = $this->driver('On Driver', 'EMP-ON');
        $this->off = $this->driver('Off Driver', 'EMP-OFF');

        ContractAssignment::create([
            'employee_id' => $this->on->id, 'contract_id' => $this->contract->id,
            'start_date' => '2026-01-01', 'status' => 'active', 'company_id' => $this->company->id,
        ]);
        foreach (range(1, 10) as $day) {
            DailyLog::create([
                'employee_id' => $this->on->id, 'contract_id' => $this->contract->id, 'vehicle_id' => $this->vehicle->id,
                'log_date' => sprintf('2026-04-%02d', $day), 'driver_status' => 'working', 'orders_count' => 0,
                'company_id' => $this->company->id, 'created_by' => $this->admin->id,
            ]);
        }

        // Expenses: his own share, one the company bears, one of a driver not on the sheet, and one
        // of his from March that nothing took yet.
        $this->ids['expense_his'] = $this->expense($this->on, '2026-04-10', 20, 'driver')->id;
        $this->ids['expense_company'] = $this->expense($this->on, '2026-04-12', 30, 'company')->id;
        $this->ids['expense_off'] = $this->expense($this->off, '2026-04-15', 12, 'driver')->id;
        $this->ids['expense_march'] = $this->expense($this->on, '2026-03-20', 8, 'driver')->id;

        // Fines: his, one of the driver not on the sheet, and one the company bears.
        $this->ids['fine_his'] = $this->fine($this->on, '2026-04-20 15:00:00', 15, true)->id;
        $this->ids['fine_off'] = $this->fine($this->off, '2026-04-21 09:00:00', 7, true)->id;
        $this->ids['fine_company'] = $this->fine($this->on, '2026-04-22 11:00:00', 40, false)->id;

        // Advances: his from March paying by instalments, and one issued in April to the other driver.
        $this->ids['advance_his'] = SalaryAdvance::forceCreate([
            'company_id' => $this->company->id, 'employee_id' => $this->on->id, 'amount' => 150, 'approved_by' => $this->admin->id,
            'monthly_installment' => 50, 'total_installments' => 3, 'paid_installments' => 0,
            'remaining_balance' => 150, 'advance_date' => '2026-03-01', 'status' => 'active', 'reason' => 'سلفة',
        ])->id;
        $this->ids['advance_off'] = SalaryAdvance::forceCreate([
            'company_id' => $this->company->id, 'employee_id' => $this->off->id, 'amount' => 60, 'approved_by' => $this->admin->id,
            'monthly_installment' => 60, 'total_installments' => 1, 'paid_installments' => 0,
            'remaining_balance' => 60, 'advance_date' => '2026-04-05', 'status' => 'active', 'reason' => 'سلفة',
        ])->id;

        $this->ids['advance_cancelled'] = SalaryAdvance::forceCreate([
            'company_id' => $this->company->id, 'employee_id' => $this->on->id, 'amount' => 40, 'approved_by' => $this->admin->id,
            'monthly_installment' => 40, 'total_installments' => 1, 'paid_installments' => 0,
            'remaining_balance' => 40, 'advance_date' => '2026-04-07', 'status' => 'cancelled', 'reason' => 'أُلغيت',
        ])->id;

        // Repairs he is liable for: one approved, one still waiting for approval.
        $this->ids['repair_approved'] = $this->repair('2026-04-08', 100, 25, 'approved')->id;
        $this->ids['repair_waiting'] = $this->repair('2026-04-09', 50, 10, 'pending')->id;

        $this->ids['custody_lost'] = CustodyItem::forceCreate([
            'company_id' => $this->company->id, 'employee_id' => $this->on->id, 'issued_by' => $this->admin->id, 'item_type' => 'other',
            'item_description' => 'خوذة', 'value' => 10, 'issued_date' => '2026-01-10', 'returned_date' => '2026-04-18',
            'status' => 'returned', 'return_condition' => 'lost', 'deduction_amount' => 10,
        ])->id;

        $this->ids['vehicle_expense'] = VehicleExpense::forceCreate([
            'company_id' => $this->company->id, 'vehicle_id' => $this->vehicle->id, 'expense_type' => 'repair',
            'amount' => 40, 'expense_date' => '2026-04-11', 'vendor' => 'Garage',
        ])->id;

        $this->actingAs($this->admin);
        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", ['year' => 2026, 'month' => 4])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_column_is_the_sum_of_its_lines_and_every_record_says_where_its_share_went(): void
    {
        $sheet = $this->getJson('/api/payroll/consolidated/2026/4')->assertOk()->json();
        $report = $this->getJson('/api/payroll/consolidated/2026/4/deductions-report')->assertOk()->json();
        $this->assertFalse($report['is_approved']);

        // The lines are the sheet's own: per kind they add up to the driver's column, fils for fils.
        $row = collect($sheet['drivers'])->firstWhere('employee_id', $this->on->id);
        $lines = collect($report['sheet_lines'])->where('employee_id', $this->on->id);
        $this->assertSame((float) $row['pending_driver_expenses_deduction'], (float) $lines->where('kind', 'driver_expense')->sum('amount'));
        $this->assertSame((float) $row['pending_violations_deduction'], (float) $lines->where('kind', 'violation')->sum('amount'));
        $this->assertSame((float) $row['pending_maintenance_deduction'], (float) $lines->where('kind', 'maintenance')->sum('amount'));
        $this->assertSame((float) $row['pending_custody_deduction'], (float) $lines->where('kind', 'custody')->sum('amount'));
        $this->assertSame((float) $row['pending_advances_deduction'], (float) $lines->where('kind', 'advance')->sum('amount'));
        $this->assertSame(28.0, (float) $row['pending_driver_expenses_deduction'], 'his April 20 and the March 8');
        $expenseLine = fn (string $key) => $lines->first(fn ($l) => $l['kind'] === 'driver_expense' && $l['source_id'] === $this->ids[$key]);
        $this->assertSame('2026-03-20', $expenseLine('expense_march')['date']);
        $this->assertSame('R-1', $expenseLine('expense_his')['plate_number']);

        $status = fn (string $kind, string $key) => collect($report['items'])
            ->first(fn ($i) => $i['kind'] === $kind && $i['source_id'] === $this->ids[$key]);

        $this->assertSame('this_sheet', $status('driver_expense', 'expense_his')['status']);
        $this->assertSame('يُخصم عند اعتماد هذا الكشف', $status('driver_expense', 'expense_his')['status_label']);
        $this->assertSame('company', $status('driver_expense', 'expense_company')['status']);
        $this->assertSame('pending', $status('driver_expense', 'expense_off')['status']);
        $this->assertStringContainsString('ليس على كشف هذا الشهر', $status('driver_expense', 'expense_off')['status_label']);
        $this->assertFalse($status('driver_expense', 'expense_march')['in_month']);
        $this->assertSame('this_sheet', $status('violation', 'fine_his')['status']);
        $this->assertStringContainsString('لا تنتقل إلا بخصم يدوي', $status('violation', 'fine_off')['status_label']);
        $this->assertSame('company', $status('violation', 'fine_company')['status']);
        $this->assertSame('this_sheet', $status('maintenance', 'repair_approved')['status']);
        $this->assertSame('بانتظار اعتماد الصيانة', $status('maintenance', 'repair_waiting')['status_label']);
        $this->assertSame('this_sheet', $status('custody', 'custody_lost')['status']);
        $this->assertSame('company', $status('vehicle_expense', 'vehicle_expense')['status']);
        $this->assertSame('إصلاح', $status('vehicle_expense', 'vehicle_expense')['description']);

        // The bridge: the month as the expenses page shows it, and where the drivers' share went.
        $bridge = collect($report['reconciliation'])->keyBy('kind');
        $this->assertSame([
            'recorded_count' => 3, 'recorded_amount' => 62.0, 'company_share' => 30.0, 'driver_share' => 32.0,
            'this_sheet' => 20.0, 'other_month' => 0.0, 'deferred' => 0.0, 'pending' => 12.0,
            'carried_in' => 8.0, 'sheet_column' => 28.0, 'sheet_column_on_sheet' => 28.0,
        ], $this->figures($bridge['driver_expense']));
        $this->assertSame([
            'recorded_count' => 3, 'recorded_amount' => 62.0, 'company_share' => 40.0, 'driver_share' => 22.0,
            'this_sheet' => 15.0, 'other_month' => 0.0, 'deferred' => 0.0, 'pending' => 7.0,
            'carried_in' => 0.0, 'sheet_column' => 15.0, 'sheet_column_on_sheet' => 15.0,
        ], $this->figures($bridge['violation']));
        $this->assertSame(35.0, (float) $bridge['maintenance']['driver_share'], 'the approved 25 and the waiting 10');
        $this->assertSame(10.0, (float) $bridge['maintenance']['pending']);
        $this->assertSame(25.0, (float) $bridge['maintenance']['sheet_column']);
        $this->assertSame(40.0, (float) $bridge['vehicle_expense']['company_share']);
        foreach ($bridge as $kind => $line) {
            $this->assertSame((float) $line['sheet_column_on_sheet'], (float) $line['sheet_column'], "{$kind}: the report's column is the sheet's");
        }

        // Advances: the principal, this month's instalment and what is left after it.
        $advance = collect($report['advances'])->firstWhere('source_id', $this->ids['advance_his']);
        $instalment = (float) $row['pending_advances_deduction'];
        $this->assertGreaterThan(0.0, $instalment);
        $this->assertSame(150.0, (float) $advance['amount']);
        $this->assertSame($instalment, (float) $advance['on_sheet_amount']);
        $this->assertSame(round(150 - $instalment, 3), (float) $advance['remaining_after']);
        $offAdvance = collect($report['advances'])->firstWhere('source_id', $this->ids['advance_off']);
        $this->assertTrue($offAdvance['in_month']);
        $this->assertSame('السائق ليس على كشف هذا الشهر', $offAdvance['status_label']);
        $cancelled = collect($report['advances'])->firstWhere('source_id', $this->ids['advance_cancelled']);
        $this->assertSame('ملغاة، لا يُخصم منها شيء', $cancelled['status_label']);
        $this->assertSame(0.0, (float) $cancelled['on_sheet_amount']);
        $this->assertSame(1, $report['advances_summary']['issued_count'], 'a cancelled advance was never money out');
        $this->assertSame(60.0, (float) $report['advances_summary']['issued_amount']);
        $this->assertSame(1, $report['advances_summary']['cancelled_count']);
        $this->assertSame(40.0, (float) $report['advances_summary']['cancelled_amount']);
        $this->assertSame($instalment, (float) $report['advances_summary']['sheet_column']);
    }

    public function test_an_approved_month_reads_as_it_closed_and_names_what_came_after(): void
    {
        $this->postJson('/api/payroll/consolidated/2026/4/approve')->assertOk();
        $instalment = (float) collect($this->getJson('/api/payroll/consolidated/2026/4')->json('drivers'))
            ->firstWhere('employee_id', $this->on->id)['advances_deduction'];

        // Recorded the day after the month was closed, dated inside it.
        Carbon::setTestNow('2026-05-04 09:00:00');
        $late = $this->expense($this->on, '2026-04-28', 5, 'driver');
        $lateFine = $this->fine($this->on, '2026-04-29 10:00:00', 9, true);

        $report = $this->getJson('/api/payroll/consolidated/2026/4/deductions-report')->assertOk()->json();
        $this->assertTrue($report['is_approved']);

        $items = collect($report['items']);
        $this->assertSame('خُصم في هذا الكشف', $items->first(fn ($i) => $i['kind'] === 'driver_expense' && $i['source_id'] === $this->ids['expense_his'])['status_label']);
        $this->assertSame('سُجِّل بعد اعتماد الشهر، ويُخصم في الكشف التالي', $items->first(fn ($i) => $i['kind'] === 'driver_expense' && $i['source_id'] === $late->id)['status_label']);
        $this->assertSame('سُجِّلت بعد اعتماد الشهر، وتُخصم يدوياً في شهر مفتوح', $items->first(fn ($i) => $i['kind'] === 'violation' && $i['source_id'] === $lateFine->id)['status_label']);

        // Still the principal less the instalments up to and including April's run.
        $advance = collect($report['advances'])->firstWhere('source_id', $this->ids['advance_his']);
        $this->assertSame(round(150 - $instalment, 3), (float) $advance['remaining_after']);
        $this->assertSame('قسط خُصم في هذا الكشف', $advance['status_label']);

        // May takes the late expense, and April's report then says where it went.
        DailyLog::create([
            'employee_id' => $this->on->id, 'contract_id' => $this->contract->id, 'vehicle_id' => $this->vehicle->id,
            'log_date' => '2026-05-02', 'driver_status' => 'working', 'orders_count' => 0,
            'company_id' => $this->company->id, 'created_by' => $this->admin->id,
        ]);
        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", ['year' => 2026, 'month' => 5])->assertOk();
        $this->postJson('/api/payroll/consolidated/2026/5/approve')->assertOk();

        $april = collect($this->getJson('/api/payroll/consolidated/2026/4/deductions-report')->json('items'));
        $this->assertSame('خُصم في كشف 05/2026', $april->first(fn ($i) => $i['kind'] === 'driver_expense' && $i['source_id'] === $late->id)['status_label']);
        $bridge = collect($this->getJson('/api/payroll/consolidated/2026/4/deductions-report')->json('reconciliation'))->keyBy('kind');
        $this->assertSame(5.0, (float) $bridge['driver_expense']['other_month']);
    }

    public function test_a_charge_the_owner_deferred_is_named_as_his_decision(): void
    {
        $this->postJson('/api/payroll/consolidated/2026/4/deduction-overrides', [
            'source_type' => 'driver_expense', 'source_id' => $this->ids['expense_his'], 'action' => 'defer',
            'defer_to_year' => 2026, 'defer_to_month' => 6, 'reason' => 'اتفاق مع السائق',
        ])->assertSuccessful();

        $report = $this->getJson('/api/payroll/consolidated/2026/4/deductions-report')->assertOk()->json();
        $item = collect($report['items'])->first(fn ($i) => $i['kind'] === 'driver_expense' && $i['source_id'] === $this->ids['expense_his']);
        $this->assertSame('deferred', $item['status']);
        $this->assertStringContainsString('مؤجَّل بقرار', $item['status_label']);

        $bridge = collect($report['reconciliation'])->keyBy('kind');
        $this->assertSame(20.0, (float) $bridge['driver_expense']['deferred']);
        $this->assertSame(8.0, (float) $bridge['driver_expense']['sheet_column'], 'only the March expense is left in April');
        $this->assertSame(8.0, (float) $bridge['driver_expense']['sheet_column_on_sheet']);
    }

    public function test_whoever_may_read_the_sheet_may_read_the_report_and_nobody_else(): void
    {
        Role::create(['name' => 'قارئ رواتب', 'company_id' => $this->company->id, 'allowed_modules' => ['contract_payroll.view']]);
        Role::create(['name' => 'إجازات', 'company_id' => $this->company->id, 'allowed_modules' => ['leaves.view']]);

        $this->actingAs($this->login('reader', 'قارئ رواتب'))->getJson('/api/payroll/consolidated/2026/4/deductions-report')->assertOk();
        $this->actingAs($this->login('leaves', 'إجازات'))->getJson('/api/payroll/consolidated/2026/4/deductions-report')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, float|int>
     */
    private function figures(array $line): array
    {
        $keys = ['recorded_count', 'recorded_amount', 'company_share', 'driver_share', 'this_sheet', 'other_month',
            'deferred', 'pending', 'carried_in', 'sheet_column', 'sheet_column_on_sheet'];

        return collect($keys)->mapWithKeys(fn ($k) => [$k => $k === 'recorded_count' ? (int) $line[$k] : (float) $line[$k]])->all();
    }

    private function driver(string $name, string $number): Employee
    {
        return Employee::create([
            'name' => $name, 'employee_number' => $number, 'company_id' => $this->company->id, 'status' => 'active',
            'role_category' => 'driver', 'date_of_joining' => '2026-01-01', 'actual_salary' => 0, 'official_salary' => 100,
        ]);
    }

    private function login(string $handle, string $role): User
    {
        return User::create([
            'name' => ucfirst($handle), 'email' => "{$handle}@report.test", 'password' => bcrypt('password'),
            'role' => $role, 'company_id' => $this->company->id,
        ]);
    }

    private function expense(Employee $driver, string $date, float $amount, string $borneBy): DriverExpense
    {
        return DriverExpense::forceCreate([
            'company_id' => $this->company->id, 'employee_id' => $driver->id, 'vehicle_id' => $this->vehicle->id,
            'expense_type' => 'بنزين / محروقات', 'amount' => $amount, 'borne_by' => $borneBy,
            'driver_amount' => $borneBy === 'driver' ? $amount : 0, 'company_amount' => $borneBy === 'company' ? $amount : 0,
            'expense_date' => $date, 'is_deducted' => false,
        ]);
    }

    private function fine(Employee $driver, string $when, float $amount, bool $driverPays): Violation
    {
        return Violation::forceCreate([
            'company_id' => $this->company->id, 'employee_id' => $driver->id, 'vehicle_id' => $this->vehicle->id, 'created_by' => $this->admin->id,
            'violation_date' => $when, 'violation_type' => 'سرعة', 'amount' => $amount,
            'is_driver_liable' => $driverPays, 'driver_deduction' => $driverPays ? $amount : 0, 'is_deducted' => false,
        ]);
    }

    private function repair(string $date, float $cost, float $driverShare, string $status): MaintenanceRecord
    {
        return MaintenanceRecord::forceCreate([
            'company_id' => $this->company->id, 'vehicle_id' => $this->vehicle->id, 'reported_by' => $this->admin->id, 'maintenance_type' => 'accident',
            'maintenance_date' => $date, 'estimated_cost' => $cost, 'actual_cost' => $status === 'approved' ? $cost : null,
            'status' => $status, 'is_driver_liable' => true, 'liable_employee_id' => $this->on->id, 'driver_deduction' => $driverShare,
        ]);
    }
}
