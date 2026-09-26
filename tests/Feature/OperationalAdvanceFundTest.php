<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * An administrative employee can be handed a cash float («رصيد») and give operational custodies
 * to other administrative employees out of it; every custody he gives comes off the float, every
 * return on one of them goes back to it, and each employee sees only the custodies that are his.
 *
 * Cast: the custodian holds «إعطاء رصيد» and nothing else of the module; the giver may create
 * custodies; the two receivers may only view (and so request); the owner is a plain admin login
 * with no employee record, whose custodies come from the company as they always did.
 */
class OperationalAdvanceFundTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $custodian;

    private User $owner;

    private User $giverLogin;

    private Employee $giver;

    private User $receiverLogin;

    private Employee $receiver;

    private User $otherLogin;

    private Employee $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Float Co',
            'code' => 'floatco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        Role::create(['name' => 'أمين الصندوق', 'company_id' => $this->company->id, 'allowed_modules' => ['op_advances.view', 'op_advances.fund', 'employees.view']]);
        Role::create(['name' => 'موزّع', 'company_id' => $this->company->id, 'allowed_modules' => ['op_advances.view', 'op_advances.create']]);
        Role::create(['name' => 'مستلم', 'company_id' => $this->company->id, 'allowed_modules' => ['op_advances.view']]);

        $this->custodian = $this->login('custodian', 'أمين الصندوق');
        $this->owner = $this->login('owner', 'admin');

        [$this->giverLogin, $this->giver] = $this->adminEmployee('giver', 'موزّع');
        [$this->receiverLogin, $this->receiver] = $this->adminEmployee('receiver', 'مستلم');
        [$this->otherLogin, $this->other] = $this->adminEmployee('other', 'مستلم');
    }

    public function test_the_custodian_funds_a_float_and_the_giver_distributes_it(): void
    {
        $this->fund($this->giver, 3000)->assertStatus(201)->assertJsonPath('balance.available', 3000);

        $response = $this->give($this->giverLogin, $this->receiver, 500);
        $response->assertStatus(201)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('funded_by_employee_id', $this->giver->id)
            ->assertJsonPath('funded_by.name', $this->giver->name);

        $this->give($this->giverLogin, $this->other, 200)->assertStatus(201);

        $balance = $this->actingAs($this->giverLogin)->getJson('/api/operational-advances/my-balance');
        $balance->assertStatus(200)
            ->assertJsonPath('employee_id', $this->giver->id)
            ->assertJsonPath('has_float', true)
            ->assertJsonPath('funded', 3000)
            ->assertJsonPath('distributed', 700)
            ->assertJsonPath('active', 700)
            ->assertJsonPath('returned', 0)
            ->assertJsonPath('available', 2300)
            ->assertJsonPath('custodies', 2);
    }

    public function test_a_float_refuses_more_than_it_holds_and_a_custody_to_its_own_holder(): void
    {
        $this->fund($this->giver, 1000);
        $this->give($this->giverLogin, $this->receiver, 700)->assertStatus(201);

        $tooMuch = $this->give($this->giverLogin, $this->other, 400);
        $tooMuch->assertStatus(422);
        $this->assertStringContainsString('رصيدك المتاح 300.000', $tooMuch->json('message'));

        $toHimself = $this->give($this->giverLogin, $this->giver, 10);
        $toHimself->assertStatus(422);
        $this->assertStringContainsString('لنفسك', $toHimself->json('message'));

        $this->give($this->giverLogin, $this->other, 300)->assertStatus(201);
        $this->assertSame(0.0, (float) $this->actingAs($this->giverLogin)->getJson('/api/operational-advances/my-balance')->json('available'));

        $this->assertDatabaseCount('operational_advances', 2);
    }

    public function test_returns_go_back_to_the_giver_and_expenses_do_not(): void
    {
        $this->fund($this->giver, 3000);
        $advanceId = $this->give($this->giverLogin, $this->receiver, 500)->json('id');

        $this->actingAs($this->giverLogin)
            ->postJson("/api/operational-advances/{$advanceId}/expense", ['amount' => 300, 'date' => '2026-09-23', 'description' => 'وقود'])
            ->assertStatus(201);
        $this->assertSame(2500.0, (float) $this->myBalance($this->giverLogin)['available']);

        $this->actingAs($this->giverLogin)
            ->postJson("/api/operational-advances/{$advanceId}/return", ['amount' => 200, 'date' => '2026-09-24'])
            ->assertStatus(201);

        $this->assertDatabaseHas('operational_advances', ['id' => $advanceId, 'status' => 'completed']);

        $balance = $this->myBalance($this->giverLogin);
        $this->assertSame(500.0, (float) $balance['distributed']);
        $this->assertSame(200.0, (float) $balance['returned']);
        $this->assertSame(2700.0, (float) $balance['available']);
    }

    public function test_each_employee_sees_only_the_custodies_that_are_his(): void
    {
        $this->fund($this->giver, 3000);
        $this->give($this->giverLogin, $this->receiver, 500);
        $this->give($this->giverLogin, $this->other, 200);
        $fromCompany = $this->give($this->owner, $this->other, 100);
        $fromCompany->assertStatus(201)->assertJsonPath('funded_by_employee_id', null);

        // Newest first within a day.
        $this->assertSame([500.0], $this->amountsSeenBy($this->receiverLogin));
        $this->assertSame([100.0, 200.0], $this->amountsSeenBy($this->otherLogin));
        $this->assertSame([200.0, 500.0], $this->amountsSeenBy($this->giverLogin));
        $this->assertSame([100.0, 200.0, 500.0], $this->amountsSeenBy($this->custodian));
        $this->assertSame([100.0, 200.0, 500.0], $this->amountsSeenBy($this->owner));

        // The profile filter narrows within what the login may see, never beyond it.
        $forOther = $this->actingAs($this->giverLogin)->getJson("/api/operational-advances?employee_id={$this->other->id}");
        $this->assertSame([200.0], array_map(fn ($row) => (float) $row['amount'], $forOther->json()));

        $noEmployee = $this->login('viewer', 'مستلم');
        $this->assertSame([], $this->actingAs($noEmployee)->getJson('/api/operational-advances')->json());
    }

    public function test_take_backs_are_bounded_by_what_the_float_still_holds(): void
    {
        $this->fund($this->giver, 1000);
        $this->give($this->giverLogin, $this->receiver, 600);

        $tooMuch = $this->fund($this->giver, 500, 'withdraw');
        $tooMuch->assertStatus(422);
        $this->assertStringContainsString('400.000', $tooMuch->json('message'));

        $this->fund($this->giver, 400, 'withdraw')->assertStatus(201)->assertJsonPath('balance.available', 0);

        $rows = $this->actingAs($this->custodian)->getJson('/api/operational-advances/balances')->assertStatus(200)->json();
        $row = collect($rows)->firstWhere('employee_id', $this->giver->id);
        $this->assertSame(600.0, (float) $row['funded']);
        $this->assertSame(600.0, (float) $row['distributed']);
        $this->assertSame(0.0, (float) $row['available']);
        $this->assertCount(2, $row['fundings']);
        $this->assertSame(-400.0, (float) $row['fundings'][0]['amount']);
        $this->assertSame($this->custodian->name, $row['fundings'][0]['created_by']);

        // Every administrative employee is listed, with or without a float.
        $this->assertFalse(collect($rows)->firstWhere('employee_id', $this->receiver->id)['has_float']);
    }

    public function test_the_fund_permission_gates_the_floats_and_the_company_still_funds_directly(): void
    {
        $this->actingAs($this->giverLogin)->postJson('/api/operational-advances/balances', $this->fundPayload($this->giver, 100))->assertStatus(403);
        $this->actingAs($this->giverLogin)->getJson('/api/operational-advances/balances')->assertStatus(403);

        // No float yet: the giver's custody comes from the company, as before floats existed.
        $this->assertFalse($this->myBalance($this->giverLogin)['has_float']);
        $this->give($this->giverLogin, $this->receiver, 300)->assertStatus(201)->assertJsonPath('funded_by_employee_id', null);

        // The owner's login has no employee record behind it, so no float can exist for it.
        $this->actingAs($this->owner)->getJson('/api/operational-advances/my-balance')
            ->assertStatus(200)
            ->assertJsonPath('has_float', false)
            ->assertJsonPath('employee_id', null);

        $driver = Employee::create([
            'name' => 'Driver', 'date_of_joining' => '2026-01-01', 'pay_type' => 'fixed',
            'official_salary' => 100, 'actual_salary' => 100, 'company_id' => $this->company->id, 'role_category' => 'driver',
        ]);
        $this->fund($driver, 100)->assertStatus(422);

        $elsewhere = Company::create(['name' => 'Elsewhere', 'code' => 'elsewhere', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true]);
        $stranger = Employee::forceCreate([
            'name' => 'Stranger', 'date_of_joining' => '2026-01-01', 'pay_type' => 'fixed',
            'official_salary' => 100, 'actual_salary' => 100, 'company_id' => $elsewhere->id, 'role_category' => 'admin',
        ]);
        $this->fund($stranger, 100)->assertStatus(422);
        $this->give($this->giverLogin, $stranger, 10)->assertStatus(422);
    }

    public function test_the_custodian_approves_a_requested_custody_without_the_edit_permission(): void
    {
        // A receiver may only view, so his own request waits for approval.
        $request = $this->actingAs($this->receiverLogin)->postJson('/api/operational-advances', [
            'employee_id' => $this->receiver->id, 'amount' => 50, 'date' => '2026-09-22', 'reason' => 'بنزين',
        ]);
        $request->assertStatus(201)->assertJsonPath('status', 'pending')->assertJsonPath('funded_by_employee_id', null);

        $this->actingAs($this->custodian)
            ->postJson("/api/operational-advances/{$request->json('id')}/approve")
            ->assertStatus(200)
            ->assertJsonPath('status', 'active');
    }

    private function login(string $handle, string $role): User
    {
        return User::create([
            'name' => ucfirst($handle),
            'email' => "{$handle}@float.test",
            'password' => bcrypt('password'),
            'role' => $role,
            'company_id' => $this->company->id,
        ]);
    }

    /** @return array{0: User, 1: Employee} */
    private function adminEmployee(string $handle, string $role): array
    {
        $user = $this->login($handle, $role);
        $employee = Employee::create([
            'name' => ucfirst($handle).' Admin',
            'date_of_joining' => '2026-01-01',
            'pay_type' => 'fixed',
            'official_salary' => 300,
            'actual_salary' => 300,
            'company_id' => $this->company->id,
            'role_category' => 'admin',
            'user_id' => $user->id,
        ]);

        return [$user, $employee];
    }

    /** @return array<string, mixed> */
    private function fundPayload(Employee $employee, float $amount, string $kind = 'add'): array
    {
        return ['employee_id' => $employee->id, 'kind' => $kind, 'amount' => $amount, 'date' => '2026-09-22', 'notes' => 'دفعة'];
    }

    private function fund(Employee $employee, float $amount, string $kind = 'add'): TestResponse
    {
        return $this->actingAs($this->custodian)->postJson('/api/operational-advances/balances', $this->fundPayload($employee, $amount, $kind));
    }

    private function give(User $as, Employee $to, float $amount): TestResponse
    {
        return $this->actingAs($as)->postJson('/api/operational-advances', [
            'employee_id' => $to->id, 'amount' => $amount, 'date' => '2026-09-22', 'reason' => 'عهدة ميدانية',
        ]);
    }

    /** @return array<string, mixed> */
    private function myBalance(User $as): array
    {
        return $this->actingAs($as)->getJson('/api/operational-advances/my-balance')->assertStatus(200)->json();
    }

    /** @return array<int, float> */
    private function amountsSeenBy(User $as): array
    {
        return array_map(fn ($row) => (float) $row['amount'], $this->actingAs($as)->getJson('/api/operational-advances')->assertStatus(200)->json());
    }
}
