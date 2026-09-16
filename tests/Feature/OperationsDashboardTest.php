<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The operations dashboard, read from the supervisors' daily log.
 *
 * Today is 2026-09-15. «زون» pays the client 0.500 an order and the driver 10.000 a logged day;
 * «خاسر» bills 0.100 an order against the same driver cost, so it loses money on every day worked.
 */
class OperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');
        Cache::flush();

        $this->company = Company::create([
            'name' => 'Ops Co',
            'code' => 'opsco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@ops.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);

        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contract(string $name, float $clientPrice): Contract
    {
        return Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-'.uniqid(),
            'name' => $name,
            'payment_type' => 'fixed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'client_payment_method' => 'zones',
            'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'is_active' => true,
            'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => ['payment_method' => 'zones', 'zones' => [['id' => 'z1', 'name' => 'الفئة 1', 'price' => $clientPrice]]]],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function driver(string $name, ?Contract $contract, array $attributes = []): Employee
    {
        $driver = Employee::create([
            'name' => $name,
            'employee_number' => 'EMP-'.uniqid(),
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
            'official_salary' => 100.000,
        ] + $attributes);
        if ($contract) {
            ContractAssignment::create([
                'employee_id' => $driver->id,
                'contract_id' => $contract->id,
                'start_date' => '2026-01-01',
                'status' => 'active',
                'company_id' => $this->company->id,
            ]);
        }

        return $driver;
    }

    private function vehicle(Employee $driver, Contract $contract, string $status, string $plate): Vehicle
    {
        $vehicle = Vehicle::create([
            'plate_number' => $plate,
            'make' => 'Toyota',
            'status' => $status,
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);
        VehicleAssignment::create([
            'company_id' => $this->company->id,
            'vehicle_id' => $vehicle->id,
            'employee_id' => $driver->id,
            'contract_id' => $contract->id,
            'assigned_date' => '2026-01-01',
            'is_active' => true,
        ]);

        return $vehicle;
    }

    private function log(Employee $driver, Contract $contract, ?Vehicle $vehicle, string $date, string $status, int $orders = 0, float $cashPending = 0.0): void
    {
        DailyLog::create([
            'company_id' => $this->company->id,
            'employee_id' => $driver->id,
            'vehicle_id' => $vehicle?->id,
            'contract_id' => $contract->id,
            'log_date' => $date,
            'driver_status' => $status,
            'orders_count' => $orders,
            'orders_online' => $orders,
            'orders_cash' => 0,
            'income_amount' => 0,
            'cash_pending' => $cashPending,
            'created_by' => $this->admin->id,
            'notes' => $orders > 0 ? json_encode(['zone_orders' => ['z1' => $orders]]) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        return $this->getJson('/api/operations/dashboard')->assertOk()->json();
    }

    /** @return array<string, mixed> */
    private function rosterRow(array $dashboard, string $name): array
    {
        foreach ($dashboard['roster'] as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }
        $this->fail("{$name} is not on the roster");
    }

    public function test_the_day_is_read_off_the_log_and_every_count_comes_from_the_roster(): void
    {
        $zone = $this->contract('زون', 0.5);
        $loser = $this->contract('خاسر', 0.1);

        $a = $this->driver('Ahmad', $zone);                                            // works, 60 orders, cash out today
        $e = $this->driver('Emad', $zone);                                             // works, 10 orders; vehicle in the shop
        $b = $this->driver('Bilal', $zone);                                            // row says not working, no leave: absent
        $c = $this->driver('Chadi', $zone);                                            // paid leave on the row
        $d = $this->driver('Dawood', $zone);                                           // row not working, but an approved leave
        $f = $this->driver('Fahd', $loser, ['residence_expiry' => '2026-09-01']);      // works the losing contract, residence lapsed
        $this->driver('Ghassan', null);                                                // active, on no contract: expected nowhere

        $va = $this->vehicle($a, $zone, 'working', 'V-A');
        $ve = $this->vehicle($e, $zone, 'maintenance', 'V-E');
        $vc = $this->vehicle($c, $zone, 'working', 'V-C');
        $vd = $this->vehicle($d, $zone, 'working', 'V-D');
        $vf = $this->vehicle($f, $loser, 'working', 'V-F');

        foreach (['2026-09-13', '2026-09-14', '2026-09-15'] as $date) {
            $this->log($a, $zone, $va, $date, 'working', 60);
            $this->log($e, $zone, $ve, $date, 'working', 10);   // well under the contract's average: D, three days running
        }
        // Bilal's rows carry the plate he drove that day; no vehicle is assigned to him now.
        $this->log($b, $zone, $va, '2026-09-15', 'unpaid_leave');
        $this->log($b, $zone, $va, '2026-09-11', 'working', 20, 15.0);   // cash from four days ago, still out
        $this->log($c, $zone, $vc, '2026-09-15', 'paid_leave');
        $this->log($d, $zone, $vd, '2026-09-15', 'unpaid_leave');
        $this->log($f, $loser, $vf, '2026-09-15', 'working', 10);

        $a->dailyLogs()->whereDate('log_date', '2026-09-15')->update(['cash_pending' => 20.0]);

        $leaveType = LeaveType::create(['company_id' => $this->company->id, 'name' => 'Annual', 'name_ar' => 'سنوية', 'is_paid' => true]);
        EmployeeLeave::create([
            'company_id' => $this->company->id,
            'employee_id' => $d->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-16',
            'days_count' => 3,
            'status' => 'approved',
            'is_paid' => true,
        ]);

        $dash = $this->dashboard();

        $this->assertSame('2026-09-15', $dash['as_of']['day']);
        $this->assertTrue($dash['as_of']['is_today']);

        $kpis = $dash['kpis'];
        $this->assertSame(['count' => 3, 'of' => 7], $kpis['on_duty'], 'Ahmad, Emad and Fahd worked; seven active drivers');
        $this->assertSame(1, $kpis['absent_without_leave']['count'], 'only Bilal: Chadi is on paid leave, Dawood has an approved leave, Ghassan has no contract');
        $this->assertSame(2, $kpis['without_vehicle']['count'], 'Bilal has none, Emad\'s is in the shop');
        $this->assertSame(2, $kpis['cash_with_drivers']['drivers']);
        $this->assertSame(35.0, (float) $kpis['cash_with_drivers']['total']);

        $this->assertSame('not_working', $this->rosterRow($dash, 'Bilal')['status']);
        $this->assertTrue($this->rosterRow($dash, 'Bilal')['is_absent']);
        $this->assertSame('paid_leave', $this->rosterRow($dash, 'Chadi')['status']);
        $this->assertSame('approved_leave', $this->rosterRow($dash, 'Dawood')['status']);
        $this->assertFalse($this->rosterRow($dash, 'Dawood')['is_absent']);
        $this->assertSame('no_log', $this->rosterRow($dash, 'Ghassan')['status']);
        $this->assertFalse($this->rosterRow($dash, 'Ghassan')['is_absent'], 'no contract, so not expected anywhere');
        $this->assertSame(['إقامة منتهية'], $this->rosterRow($dash, 'Fahd')['issues']);
        $this->assertSame(['مركبته في الصيانة — بلا بديل'], $this->rosterRow($dash, 'Emad')['issues']);
        $this->assertSame('Bilal', $dash['roster'][0]['name'], 'the absent come first');

        $this->assertEqualsCanonicalizing(['maintenance', 'no_vehicle'], array_column($dash['without_vehicle'], 'problem'));

        // Cash: Ahmad's 20 from today, Bilal's 15 from four days ago — the late one is flagged per contract.
        $this->assertSame(['Ahmad', 'Bilal'], array_column($dash['cash'], 'name'));
        $this->assertSame(4, $dash['cash'][1]['days_open']);
        $this->assertCount(1, $dash['cash_close']);
        $this->assertSame(['late_drivers' => 1, 'recent_drivers' => 1], array_intersect_key($dash['cash_close'][0], ['late_drivers' => 1, 'recent_drivers' => 1]));
        $this->assertSame('زون', $dash['cash_close'][0]['contract_name']);

        // The losing contract: 10 orders at 0.100 against a 10.000 day. «زون» billed 115.000 for
        // 80.000 of driver days (Chadi's paid leave and Bilal's one day are paid too) and is not listed.
        $this->assertSame(['خاسر'], array_column($dash['losing_contracts'], 'name'));
        $this->assertSame(-9.0, (float) $dash['losing_contracts'][0]['contribution']);

        // Performance on the day: Ahmad 60 and Emad 10 average 35 on «زون» — 171% and 29%. Fahd is alone on his.
        $performance = $dash['performance'];
        $this->assertSame(['A' => 1, 'B' => 0, 'C' => 0, 'D' => 1, 'ungraded' => 1], $performance['grades']);
        $this->assertSame('Emad', $performance['worst'][0]['name']);
        $this->assertSame('D', $performance['worst'][0]['grade']);
        $this->assertSame(28.6, (float) $performance['worst'][0]['achievement_pct']);
        $this->assertSame('contract_average', $performance['worst'][0]['basis']);
        $this->assertSame([['name' => 'Emad', 'days' => 3]], array_map(fn ($r) => ['name' => $r['name'], 'days' => $r['consecutive_d_days']], $performance['repeated']));

        $this->assertFalse($dash['coverage']['shown'], 'no contract has a «required» count filled in');
    }

    public function test_before_today_is_entered_the_screen_describes_the_last_entered_day(): void
    {
        $zone = $this->contract('زون', 0.5);
        $a = $this->driver('Ahmad', $zone);
        $b = $this->driver('Bilal', $zone);
        $va = $this->vehicle($a, $zone, 'working', 'V-A');
        $vb = $this->vehicle($b, $zone, 'working', 'V-B');

        // The month's rows exist in advance; only the 12th has been entered.
        foreach (['2026-09-12', '2026-09-13', '2026-09-14', '2026-09-15', '2026-09-16'] as $date) {
            $this->log($a, $zone, $va, $date, $date === '2026-09-12' ? 'working' : 'unpaid_leave', $date === '2026-09-12' ? 25 : 0);
            $this->log($b, $zone, $vb, $date, $date === '2026-09-12' ? 'working' : 'unpaid_leave', $date === '2026-09-12' ? 15 : 0);
        }

        $dash = $this->dashboard();

        $this->assertSame('2026-09-12', $dash['as_of']['day']);
        $this->assertFalse($dash['as_of']['is_today']);
        $this->assertNotNull($dash['as_of']['last_entry_at']);
        $this->assertSame(2, $dash['kpis']['on_duty']['count']);
        $this->assertSame(0, $dash['kpis']['absent_without_leave']['count'], 'an un-entered today is not absence');
        $this->assertSame('2026-09-12', $dash['performance']['day']);
    }

    public function test_a_day_whose_entry_has_only_begun_is_named_but_not_read(): void
    {
        $zone = $this->contract('زون', 0.5);
        $drivers = [];
        foreach (['Ahmad', 'Bilal', 'Chadi', 'Dawood'] as $name) {
            $driver = $this->driver($name, $zone);
            $drivers[] = [$driver, $this->vehicle($driver, $zone, 'working', 'V-'.$name)];
        }
        // The 14th was entered for all four; on the 15th the supervisor has turned one row so far.
        foreach ($drivers as $i => [$driver, $vehicle]) {
            $this->log($driver, $zone, $vehicle, '2026-09-14', 'working', 20);
            $this->log($driver, $zone, $vehicle, '2026-09-15', $i === 0 ? 'working' : 'unpaid_leave', $i === 0 ? 20 : 0);
        }

        $dash = $this->dashboard();

        $this->assertSame('2026-09-14', $dash['as_of']['day']);
        $this->assertSame(['day' => '2026-09-15', 'working' => 1], $dash['as_of']['entry_in_progress']);
        $this->assertSame(4, $dash['kpis']['on_duty']['count']);
        $this->assertSame(0, $dash['kpis']['absent_without_leave']['count'], 'three untouched rows on a half-entered day are not absences');

        // The manager may still ask for the day in progress by name.
        $picked = $this->getJson('/api/operations/dashboard?day=2026-09-15')->assertOk()->json();
        $this->assertSame('2026-09-15', $picked['as_of']['day']);
        $this->assertTrue($picked['as_of']['requested']);
        $this->assertSame(1, $picked['kpis']['on_duty']['count']);
        $this->assertSame(3, $picked['kpis']['absent_without_leave']['count']);

        $this->getJson('/api/operations/dashboard?day=2026-09-16')->assertStatus(422);
    }

    public function test_a_personal_target_grades_the_driver_against_his_own_daily_share(): void
    {
        $zone = $this->contract('زون', 0.5);
        $a = $this->driver('Ahmad', $zone, ['target_orders_monthly' => 520]);   // 20 a day over 26 days
        $va = $this->vehicle($a, $zone, 'working', 'V-A');
        $this->log($a, $zone, $va, '2026-09-15', 'working', 17);               // 85% of 20: B

        $performance = $this->dashboard()['performance'];

        $this->assertSame(['A' => 0, 'B' => 1, 'C' => 0, 'D' => 0, 'ungraded' => 0], $performance['grades']);
        $this->assertSame('personal_target', $performance['worst'][0]['basis']);
        $this->assertSame(85.0, (float) $performance['worst'][0]['achievement_pct']);
        $this->assertSame(20.0, (float) $performance['worst'][0]['target']);
    }
}
