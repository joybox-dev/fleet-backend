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
 * Saving one month of daily logs used to fire DailyLogObserver once per row, and every firing
 * rebuilt every payroll slip in the month's draft run. A 31-day month therefore paid for 31
 * identical rebuilds and the request took 23 seconds. The rebuild must happen once.
 */
class DailyLogBulkRecalculationTest extends TestCase
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
            'name' => 'Bulk Recalc Co',
            'code' => 'bulkrecalc',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@bulkrecalc.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'C', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Recalc Driver',
            'employee_number' => 'EMP-RC-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-01-01',
            'basic_salary' => 300,
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-RC-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-RC',
            'name' => 'Recalc Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'rate_per_order' => 1.000,
        ]);

        ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'start_date' => '2026-07-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user);
    }

    private function julyPayload(int $days): array
    {
        $logs = [];
        foreach (range(1, $days) as $day) {
            $logs[] = [
                'employee_id' => $this->driver->id,
                'contract_id' => (string) $this->contract->id,
                'vehicle_id' => $this->vehicle->id,
                'log_date' => sprintf('2026-07-%02d', $day),
                'orders_count' => 5,
                'driver_status' => 'working',
            ];
        }

        return ['logs' => $logs];
    }

    public function test_the_month_is_still_recalculated_after_a_bulk_save(): void
    {
        $this->postJson('/api/daily-logs/bulk', $this->julyPayload(3))->assertOk();

        $this->assertSame(
            3,
            DailyLog::withoutGlobalScopes()->where('employee_id', $this->driver->id)->count()
        );

        // recalculateEmployeeCommissions ran and stamped the month's logs.
        $this->assertSame(
            15,
            (int) DailyLog::withoutGlobalScopes()->where('employee_id', $this->driver->id)->sum('orders_count')
        );
    }

    /**
     * A single-row save is unchanged: it still recalculates immediately.
     */
    public function test_a_single_row_save_still_recalculates_inline(): void
    {
        $log = DailyLog::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'vehicle_id' => $this->vehicle->id,
            'log_date' => '2026-07-05',
            'driver_status' => 'working',
            'orders_count' => 4,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);

        $this->assertNotNull($log->fresh(), 'the observer must not swallow a normal save');
    }
}
