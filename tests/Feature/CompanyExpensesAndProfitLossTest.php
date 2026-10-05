<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyExpense;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleFixedCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The accountant's profit and loss and what it needed that did not exist: an expense tree and a
 * way to record the company's own spending (rent, residencies, bank fees…), the vehicles' fixed
 * costs (rent, instalments, depreciation), and the statement itself.
 *
 * May 2026. One fixed contract bills its client 600.000 a month and pays its driver 260.000 over
 * 26 days; he works ten days (100.000).
 */
class CompanyExpensesAndProfitLossTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Vehicle $vehicle;

    private Employee $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-03 10:00:00');

        $this->company = Company::create([
            'name' => 'PL Co', 'code' => 'plco', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@pl.test', 'password' => bcrypt('password'),
            'role' => 'admin', 'company_id' => $this->company->id,
        ]);
        $this->actingAs($this->admin);

        $client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);
        $this->vehicle = Vehicle::create([
            'plate_number' => 'PL-1', 'status' => 'working', 'company_id' => $this->company->id, 'vehicle_type_id' => 1,
        ]);
        $contract = Contract::create([
            'client_id' => $client->id, 'contract_number' => 'CON-PL', 'name' => 'عقد الأرباح',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed', 'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id, 'currency' => 'KWD', 'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 600]],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);
        $this->driver = Employee::create([
            'name' => 'سائق', 'employee_number' => 'EMP-PL', 'company_id' => $this->company->id,
            'status' => 'active', 'role_category' => 'driver', 'date_of_joining' => '2026-01-01',
        ]);
        ContractAssignment::create([
            'employee_id' => $this->driver->id, 'contract_id' => $contract->id,
            'start_date' => '2026-01-01', 'status' => 'active', 'company_id' => $this->company->id,
        ]);
        foreach (range(1, 10) as $day) {
            DailyLog::create([
                'employee_id' => $this->driver->id, 'contract_id' => $contract->id, 'vehicle_id' => $this->vehicle->id,
                'log_date' => sprintf('2026-05-%02d', $day), 'driver_status' => 'working', 'orders_count' => 10,
                'company_id' => $this->company->id, 'created_by' => $this->admin->id,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function item(string $name): ExpenseCategory
    {
        $this->getJson('/api/expense-categories')->assertOk();

        return ExpenseCategory::where('name_ar', $name)->firstOrFail();
    }

    public function test_the_expense_tree_starts_with_the_accountants_list_and_stays_two_levels(): void
    {
        $tree = $this->getJson('/api/expense-categories')->assertOk()->json('data');

        $hr = collect($tree)->firstWhere('name_ar', 'موارد بشرية وإدارية');
        $this->assertNotNull($hr);
        $this->assertContains('تجديد إقامات', array_column($hr['children'], 'name_ar'));
        $this->assertContains('رسوم بنكية', array_column($hr['children'], 'name_ar'));

        // A new item under a category; an item under an item is refused.
        $new = $this->postJson('/api/expense-categories', ['name_ar' => 'اشتراكات', 'parent_id' => $hr['id']])->assertCreated()->json('data');
        $this->postJson('/api/expense-categories', ['name_ar' => 'فرعي', 'parent_id' => $new['id']])->assertUnprocessable();

        // An item with spending on it is history: switched off, never deleted.
        CompanyExpense::create(['expense_category_id' => $new['id'], 'amount' => 5, 'expense_date' => '2026-05-02']);
        $this->deleteJson("/api/expense-categories/{$new['id']}")->assertUnprocessable();
        $this->putJson("/api/expense-categories/{$new['id']}", ['is_active' => false])->assertOk();
    }

    public function test_company_expenses_are_recorded_listed_and_guarded(): void
    {
        $rent = $this->item('إيجار مكتب');

        $id = $this->postJson('/api/company-expenses', [
            'expense_category_id' => $rent->id, 'amount' => 350, 'expense_date' => '2026-05-01',
            'description' => 'إيجار أيار', 'paid_via' => 'bank', 'reference' => 'TR-1',
        ])->assertCreated()->json('data.id');

        $list = $this->getJson('/api/company-expenses?from=2026-05-01&to=2026-05-31')->assertOk()->json();
        $this->assertSame(350.0, (float) $list['total']);
        $this->assertSame('موارد بشرية وإدارية › إيجار مكتب', $list['data'][0]['category_path']);

        // The one expenses screen lists it, borne by the company whole.
        $row = collect($this->getJson('/api/expenses?from=2026-05-01&to=2026-05-31')->assertOk()->json('rows'))->firstWhere('kind', 'company_expense');
        $this->assertSame(350.0, (float) $row['company']);
        $this->assertSame(0.0, (float) $row['driver']);
        $this->assertStringContainsString('إيجار مكتب', $row['label']);

        $this->putJson("/api/company-expenses/{$id}", ['amount' => 360])->assertOk();
        $this->assertSame(360.0, (float) CompanyExpense::find($id)->amount);

        // A role without the module may not record one.
        Role::create(['name' => 'إجازات', 'company_id' => $this->company->id, 'allowed_modules' => ['leaves.view']]);
        $clerk = User::create(['name' => 'Clerk', 'email' => 'clerk@pl.test', 'password' => bcrypt('x'), 'role' => 'إجازات', 'company_id' => $this->company->id]);
        $this->actingAs($clerk)->postJson('/api/company-expenses', ['expense_category_id' => $rent->id, 'amount' => 1, 'expense_date' => '2026-05-01'])->assertForbidden();
    }

    public function test_vehicle_fixed_costs_follow_ownership_and_dates(): void
    {
        $make = fn (array $attributes) => Vehicle::create($attributes + ['status' => 'available', 'company_id' => $this->company->id, 'vehicle_type_id' => 1]);

        $rented = $make(['plate_number' => 'R-1', 'ownership_type' => 'rented', 'rental_price' => 120]);
        $rentedFromMid = $make(['plate_number' => 'R-2', 'ownership_type' => 'rented', 'rental_price' => 124, 'rental_start_date' => '2026-05-17']);
        $paidOff = $make(['plate_number' => 'I-1', 'ownership_type' => 'installment', 'installment_price' => 100, 'installment_end_date' => '2026-04-30', 'purchase_price' => 9000, 'useful_life_months' => 60]);
        $owned = $make(['plate_number' => 'O-1', 'ownership_type' => 'owned', 'purchase_price' => 6000, 'useful_life_months' => 60, 'purchase_date' => '2026-01-10']);
        $notYet = $make(['plate_number' => 'O-2', 'ownership_type' => 'owned', 'purchase_price' => 6000, 'useful_life_months' => 60, 'purchase_date' => '2026-07-01']);
        $byHand = $make(['plate_number' => 'O-3', 'ownership_type' => 'asset', 'purchase_price' => 6000, 'useful_life_months' => 60, 'monthly_depreciation' => 75]);

        $fixed = VehicleFixedCostService::forMonth($this->company->id, 2026, 5)['vehicles'];

        $this->assertSame(120.0, $fixed[$rented->id]['rent'], 'no dates: the whole month');
        $this->assertSame(60.0, $fixed[$rentedFromMid->id]['rent'], 'May 17–31: 15 of 31 days');
        $this->assertArrayNotHasKey($paidOff->id, $fixed, 'instalments ended in April; a financed car is not depreciated');
        $this->assertSame(100.0, $fixed[$owned->id]['depreciation'], '6000 over 60 months');
        $this->assertArrayNotHasKey($notYet->id, $fixed, 'not bought yet');
        $this->assertSame(75.0, $fixed[$byHand->id]['depreciation'], 'the hand-entered figure wins');

        // The vehicle report lists an idle rented car for its rent, below the recorded costs.
        $report = $this->getJson('/api/reports/vehicle-profitability?year=2026&month=5')->assertOk()->json();
        $row = collect($report['vehicles'])->firstWhere('vehicle_id', $rented->id);
        $this->assertSame(120.0, (float) $row['fixed_costs']);
        $this->assertSame(round((float) $row['net_profit'] - 120, 3), (float) $row['net_after_fixed']);

        // The vehicle form saves the new fields.
        $this->putJson("/api/vehicles/{$owned->id}", ['purchase_price' => 6500, 'salvage_value' => 500, 'useful_life_months' => 60])->assertOk();
        $this->assertSame(100.0, VehicleFixedCostService::forMonth($this->company->id, 2026, 5)['vehicles'][$owned->id]['depreciation'], '(6500 − 500) ÷ 60');
    }

    public function test_the_profit_and_loss_reads_every_source_once(): void
    {
        // Spending of every kind in May.
        CompanyExpense::create(['expense_category_id' => $this->item('إيجار مكتب')->id, 'amount' => 200, 'expense_date' => '2026-05-05']);
        CompanyExpense::create(['expense_category_id' => $this->item('رسوم بنكية')->id, 'amount' => 3.5, 'expense_date' => '2026-05-06']);
        CompanyExpense::create(['expense_category_id' => $this->item('إيجار مكتب')->id, 'amount' => 999, 'expense_date' => '2026-06-01']);
        DriverExpense::create([
            'employee_id' => $this->driver->id, 'expense_type' => 'بنزين', 'amount' => 20, 'borne_by' => 'split',
            'company_amount' => 12, 'driver_amount' => 8, 'expense_date' => '2026-05-07',
        ]);
        Employee::create([
            'name' => 'محاسب', 'employee_number' => 'ADM-1', 'company_id' => $this->company->id, 'status' => 'active',
            'role_category' => 'admin', 'actual_salary' => 400, 'date_of_joining' => '2026-01-01',
        ]);
        Vehicle::create(['plate_number' => 'R-9', 'status' => 'available', 'company_id' => $this->company->id, 'vehicle_type_id' => 1, 'ownership_type' => 'rented', 'rental_price' => 120]);

        $pl = $this->getJson('/api/reports/profit-loss?year=2026&month=5')->assertOk()->json();
        $line = fn (array $lines, string $key) => (float) (collect($lines)->firstWhere('key', $key)['amount'] ?? 0);

        $this->assertSame(600.0, (float) $pl['revenue']['total']);
        $this->assertSame(100.0, $line($pl['direct_costs']['lines'], 'driver_salaries'));
        $this->assertSame(12.0, $line($pl['direct_costs']['lines'], 'driver_expenses'), 'the company share of a driver expense, never counted before');
        $this->assertSame(120.0, $line($pl['direct_costs']['lines'], 'vehicle_rent'));
        $this->assertSame(232.0, (float) $pl['direct_costs']['total']);
        $this->assertSame(368.0, (float) $pl['gross_profit']);

        $this->assertSame(400.0, $line($pl['admin_costs']['lines'], 'admin_salaries'));
        $hr = collect($pl['admin_costs']['lines'])->firstWhere('label', 'موارد بشرية وإدارية');
        $this->assertSame(203.5, (float) $hr['amount'], 'May only: the June rent waits for June');
        $this->assertSame(603.5, (float) $pl['admin_costs']['total']);
        $this->assertSame(-235.5, (float) $pl['net_profit']);
    }

    public function test_reading_the_statement_needs_the_reports_permission(): void
    {
        Role::create(['name' => 'إجازات', 'company_id' => $this->company->id, 'allowed_modules' => ['leaves.view']]);
        $clerk = User::create(['name' => 'Clerk', 'email' => 'clerk2@pl.test', 'password' => bcrypt('x'), 'role' => 'إجازات', 'company_id' => $this->company->id]);
        $this->actingAs($clerk)->getJson('/api/reports/profit-loss?year=2026&month=5')->assertForbidden();
    }
}
