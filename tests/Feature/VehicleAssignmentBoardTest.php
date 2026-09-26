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
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The assignment board («إسناد المركبات»): drivers and vehicles side by side, each saying what it
 * holds, and one driver never holding two vehicles once a new one is assigned to him.
 *
 * Fixture: two cars and one in maintenance; a driver holding car A (who used to hold car B), a
 * driver with no vehicle but a current contract, and a driver who left with nothing in hand.
 */
class VehicleAssignmentBoardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Vehicle $carA;

    private Vehicle $carB;

    private Vehicle $inShop;

    private Employee $holder;

    private Employee $free;

    private Employee $gone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Board Co',
            'code' => 'boardco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = $this->login('admin', 'admin');

        $type = VehicleType::forceCreate(['company_id' => $this->company->id, 'name' => 'Small Car', 'name_ar' => 'سيارة صغيرة']);

        $this->carA = $this->vehicle('A-1', $type->id, 'working');
        $this->carB = $this->vehicle('B-2', $type->id, 'available');
        $this->inShop = $this->vehicle('C-3', $type->id, 'maintenance');

        $this->holder = $this->driver('Holder', 'active');
        $this->free = $this->driver('Free', 'probation');
        $this->gone = $this->driver('Gone', 'inactive');

        VehicleAssignment::forceCreate([
            'company_id' => $this->company->id, 'vehicle_id' => $this->carA->id, 'employee_id' => $this->holder->id,
            'assigned_date' => '2026-09-01', 'is_active' => true,
        ]);
        VehicleAssignment::forceCreate([
            'company_id' => $this->company->id, 'vehicle_id' => $this->carB->id, 'employee_id' => $this->holder->id,
            'assigned_date' => '2026-06-01', 'unassigned_date' => '2026-08-01', 'is_active' => false,
        ]);

        $client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);
        $contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-BOARD',
            'name' => 'Contract X',
            'payment_type' => 'fixed',
            'start_date' => '2026-01-01',
            'client_payment_method' => 'fixed',
            'driver_payment_method' => 'fixed',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);
        ContractAssignment::forceCreate([
            'company_id' => $this->company->id, 'employee_id' => $this->free->id, 'contract_id' => $contract->id,
            'start_date' => '2026-01-01', 'status' => 'active',
        ]);
    }

    public function test_the_board_shows_every_driver_and_vehicle_with_what_it_holds(): void
    {
        $board = $this->actingAs($this->admin)->getJson('/api/vehicles/assignment-board')->assertStatus(200)->json();

        // The driver who left holds nothing, so he is not a candidate.
        $this->assertSame([$this->free->id, $this->holder->id], array_column($board['drivers'], 'id'));

        $holder = collect($board['drivers'])->firstWhere('id', $this->holder->id);
        $this->assertSame('A-1', $holder['vehicle']['plate_number']);
        $this->assertSame('سيارة صغيرة', $holder['vehicle']['type']);
        $this->assertSame([], $holder['contracts']);

        $free = collect($board['drivers'])->firstWhere('id', $this->free->id);
        $this->assertNull($free['vehicle']);
        $this->assertSame(['Contract X'], $free['contracts']);

        $vehicles = collect($board['vehicles'])->keyBy('plate_number');
        $this->assertSame('Holder', $vehicles['A-1']['driver']['name']);
        $this->assertNull($vehicles['B-2']['driver']);
        $this->assertSame('Holder', $vehicles['B-2']['last_driver']);
        $this->assertSame('2026-08-01', $vehicles['B-2']['last_unassigned_date']);
        $this->assertSame('maintenance', $vehicles['C-3']['status']);

        $this->assertSame([
            'drivers_without_vehicle' => 1,
            'drivers_with_vehicle' => 1,
            'free_vehicles' => 1,
            'held_vehicles' => 1,
        ], $board['summary']);
        $this->assertSame(['سيارة صغيرة'], array_column($board['types'], 'name'));
    }

    public function test_either_side_viewer_may_open_the_board_and_nobody_else(): void
    {
        Role::create(['name' => 'مركبات فقط', 'company_id' => $this->company->id, 'allowed_modules' => ['vehicles.view']]);
        Role::create(['name' => 'موظفون فقط', 'company_id' => $this->company->id, 'allowed_modules' => ['employees.view']]);
        Role::create(['name' => 'إجازات فقط', 'company_id' => $this->company->id, 'allowed_modules' => ['leaves.view']]);

        $this->actingAs($this->login('v', 'مركبات فقط'))->getJson('/api/vehicles/assignment-board')->assertStatus(200);
        $this->actingAs($this->login('e', 'موظفون فقط'))->getJson('/api/vehicles/assignment-board')->assertStatus(200);
        $this->actingAs($this->login('l', 'إجازات فقط'))->getJson('/api/vehicles/assignment-board')->assertStatus(403);
    }

    public function test_assigning_a_driver_a_second_vehicle_releases_the_first(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/vehicles/{$this->carB->id}/assign", ['employee_id' => $this->holder->id, 'assigned_date' => '2026-09-22'])
            ->assertStatus(201);

        $this->assertDatabaseHas('vehicle_assignments', ['vehicle_id' => $this->carA->id, 'is_active' => 0, 'unassigned_date' => '2026-09-22']);
        $this->assertDatabaseHas('vehicle_assignments', ['vehicle_id' => $this->carB->id, 'employee_id' => $this->holder->id, 'is_active' => 1]);
        $this->assertSame('available', $this->carA->fresh()->status);
        $this->assertSame('working', $this->carB->fresh()->status);

        $board = $this->actingAs($this->admin)->getJson('/api/vehicles/assignment-board')->json();
        $this->assertSame('B-2', collect($board['drivers'])->firstWhere('id', $this->holder->id)['vehicle']['plate_number']);
        $this->assertSame('Holder', collect($board['vehicles'])->firstWhere('plate_number', 'A-1')['last_driver']);
    }

    public function test_the_employee_list_can_show_only_the_drivers_without_a_vehicle(): void
    {
        $without = array_column($this->actingAs($this->admin)->getJson('/api/employees?without_vehicle=1&per_page=100')->json('data'), 'id');
        $this->assertContains($this->free->id, $without);
        $this->assertNotContains($this->holder->id, $without);

        $with = array_column($this->actingAs($this->admin)->getJson('/api/employees?with_vehicle=1&per_page=100')->json('data'), 'id');
        $this->assertSame([$this->holder->id], $with);
    }

    public function test_another_company_stays_off_the_board(): void
    {
        $elsewhere = Company::create(['name' => 'Elsewhere', 'code' => 'elsewhere', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true]);
        Vehicle::forceCreate(['company_id' => $elsewhere->id, 'plate_number' => 'Z-9', 'make' => 'X', 'model' => 'Y', 'status' => 'available']);
        Employee::forceCreate([
            'company_id' => $elsewhere->id, 'name' => 'Stranger', 'date_of_joining' => '2026-01-01', 'pay_type' => 'fixed',
            'official_salary' => 100, 'actual_salary' => 100, 'role_category' => 'driver', 'status' => 'active',
        ]);

        $board = $this->actingAs($this->admin)->getJson('/api/vehicles/assignment-board')->json();
        $this->assertNotContains('Z-9', array_column($board['vehicles'], 'plate_number'));
        $this->assertNotContains('Stranger', array_column($board['drivers'], 'name'));
    }

    private function login(string $handle, string $role): User
    {
        return User::create([
            'name' => ucfirst($handle),
            'email' => "{$handle}@board.test",
            'password' => bcrypt('password'),
            'role' => $role,
            'company_id' => $this->company->id,
        ]);
    }

    private function vehicle(string $plate, int $typeId, string $status): Vehicle
    {
        return Vehicle::forceCreate([
            'company_id' => $this->company->id, 'plate_number' => $plate, 'make' => 'Make', 'model' => 'Model',
            'vehicle_type_id' => $typeId, 'status' => $status,
        ]);
    }

    private function driver(string $name, string $status): Employee
    {
        return Employee::forceCreate([
            'company_id' => $this->company->id, 'name' => $name, 'date_of_joining' => '2026-01-01', 'pay_type' => 'fixed',
            'official_salary' => 120, 'actual_salary' => 120, 'role_category' => 'driver', 'status' => $status,
        ]);
    }
}
