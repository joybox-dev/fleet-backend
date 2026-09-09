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
 * Saving a month of daily logs writes every row, once, and nothing else happens on the way.
 *
 * Every saved row used to fire DailyLogObserver, which rebuilt every slip in the retired legacy
 * payroll run — 31 identical rebuilds for a 31-day month, 23 seconds a request — and stamped a
 * `driver_commission` column that only the reports read, and then trusted over the payroll sheet.
 * The observer no longer recalculates anything: a month is priced when a screen asks for it, from
 * the logs as they stand.
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
        ]);

        $client = Client::create(['name' => 'C', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Recalc Driver',
            'employee_number' => 'EMP-RC-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
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

    public function test_a_bulk_save_writes_every_row_once(): void
    {
        $this->postJson('/api/daily-logs/bulk', $this->julyPayload(3))->assertOk();

        $rows = DailyLog::withoutGlobalScopes()->where('employee_id', $this->driver->id)->get();

        $this->assertCount(3, $rows);
        $this->assertSame(15, (int) $rows->sum('orders_count'));

        // Saved again, the same three days are replaced, not duplicated.
        $this->postJson('/api/daily-logs/bulk', $this->julyPayload(3))->assertOk();
        $this->assertSame(3, DailyLog::withoutGlobalScopes()->where('employee_id', $this->driver->id)->count());
    }

    public function test_nothing_is_stamped_on_the_row_by_the_save(): void
    {
        $this->postJson('/api/daily-logs/bulk', $this->julyPayload(1))->assertOk();

        $log = DailyLog::withoutGlobalScopes()->where('employee_id', $this->driver->id)->firstOrFail();

        // The retired engine's columns stay empty: the month is priced when it is read.
        $this->assertEquals(0.0, (float) $log->driver_commission);
        $this->assertEquals(0.0, (float) $log->income_amount);
    }

    public function test_a_single_row_save_is_unaffected(): void
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
