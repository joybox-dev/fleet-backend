<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The golden file is the last line of defence for the real data: a snapshot must verify against
 * itself, and a single changed figure must fail the verification by name.
 */
class PayrollGoldenCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $driver;

    private Contract $contract;

    private Vehicle $vehicle;

    private User $admin;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Golden Co',
            'code' => 'goldco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@gold.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        $client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Golden Driver',
            'employee_number' => 'EMP-G-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-G-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-G',
            'name' => 'Golden Contract',
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

        foreach (['2026-05-04', '2026-05-05'] as $date) {
            $this->workDay($date);
        }

        $this->file = sys_get_temp_dir().'/golden-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    private function workDay(string $date): void
    {
        DailyLog::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'vehicle_id' => $this->vehicle->id,
            'log_date' => $date,
            'driver_status' => 'working',
            'orders_count' => 0,
            'company_id' => $this->company->id,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_a_snapshot_verifies_against_itself_and_a_changed_figure_fails_by_name(): void
    {
        $this->artisan('payroll:golden', ['action' => 'snapshot', '--file' => $this->file])
            ->assertExitCode(0);

        $golden = json_decode((string) file_get_contents($this->file), true);
        $sheet = $golden['companies'][$this->company->id]['sheets']["{$this->contract->id}|2026-05"];
        $this->assertSame(20.0, (float) $sheet['drivers'][0]['gross_contract_earnings'], 'two days at 10.000');
        // The statement runs from the first logged month to the current one, newest first.
        $may = collect($golden['companies'][$this->company->id]['ledger'][$this->driver->id]['months'])->firstWhere('label', '05/2026');
        $this->assertSame(20.0, (float) $may['net_payout']);

        $this->artisan('payroll:golden', ['action' => 'verify', '--file' => $this->file])
            ->expectsOutputToContain('identical')
            ->assertExitCode(0);

        // A third worked day changes the driver's month; the verification must say so, by driver.
        $this->workDay('2026-05-06');

        $this->artisan('payroll:golden', ['action' => 'verify', '--file' => $this->file])
            ->expectsOutputToContain('gross_contract_earnings: 20 → 30')
            ->assertExitCode(1);
    }

    public function test_verify_refuses_to_run_without_a_golden_file(): void
    {
        $this->artisan('payroll:golden', ['action' => 'verify', '--file' => $this->file])
            ->assertExitCode(1);
    }
}
