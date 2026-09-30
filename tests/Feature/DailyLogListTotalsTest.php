<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The daily-log list ends in a totals row. It totals every log the filters match — the list is
 * paged, and a row that summed the fifty logs on screen would read as the month's figure.
 */
class DailyLogListTotalsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Employee $driver;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Totals Co',
            'code' => 'totalsco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@totals.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->driver = Employee::create([
            'name' => 'سائق المجموع',
            'employee_number' => 'D-TOTALS',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
        ]);
        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-TOTALS',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);
    }

    private function contract(string $name): Contract
    {
        return Contract::create([
            'client_id' => Client::create(['name' => "Client {$name}", 'company_id' => $this->company->id])->id,
            'contract_number' => 'CON-'.uniqid(),
            'name' => $name,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'is_active' => true,
            'default_required_work_days' => 26,
        ]);
    }

    private function log(Contract $contract, string $date, int $orders, float $cash = 0.0, float $pending = 0.0): void
    {
        DailyLog::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'contract_id' => $contract->id,
            'log_date' => $date,
            'driver_status' => 'working',
            'orders_count' => $orders,
            'online_hours' => 8,
            'cash_collected' => $cash,
            'cash_pending' => $pending,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_the_totals_cover_every_matching_log_not_the_page_on_screen(): void
    {
        $contract = $this->contract('عقد المجموع');
        foreach (range(1, 8) as $day) {
            $this->log($contract, sprintf('2026-09-%02d', $day), 10, 2.5, 1.0);
        }

        // Five logs a page: the page carries 50 orders, the totals carry all 80.
        $page = $this->getJson('/api/daily-logs?per_page=5')->assertOk()->json();

        $this->assertCount(5, $page['data']);
        $this->assertSame(8, $page['total'], 'the pager still counts the logs');
        $this->assertSame(['logs' => 8, 'orders' => 80, 'online_hours' => 64, 'cash_collected' => 20, 'cash_pending' => 8], $page['totals']);
    }

    public function test_the_totals_follow_the_filters(): void
    {
        $first = $this->contract('الأول');
        $second = $this->contract('الثاني');
        $this->log($first, '2026-09-01', 10);
        $this->log($first, '2026-09-02', 20);
        $this->log($second, '2026-09-01', 7);

        $this->assertSame(37, $this->getJson('/api/daily-logs')->assertOk()->json('totals.orders'));
        $this->assertSame(30, $this->getJson("/api/daily-logs?contract_id={$first->id}")->assertOk()->json('totals.orders'));
        $this->assertSame(17, $this->getJson('/api/daily-logs?date_from=2026-09-01&date_to=2026-09-01')->assertOk()->json('totals.orders'));
        $this->assertSame(7, $this->getJson('/api/daily-logs?search='.urlencode('الثاني'))->assertOk()->json('totals.orders'));

        // Nothing matches: zeros, not nulls.
        $this->assertSame(
            ['logs' => 0, 'orders' => 0, 'online_hours' => 0, 'cash_collected' => 0, 'cash_pending' => 0],
            $this->getJson('/api/daily-logs?date_from=2027-01-01')->assertOk()->json('totals')
        );
    }
}
