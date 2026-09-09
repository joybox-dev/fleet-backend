<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\ContractPayrollAdjustment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\EmployeeLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The driver's statement has to be readable line by line: every figure between the month's
 * opening and closing balance is a line, so the lines add up to the closing figure.
 */
class EmployeeStatementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Employee $driver;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Statement Co',
            'code' => 'stmtco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@stmt.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Statement Driver',
            'employee_number' => 'EMP-ST-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
        ]);

        $vehicle = Vehicle::create([
            'plate_number' => 'V-ST-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-ST',
            'name' => 'Statement Contract',
            'payment_type' => 'fixed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed',
            'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);

        ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        // Three worked days: 30.000 gross.
        foreach (['2026-04-01', '2026-04-02', '2026-04-03'] as $date) {
            DailyLog::create([
                'employee_id' => $this->driver->id,
                'contract_id' => $this->contract->id,
                'vehicle_id' => $vehicle->id,
                'log_date' => $date,
                'driver_status' => 'working',
                'orders_count' => 0,
                'company_id' => $this->company->id,
                'created_by' => $this->admin->id,
            ]);
        }

        // A bonus and a write-off agreed on the contract for the month.
        foreach ([['addition', 7.5, 'إكرامية'], ['deduction', 4, 'تارجت']] as [$type, $amount, $reason]) {
            ContractPayrollAdjustment::create([
                'company_id' => $this->company->id,
                'contract_id' => $this->contract->id,
                'employee_id' => $this->driver->id,
                'year' => 2026,
                'month' => 4,
                'type' => $type,
                'amount' => $amount,
                'reason' => $reason,
                'created_by' => $this->admin->id,
            ]);
        }

        $this->actingAs($this->admin);
    }

    public function test_an_approved_month_lists_its_manual_settlements_line_by_line(): void
    {
        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", ['year' => 2026, 'month' => 4])->assertOk();
        $this->postJson('/api/payroll/consolidated/2026/4/approve')->assertOk();

        $history = EmployeeLedgerService::history($this->driver, '2026-04', '2026-04');
        $april = collect($history['months'])->firstWhere('label', '04/2026');

        $this->assertSame('approved', $april['status']);
        $this->assertSame(30.0, (float) $april['gross_earnings']);
        $this->assertSame(3.5, (float) $april['manual_adjustments']);

        $items = collect($april['manual_adjustment_items']);
        $this->assertCount(2, $items);
        $this->assertSame([7.5, -4.0], $items->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(['إكرامية', 'تارجت'], $items->pluck('reason')->all());
        $this->assertSame('Statement Contract', $items[0]['contract_name']);

        // The lines the statement draws — opening, contracts, settlements, charges — reach the
        // month's closing figure exactly.
        $lines = (float) $april['carried_in']
            + collect($april['contracts'])->sum('gross')
            + $items->sum('amount')
            - collect($april['deduction_items'])->sum('amount');
        $this->assertSame(round($lines, 3), (float) $april['closing_balance']);
        $this->assertSame(33.5, (float) $april['closing_balance']);
    }
}
