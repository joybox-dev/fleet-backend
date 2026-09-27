<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The owner's hierarchy (2026-09-27): the system owner (the Mersal account) runs their own
 * company in full and only looks at the others; each company's staff take their permissions from
 * the roles. The company's money on the dashboard belongs to those who read the reports, and a
 * whole module ticked on a role never carries the contract restriction or «إعطاء رصيد».
 */
class SystemOwnerAndRolesTest extends TestCase
{
    use RefreshDatabase;

    private Company $own;

    private Company $other;

    private User $owner;

    private Contract $otherContract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->own = Company::create(['name' => 'Own Co', 'code' => 'ownco', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true]);
        $this->other = Company::create(['name' => 'Other Co', 'code' => 'otherco', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true]);

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'owner@own.test', 'password' => bcrypt('password'),
            'role' => 'admin', 'company_id' => $this->own->id, 'is_super_admin' => true,
        ]);

        $client = Client::withoutGlobalScopes()->forceCreate(['name' => 'Other client', 'company_id' => $this->other->id]);
        $this->otherContract = Contract::withoutGlobalScopes()->forceCreate([
            'client_id' => $client->id, 'contract_number' => 'OTHER-1', 'name' => 'عقد الشركة الأخرى',
            'payment_type' => 'fixed', 'start_date' => '2026-01-01', 'company_id' => $this->other->id,
            'currency' => 'KWD', 'default_required_work_days' => 26,
        ]);
    }

    public function test_the_system_owner_runs_their_own_company_in_full(): void
    {
        $me = $this->actingAs($this->owner)->getJson('/api/auth/me')->assertOk()->json();
        $this->assertFalse($me['user']['view_only']);
        $this->assertSame($this->own->id, $me['current_company']['id']);
        $this->assertTrue($me['user']['permissions']['contracts.edit']);

        $this->actingAs($this->owner)->postJson('/api/clients', ['name' => 'New client'])->assertSuccessful();
        $this->assertSame(1, Client::withoutGlobalScopes()->where('company_id', $this->own->id)->count());
    }

    public function test_another_company_is_shown_but_nothing_is_written_there(): void
    {
        $look = ['X-Company-Id' => (string) $this->other->id];

        $me = $this->actingAs($this->owner)->getJson('/api/auth/me', $look)->assertOk()->json();
        $this->assertTrue($me['user']['view_only']);
        $this->assertSame($this->other->id, $me['current_company']['id']);
        $this->assertTrue($me['user']['permissions']['contracts.view']);
        $this->assertArrayNotHasKey('contracts.edit', $me['user']['permissions'], 'only the «view» of each module');

        $this->actingAs($this->owner)->getJson("/api/contracts/{$this->otherContract->id}", $look)
            ->assertOk()->assertJsonPath('name', 'عقد الشركة الأخرى');

        $this->actingAs($this->owner)->postJson('/api/clients', ['name' => 'Sneaked in'], $look)
            ->assertStatus(403)->assertJsonPath('view_only', true);
        $this->actingAs($this->owner)->putJson("/api/contracts/{$this->otherContract->id}", ['notes' => 'x'], $look)->assertStatus(403);
        $this->actingAs($this->owner)->deleteJson("/api/contracts/{$this->otherContract->id}", [], $look)->assertStatus(403);

        $this->assertSame(0, Client::withoutGlobalScopes()->where('name', 'Sneaked in')->count());
        $this->assertNull($this->otherContract->fresh()->notes);
    }

    public function test_the_platform_screens_stay_the_owners_to_change(): void
    {
        $look = ['X-Company-Id' => (string) $this->other->id];

        $this->actingAs($this->owner)
            ->putJson("/api/admin/companies/{$this->other->id}/modules", ['enabled_modules' => ['dashboard', 'contracts']], $look)
            ->assertOk();
        $this->assertSame(['dashboard', 'contracts'], $this->other->fresh()->enabled_modules);
    }

    public function test_a_company_user_cannot_look_into_another_company(): void
    {
        $staff = User::create([
            'name' => 'Other admin', 'email' => 'admin@other.test', 'password' => bcrypt('password'),
            'role' => 'admin', 'company_id' => $this->own->id,
        ]);

        // The header is the owner's alone: a company user stays in their own company.
        $this->actingAs($staff)->getJson("/api/contracts/{$this->otherContract->id}", ['X-Company-Id' => (string) $this->other->id])
            ->assertNotFound();
    }

    public function test_the_dashboards_money_is_for_those_who_read_the_reports(): void
    {
        Role::withoutGlobalScopes()->forceCreate(['name' => 'مدخل', 'company_id' => $this->own->id, 'allowed_modules' => ['daily_logs.view', 'daily_logs.create']]);
        $clerk = User::create([
            'name' => 'Clerk', 'email' => 'clerk@own.test', 'password' => bcrypt('password'),
            'role' => 'مدخل', 'company_id' => $this->own->id,
        ]);

        $this->actingAs($clerk)->getJson('/api/dashboard/summary')->assertOk();
        $this->actingAs($clerk)->getJson('/api/dashboard/pulse')->assertStatus(403);
        $this->actingAs($clerk)->getJson('/api/dashboard/money-at-risk')->assertStatus(403);
        $this->actingAs($clerk)->getJson('/api/dashboard/contracts-profitability')->assertStatus(403);

        $this->actingAs($this->owner)->getJson('/api/dashboard/pulse')->assertOk();
    }

    public function test_a_whole_module_never_carries_the_restriction_or_the_fund(): void
    {
        Role::withoutGlobalScopes()->forceCreate(['name' => 'كامل', 'company_id' => $this->own->id, 'allowed_modules' => ['employees', 'op_advances']]);
        Role::withoutGlobalScopes()->forceCreate(['name' => 'مقيّد', 'company_id' => $this->own->id, 'allowed_modules' => ['employees', 'employees.scope_contracts', 'op_advances.fund']]);
        $whole = User::create(['name' => 'Whole', 'email' => 'whole@own.test', 'password' => bcrypt('password'), 'role' => 'كامل', 'company_id' => $this->own->id]);
        $ticked = User::create(['name' => 'Ticked', 'email' => 'ticked@own.test', 'password' => bcrypt('password'), 'role' => 'مقيّد', 'company_id' => $this->own->id]);

        $this->actingAs($whole);
        $this->assertTrue($whole->can('employees.edit'));
        $this->assertTrue($whole->can('op_advances.create'));
        $this->assertFalse($whole->can('employees.scope_contracts'), 'the restriction is ticked on its own');
        $this->assertFalse($whole->can('op_advances.fund'), 'so is the power over everyone\'s custody');

        $this->actingAs($ticked);
        $this->assertTrue($ticked->can('employees.scope_contracts'));
        $this->assertTrue($ticked->can('op_advances.fund'));
    }
}
