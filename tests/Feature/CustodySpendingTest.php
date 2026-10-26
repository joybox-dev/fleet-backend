<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\OperationalAdvance;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\CustodySpendingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Every expense, fine paid and maintenance approved is paid out of an administrative employee's
 * operational custody (owner, 2026-10-26: Â«Ø¨Ø¹Ø¯ Ø§Ø¹Ø·Ø§Ø¡ Ù…Ø¨Ù„Øº Ø¹Ù‡Ø¯Ø© Ø§Ù„Ù…ÙØ±ÙˆØ¶ Ø§Ù„ØµØ±Ù ÙŠÙƒÙˆÙ† Ø¹Ù„Ù‰ Ø´ÙŠ Ø¶Ù…Ù†
 * Ø§Ù„Ù…ØµØ§Ø±ÙŠÙ Ø§Ù„Ù…ÙˆØ¬ÙˆØ¯Ø© ÙˆÙ…Ù† Ø¶Ù…Ù†Ù‡Ø§ Ø§Ù„Ù…Ø®Ø§Ù„ÙØ§ØªÂ»). Mandatory for everything; more than is left is refused.
 *
 * Cast: the holder was handed a 100.000 custody on October 1st; a colleague with the same role
 * holds none; the owner is a plain admin login who may pay from any custody.
 */
class CustodySpendingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $holderLogin;

    private User $colleagueLogin;

    private Employee $driver;

    private Vehicle $vehicle;

    private int $custodyId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->company = Company::create([
            'name' => 'Custody Co', 'code' => 'custco', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        Role::create(['name' => 'Ù…Ø´Ø±Ù', 'company_id' => $this->company->id, 'allowed_modules' => [
            'op_advances.view', 'driver_expenses.view', 'driver_expenses.create', 'driver_expenses.edit', 'driver_expenses.delete',
            'violations.view', 'violations.edit', 'maintenance.view', 'maintenance.create', 'maintenance.edit',
        ]]);

        $this->owner = $this->login('owner', 'admin');
        [$this->holderLogin, $holder] = $this->adminEmployee('holder');
        [$this->colleagueLogin] = $this->adminEmployee('colleague');

        $this->driver = Employee::create([
            'name' => 'Ø³Ø§Ø¦Ù‚', 'company_id' => $this->company->id, 'status' => 'active',
            'role_category' => 'driver', 'date_of_joining' => '2026-01-01', 'actual_salary' => 200,
        ]);
        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-CS-1', 'make' => 'Toyota', 'status' => 'working',
            'company_id' => $this->company->id, 'vehicle_type_id' => 2,
        ]);

        $this->custodyId = OperationalAdvance::create([
            'company_id' => $this->company->id, 'employee_id' => $holder->id, 'amount' => 100,
            'date' => '2026-10-01', 'reason' => 'Ø¹Ù‡Ø¯Ø© Ù…ÙŠØ¯Ø§Ù†ÙŠØ©', 'status' => 'active',
        ])->id;
    }

    public function test_an_expense_names_the_custody_it_was_paid_from(): void
    {
        $this->actingAs($this->holderLogin)->postJson('/api/driver-expenses', $this->expense(30))
            ->assertStatus(422)->assertJsonValidationErrors('operational_advance_id');

        $this->actingAs($this->holderLogin)->postJson('/api/driver-expenses', $this->expense(130, ['operational_advance_id' => $this->custodyId]))
            ->assertStatus(422)->assertJsonValidationErrors('operational_advance_id');

        $this->actingAs($this->holderLogin)->postJson('/api/driver-expenses', $this->expense(30, ['operational_advance_id' => $this->custodyId, 'expense_date' => '2026-09-30']))
            ->assertStatus(422)->assertJsonValidationErrors('operational_advance_id');

        // A colleague without Â«Ø¥Ø¹Ø·Ø§Ø¡ Ø±ØµÙŠØ¯Â» does not spend someone else's custody.
        $this->actingAs($this->colleagueLogin)->postJson('/api/driver-expenses', $this->expense(30, ['operational_advance_id' => $this->custodyId]))
            ->assertStatus(422)->assertJsonValidationErrors('operational_advance_id');

        $this->actingAs($this->holderLogin)->postJson('/api/driver-expenses', $this->expense(30, ['operational_advance_id' => $this->custodyId]))
            ->assertSuccessful();

        $this->assertSame(70.0, $this->left());
    }

    public function test_a_custody_used_up_closes_and_opens_again_when_money_comes_back(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/driver-expenses', $this->expense(100, ['operational_advance_id' => $this->custodyId]))
            ->assertSuccessful()->json('id') ?? DriverExpense::latest('id')->value('id');

        $this->assertSame('completed', OperationalAdvance::find($this->custodyId)->status);
        $this->actingAs($this->owner)->postJson('/api/driver-expenses', $this->expense(1, ['operational_advance_id' => $this->custodyId]))
            ->assertStatus(422);

        // Editing the record that used it up still works on the closed custody.
        $this->actingAs($this->owner)->putJson("/api/driver-expenses/{$id}", $this->expense(60))->assertSuccessful();
        $this->assertSame('active', OperationalAdvance::find($this->custodyId)->status);
        $this->assertSame(40.0, $this->left());

        $this->actingAs($this->owner)->deleteJson("/api/driver-expenses/{$id}")->assertSuccessful();
        $this->assertSame(100.0, $this->left());
    }

    public function test_a_fine_is_paid_from_a_custody_and_can_be_undone(): void
    {
        $fine = Violation::create([
            'vehicle_id' => $this->vehicle->id, 'employee_id' => $this->driver->id, 'violation_date' => '2026-10-05',
            'violation_type' => 'ØªØ¬Ø§ÙˆØ² Ø§Ù„Ø³Ø±Ø¹Ø©', 'amount' => 40, 'is_driver_liable' => false, 'driver_share' => 0,
            'created_by' => $this->owner->id,
        ]);

        $this->actingAs($this->holderLogin)->postJson("/api/violations/{$fine->id}/pay", ['paid_at' => '2026-10-10'])
            ->assertStatus(422)->assertJsonValidationErrors('operational_advance_id');
        $this->actingAs($this->holderLogin)->postJson("/api/violations/{$fine->id}/pay", ['operational_advance_id' => $this->custodyId, 'paid_at' => '2026-10-10'])
            ->assertSuccessful();

        $this->assertSame(60.0, $this->left());
        $this->actingAs($this->holderLogin)->postJson("/api/violations/{$fine->id}/pay", ['operational_advance_id' => $this->custodyId, 'paid_at' => '2026-10-10'])
            ->assertStatus(422);
        $this->actingAs($this->owner)->deleteJson("/api/violations/{$fine->id}")->assertStatus(422);

        $this->actingAs($this->holderLogin)->deleteJson("/api/violations/{$fine->id}/pay")->assertSuccessful();
        $this->assertNull($fine->fresh()->operational_advance_id);
        $this->assertSame(100.0, $this->left());
    }

    public function test_a_maintenance_is_approved_first_and_paid_from_a_custody_later(): void
    {
        $id = $this->actingAs($this->holderLogin)->postJson('/api/maintenance', [
            'vehicle_id' => $this->vehicle->id, 'maintenance_type' => 'accident', 'maintenance_date' => '2026-10-08',
            'estimated_cost' => 50, 'is_driver_liable' => false,
        ])->assertSuccessful()->json('id');

        // Paying before the cost is approved is refused; approving pays nothing.
        $this->actingAs($this->holderLogin)->postJson("/api/maintenance/{$id}/pay", ['operational_advance_id' => $this->custodyId, 'paid_at' => '2026-10-12'])
            ->assertStatus(422);
        $this->actingAs($this->owner)->postJson("/api/maintenance/{$id}/approve", ['actual_cost' => 45])->assertSuccessful();
        $this->assertSame(100.0, $this->left());

        $this->actingAs($this->holderLogin)->postJson("/api/maintenance/{$id}/pay", ['paid_at' => '2026-10-12'])
            ->assertStatus(422)->assertJsonValidationErrors('operational_advance_id');
        $this->actingAs($this->holderLogin)->postJson("/api/maintenance/{$id}/pay", ['operational_advance_id' => $this->custodyId, 'paid_at' => '2026-10-12'])
            ->assertSuccessful();

        $this->assertSame(55.0, $this->left());
        $this->assertDatabaseHas('maintenance_records', ['id' => $id, 'operational_advance_id' => $this->custodyId, 'paid_at' => '2026-10-12']);
        $this->actingAs($this->owner)->deleteJson("/api/maintenance/{$id}")->assertStatus(422);

        $this->actingAs($this->colleagueLogin)->deleteJson("/api/maintenance/{$id}/pay")->assertForbidden();
        $this->actingAs($this->holderLogin)->deleteJson("/api/maintenance/{$id}/pay")->assertSuccessful();
        $this->assertSame(100.0, $this->left());
    }

    public function test_whoever_holds_the_cash_records_what_it_went_on_without_the_screens(): void
    {
        Role::create(['name' => 'أمين صرف', 'company_id' => $this->company->id, 'allowed_modules' => ['op_advances.view']]);
        $cashier = $this->login('cashier', 'أمين صرف');
        $employee = Employee::create([
            'name' => 'Cashier', 'date_of_joining' => '2026-01-01', 'pay_type' => 'fixed', 'official_salary' => 300,
            'actual_salary' => 300, 'company_id' => $this->company->id, 'role_category' => 'admin', 'user_id' => $cashier->id,
        ]);
        $own = OperationalAdvance::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id, 'amount' => 80,
            'date' => '2026-10-01', 'reason' => 'صندوق', 'status' => 'active',
        ])->id;
        $fine = Violation::create([
            'vehicle_id' => $this->vehicle->id, 'employee_id' => $this->driver->id, 'violation_date' => '2026-10-18',
            'violation_type' => 'وقوف', 'amount' => 15, 'is_driver_liable' => false, 'driver_share' => 0, 'created_by' => $this->owner->id,
        ]);

        // On the custody he holds: no expense or fine permission needed.
        $this->actingAs($cashier)->postJson('/api/driver-expenses', $this->expense(10, ['operational_advance_id' => $own]))->assertSuccessful();
        $this->actingAs($cashier)->postJson("/api/violations/{$fine->id}/pay", ['operational_advance_id' => $own, 'paid_at' => '2026-10-19'])->assertSuccessful();

        // Not on someone else's custody, and not without naming one.
        $this->actingAs($cashier)->postJson('/api/driver-expenses', $this->expense(10, ['operational_advance_id' => $this->custodyId]))->assertForbidden();
        $this->actingAs($cashier)->postJson('/api/driver-expenses', $this->expense(10))->assertForbidden();

        $this->assertSame(55.0, CustodySpendingService::remaining(OperationalAdvance::find($own)));
    }

    public function test_my_custody_lists_what_is_waiting_to_be_paid_and_what_the_forms_need(): void
    {
        Carbon::setTestNow('2026-10-28 10:00:00');
        $id = $this->actingAs($this->owner)->postJson('/api/maintenance', [
            'vehicle_id' => $this->vehicle->id, 'maintenance_type' => 'accident', 'maintenance_date' => '2026-10-15',
            'estimated_cost' => 20, 'is_driver_liable' => false,
        ])->json('id');
        $this->actingAs($this->owner)->postJson("/api/maintenance/{$id}/approve", ['actual_cost' => 20])->assertSuccessful();
        Violation::create([
            'vehicle_id' => $this->vehicle->id, 'employee_id' => $this->driver->id, 'violation_date' => '2026-10-19',
            'violation_type' => 'وقوف', 'amount' => 15, 'is_driver_liable' => false, 'driver_share' => 0, 'created_by' => $this->owner->id,
        ]);
        // A fine recorded before custodies paid for everything is not owed by anyone's custody.
        Carbon::setTestNow('2026-10-01 10:00:00');
        Violation::create([
            'vehicle_id' => $this->vehicle->id, 'employee_id' => $this->driver->id, 'violation_date' => '2026-09-30',
            'violation_type' => 'قديمة', 'amount' => 5, 'is_driver_liable' => false, 'driver_share' => 0, 'created_by' => $this->owner->id,
        ]);
        Carbon::setTestNow('2026-10-28 10:00:00');

        $mine = $this->actingAs($this->holderLogin)->getJson('/api/operational-advances/workspace')->assertOk()->json();
        $this->assertSame([$this->custodyId], array_column($mine['custodies'], 'id'));
        $this->assertSame(['وقوف'], array_column($mine['awaiting']['violations'], 'label'));
        $this->assertSame([$id], array_column($mine['awaiting']['maintenance'], 'id'));
        $this->assertSame([$this->driver->id], array_column($mine['lookups']['drivers'], 'id'));
        $this->assertNotEmpty($mine['lookups']['categories']);

        // Nothing for a login with no custody to pay from.
        $this->assertSame([], $this->actingAs($this->colleagueLogin)->getJson('/api/operational-advances/workspace')->assertOk()->json('custodies'));
    }

    public function test_the_custody_lists_what_it_paid_for_and_free_text_is_gone(): void
    {
        $this->actingAs($this->holderLogin)->postJson('/api/driver-expenses', $this->expense(25, ['operational_advance_id' => $this->custodyId]))
            ->assertSuccessful();

        $mine = $this->actingAs($this->holderLogin)->getJson('/api/operational-advances/spendable')->assertOk()->json();
        $this->assertSame([$this->custodyId], array_column($mine, 'id'));
        $this->assertSame(75.0, (float) $mine[0]['remaining']);
        $this->assertSame([], $this->actingAs($this->colleagueLogin)->getJson('/api/operational-advances/spendable')->assertOk()->json());
        $this->assertSame([$this->custodyId], array_column($this->actingAs($this->owner)->getJson('/api/operational-advances/spendable')->json(), 'id'));

        $advance = collect($this->actingAs($this->owner)->getJson('/api/operational-advances')->assertOk()->json())->firstWhere('id', $this->custodyId);
        $this->assertSame(['driver_expense'], array_column($advance['paid_records'], 'kind'));
        $this->assertSame(75.0, (float) $advance['remaining_balance']);

        $this->actingAs($this->owner)->postJson("/api/operational-advances/{$this->custodyId}/expense", ['amount' => 5, 'date' => '2026-10-10', 'description' => 'ÙˆÙ‚ÙˆØ¯'])
            ->assertStatus(422);
    }

    /** @return array<string, mixed> */
    private function expense(float $amount, array $extra = []): array
    {
        return array_merge([
            'employee_id' => $this->driver->id, 'expense_type' => 'fuel', 'amount' => $amount,
            'borne_by' => 'company', 'expense_date' => '2026-10-10',
        ], $extra);
    }

    private function left(): float
    {
        return CustodySpendingService::remaining(OperationalAdvance::find($this->custodyId));
    }

    private function login(string $handle, string $role): User
    {
        return User::create([
            'name' => ucfirst($handle), 'email' => "{$handle}@custody.test", 'password' => bcrypt('password'),
            'role' => $role, 'company_id' => $this->company->id,
        ]);
    }

    /** @return array{0: User, 1: Employee} */
    private function adminEmployee(string $handle): array
    {
        $user = $this->login($handle, 'Ù…Ø´Ø±Ù');
        $employee = Employee::create([
            'name' => ucfirst($handle).' Admin', 'date_of_joining' => '2026-01-01', 'pay_type' => 'fixed',
            'official_salary' => 300, 'actual_salary' => 300, 'company_id' => $this->company->id,
            'role_category' => 'admin', 'user_id' => $user->id,
        ]);

        return [$user, $employee];
    }
}
