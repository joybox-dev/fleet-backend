<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Services\InventoryReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The stock-take: every administrative employee, driver, vehicle and contract, as things stand
 * today, with the counts on top and the places where the records contradict themselves named.
 *
 * Fixture, read on 2026-09-18:
 *   contracts  «جارٍ» runs from January with no end · «منتهٍ» ended on 31 March and is still active
 *   staff      one accountant whose residence lapsed in August
 *   drivers    Ahmad  active, on «جارٍ», holds V-1
 *              Bilal  on probation, no contract, no vehicle, licence lapsed
 *              Careem inactive — and still assigned to «جارٍ»
 *              Dawood active, his only assignment ended in March but is still flagged active
 *   vehicles   V-1 working with Ahmad · V-2 available, insurance lapsed · V-3 held by an authority
 */
class InventoryReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Contract $running;

    private Contract $ended;

    /** @var array<string, Employee> */
    private array $people = [];

    /** @var array<string, Vehicle> */
    private array $vehicles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-18 10:00:00');

        $this->company = Company::create([
            'name' => 'Inventory Co',
            'code' => 'invco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@inv.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Client', 'name_ar' => 'العميل', 'company_id' => $this->company->id]);
        $this->running = $this->contract($client, 'جارٍ', 'C-1', '2026-01-01', null);
        $this->ended = $this->contract($client, 'منتهٍ', 'C-2', '2026-01-01', '2026-03-31');

        $this->people['accountant'] = $this->employee('Accountant', 'admin', 'active', ['residence_expiry' => '2026-08-01']);
        $this->people['ahmad'] = $this->employee('Ahmad', 'driver', 'active', ['driving_license_expiry' => '2027-01-01']);
        $this->people['bilal'] = $this->employee('Bilal', 'driver', 'probation', ['driving_license_expiry' => '2026-09-01', 'employee_type' => 'overseas']);
        $this->people['careem'] = $this->employee('Careem', 'driver', 'inactive');
        $this->people['dawood'] = $this->employee('Dawood', 'driver', 'active');

        $this->assign('ahmad', $this->running, '2026-01-01', null);
        $this->assign('careem', $this->running, '2026-01-01', null);
        $this->assign('dawood', $this->ended, '2026-01-01', '2026-03-31');

        $this->vehicles['v1'] = $this->vehicle('V-1', 'working', ['insurance_expiry' => '2027-01-01']);
        $this->vehicles['v2'] = $this->vehicle('V-2', 'available', ['insurance_expiry' => '2026-09-17', 'ownership_type' => 'rented']);
        $this->vehicles['v3'] = $this->vehicle('V-3', 'reserved', ['reserved_by' => 'المرور', 'reserved_until' => '2026-10-01']);

        VehicleAssignment::create([
            'vehicle_id' => $this->vehicles['v1']->id,
            'employee_id' => $this->people['ahmad']->id,
            'contract_id' => $this->running->id,
            'assigned_date' => '2026-01-01',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contract(Client $client, string $name, string $number, string $start, ?string $end): Contract
    {
        return Contract::create([
            'client_id' => $client->id,
            'contract_number' => $number,
            'name' => $name,
            'payment_type' => 'fixed',
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'active',
            'is_active' => true,
            'client_payment_method' => 'fixed',
            'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'required_drivers' => 3,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function employee(string $name, string $category, string $status, array $extra = []): Employee
    {
        return Employee::create($extra + [
            'name' => $name,
            'employee_number' => 'EMP-'.strtoupper($name),
            'company_id' => $this->company->id,
            'status' => $status,
            'role_category' => $category,
            'employee_type' => 'local_transfer',
            'date_of_joining' => '2026-01-01',
        ]);
    }

    private function assign(string $who, Contract $contract, string $start, ?string $end): void
    {
        ContractAssignment::create([
            'employee_id' => $this->people[$who]->id,
            'contract_id' => $contract->id,
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function vehicle(string $plate, string $status, array $extra = []): Vehicle
    {
        return Vehicle::create($extra + [
            'plate_number' => $plate,
            'make' => 'Toyota',
            'status' => $status,
            'ownership_type' => 'owned',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);
    }

    /** @param  array<int, array{key: string, count: int}>  $tally */
    private function counts(array $tally): array
    {
        return collect($tally)->pluck('count', 'key')->all();
    }

    public function test_it_counts_what_the_company_has_and_who_holds_what(): void
    {
        $report = $this->getJson('/api/reports/inventory')->assertOk()->json();

        $this->assertSame('2026-09-18', $report['as_of']);

        $staff = $report['summary']['staff'];
        $this->assertSame(1, $staff['total']);
        $this->assertSame(1, $staff['expired_documents']);

        $drivers = $report['summary']['drivers'];
        $this->assertSame(4, $drivers['total']);
        $this->assertSame(['active' => 2, 'probation' => 1, 'inactive' => 1], $this->counts($drivers['by_status']));
        $this->assertSame(['local_transfer' => 3, 'overseas' => 1], $this->counts($drivers['by_type']));
        $this->assertSame(3, $drivers['in_service'], 'active and on probation — the inactive one is not expected to work');
        $this->assertSame(1, $drivers['on_contract'], 'Ahmad alone: Dawood\'s assignment ended in March');
        $this->assertSame(2, $drivers['without_contract']);
        $this->assertSame(1, $drivers['with_vehicle']);
        $this->assertSame(2, $drivers['without_vehicle']);
        $this->assertSame(1, $drivers['expired_documents']);

        $vehicles = $report['summary']['vehicles'];
        $this->assertSame(3, $vehicles['total']);
        $this->assertSame(['working' => 1, 'available' => 1, 'reserved' => 1], $this->counts($vehicles['by_status']));
        $this->assertSame(['owned' => 2, 'rented' => 1], $this->counts($vehicles['by_ownership']));
        $this->assertSame(1, $vehicles['with_driver']);
        $this->assertSame(2, $vehicles['without_driver']);
        $this->assertSame(1, $vehicles['expired_documents']);

        $contracts = $report['summary']['contracts'];
        $this->assertSame(2, $contracts['total']);
        $this->assertSame(1, $contracts['running']);
        $this->assertSame(1, $contracts['expired_but_active']);
        $this->assertSame(2, $contracts['drivers_assigned'], 'Ahmad and Careem — by the dates, not by the flag');
    }

    public function test_every_row_says_where_it_stands_today(): void
    {
        $report = $this->getJson('/api/reports/inventory')->assertOk()->json();
        $driver = fn (string $name) => collect($report['drivers'])->firstWhere('name', $name);
        $vehicle = fn (string $plate) => collect($report['vehicles'])->firstWhere('plate_number', $plate);

        $this->assertSame(['الإقامة'], $report['staff'][0]['expired_documents']);

        $ahmad = $driver('Ahmad');
        $this->assertSame(['جارٍ'], $ahmad['contracts']);
        $this->assertSame('V-1', $ahmad['vehicle_plate']);
        $this->assertSame([], $ahmad['expired_documents']);
        $this->assertFalse($ahmad['holds_while_inactive']);

        $this->assertSame(['رخصة القيادة'], $driver('Bilal')['expired_documents']);
        $this->assertSame([], $driver('Bilal')['contracts']);
        $this->assertNull($driver('Bilal')['vehicle_plate']);

        // Out of service, and the contract still lists him.
        $this->assertTrue($driver('Careem')['holds_while_inactive']);
        // The flag says active; the dates say March. The dates are what payroll pays by.
        $this->assertSame([], $driver('Dawood')['contracts']);

        $this->assertSame('Ahmad', $vehicle('V-1')['driver_name']);
        $this->assertSame('جارٍ', $vehicle('V-1')['contract_names']);
        $this->assertSame(['التأمين'], $vehicle('V-2')['expired_documents'], 'lapsed yesterday');
        $this->assertNull($vehicle('V-2')['driver_name']);
        $this->assertSame('المرور', $vehicle('V-3')['reserved_by']);
        $this->assertSame('2026-10-01', $vehicle('V-3')['reserved_until']);
        $this->assertSame([], $vehicle('V-3')['expired_documents'], 'a document with no date says nothing');

        $running = collect($report['contracts'])->firstWhere('name', 'جارٍ');
        $this->assertSame('العميل', $running['client']);
        $this->assertSame('running', $running['date_state']);
        $this->assertSame(2, $running['drivers_count']);
        $this->assertSame(1, $running['vehicles_count']);
        $this->assertSame(3, $running['required_drivers']);
        $this->assertSame(['fixed'], $running['driver_payment_methods'], 'no pricing rules: the contract\'s own column');

        // The method is read from the pricing rules, one per vehicle type — not from the column.
        $this->running->update(['driver_pricing_rules' => ['1' => ['payment_method' => 'tiers'], '2' => ['payment_method' => 'zones'], '3' => ['payment_method' => 'tiers']]]);
        $priced = collect($this->getJson('/api/reports/inventory')->json('contracts'))->firstWhere('name', 'جارٍ');
        $this->assertSame(['tiers', 'zones'], $priced['driver_payment_methods']);

        $ended = collect($report['contracts'])->firstWhere('name', 'منتهٍ');
        $this->assertSame('expired', $ended['date_state']);
        $this->assertSame(0, $ended['drivers_count']);

        // Where the records contradict themselves.
        $this->assertSame(1, $report['notes']['lapsed_assignments']);
        $this->assertSame(1, $report['notes']['lapsed_assignment_drivers']);
        $this->assertSame(1, $report['notes']['inactive_still_holding']);
        $this->assertSame(['منتهٍ'], $report['notes']['contracts_expired_but_active']);
    }

    public function test_each_list_is_shown_only_to_a_reader_who_may_open_its_screen(): void
    {
        $role = Role::create(['name' => 'مراقب الأسطول', 'company_id' => $this->company->id, 'allowed_modules' => ['reports', 'vehicles']]);
        $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@inv.test', 'password' => bcrypt('password'), 'role' => 'مراقب الأسطول', 'company_id' => $this->company->id]);
        Employee::create(['name' => 'Viewer', 'employee_number' => 'EMP-V', 'company_id' => $this->company->id, 'status' => 'active', 'role_category' => 'admin', 'admin_role_id' => $role->id, 'user_id' => $viewer->id, 'date_of_joining' => '2026-01-01']);

        $report = $this->actingAs($viewer)->getJson('/api/reports/inventory')->assertOk()->json();

        $this->assertCount(3, $report['vehicles']);
        $this->assertNull($report['staff']);
        $this->assertNull($report['drivers']);
        $this->assertNull($report['contracts']);
        $this->assertNull($report['summary']['drivers']);
        $this->assertSame(3, $report['summary']['vehicles']['total']);
        $this->assertSame(0, $report['notes']['lapsed_assignments'], 'nothing about a list he cannot see');

        // And no reports module, no report.
        $role->update(['allowed_modules' => ['vehicles']]);
        $this->actingAs($viewer->fresh())->getJson('/api/reports/inventory')->assertStatus(403);
    }

    public function test_a_supervisor_sees_his_own_drivers_and_contracts(): void
    {
        $report = InventoryReportService::build(
            $this->company->id,
            Carbon::today(),
            [],
            [$this->people['ahmad']->id],
            [$this->running->id],
        );

        $this->assertSame(['Ahmad'], array_column($report['drivers'], 'name'));
        $this->assertSame([], $report['staff']);
        $this->assertSame(['جارٍ'], array_column($report['contracts'], 'name'));
        $this->assertSame(1, collect($report['contracts'])->first()['drivers_count'], 'Careem is not one of his');
    }

    public function test_another_company_is_never_counted(): void
    {
        // company_id is not mass-assignable — the tenant trait fills it — so it is forced here.
        $other = Company::create(['name' => 'Other Co', 'code' => 'other', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true]);
        Employee::forceCreate(['name' => 'Stranger', 'employee_number' => 'EMP-X', 'company_id' => $other->id, 'status' => 'active', 'role_category' => 'driver', 'date_of_joining' => '2026-01-01']);
        Vehicle::forceCreate(['plate_number' => 'X-1', 'make' => 'Kia', 'status' => 'available', 'company_id' => $other->id, 'vehicle_type_id' => 1]);

        $report = $this->getJson('/api/reports/inventory')->assertOk()->json();

        $this->assertSame(4, $report['summary']['drivers']['total']);
        $this->assertSame(3, $report['summary']['vehicles']['total']);
    }
}
