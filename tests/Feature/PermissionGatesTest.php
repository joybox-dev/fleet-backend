<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The role gate used to admit any login that was not a driver, and the permission service handed
 * the full admin set to a role that granted nothing. Between them, what a company-defined role was
 * allowed to do was decided by nothing at all: a data-entry login could rewrite any role's
 * permissions and read the WhatsApp token.
 */
class PermissionGatesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Gates Co',
            'code' => 'gatesco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);
    }

    /**
     * A login carrying a company-defined role, linked the way the employee wizard links it.
     *
     * @param  array<int, string>  $modules
     */
    private function loginWithRole(string $roleName, array $modules): User
    {
        $role = Role::create([
            'name' => $roleName,
            'company_id' => $this->company->id,
            'allowed_modules' => $modules,
        ]);

        $user = User::create([
            'name' => $roleName,
            'email' => uniqid('gate').'@gates.test',
            'password' => bcrypt('password'),
            'role' => $roleName,
            'company_id' => $this->company->id,
        ]);

        Employee::create([
            'name' => $roleName,
            'employee_number' => 'EMP-'.strtoupper(uniqid()),
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'admin',
            'admin_role_id' => $role->id,
            'user_id' => $user->id,
            'date_of_joining' => '2026-01-01',
        ]);

        return $user;
    }

    private function builtIn(string $role): User
    {
        return User::create([
            'name' => $role,
            'email' => uniqid($role).'@gates.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_a_company_defined_role_passes_the_role_gate_and_is_judged_by_its_permissions(): void
    {
        $user = $this->loginWithRole('مدخل بيانات', ['daily_logs', 'employees']);

        // The admin group admits him, and his own modules decide what inside it he may do.
        $this->actingAs($user)->getJson('/api/employees')->assertOk();
        $this->actingAs($user)->getJson('/api/clients')->assertStatus(403);
    }

    public function test_a_data_entry_login_cannot_rewrite_roles_or_read_settings(): void
    {
        $user = $this->loginWithRole('مدخل بيانات', ['daily_logs']);
        $ownRole = Role::withoutGlobalScopes()->where('name', 'مدخل بيانات')->firstOrFail();

        $this->actingAs($user)
            ->putJson("/api/roles/{$ownRole->id}", ['allowed_modules' => ['settings', 'payroll']])
            ->assertStatus(403)
            ->assertJsonPath('required_permission.0', 'settings.edit');

        $this->assertSame(['daily_logs'], $ownRole->fresh()->allowed_modules);

        $this->actingAs($user)->getJson('/api/settings')->assertStatus(403);
        $this->actingAs($user)->getJson('/api/roles')->assertStatus(403);
    }

    public function test_a_role_with_no_modules_grants_nothing_beyond_the_dashboard(): void
    {
        $user = $this->loginWithRole('أبو حضرم', []);

        $this->actingAs($user)->getJson('/api/dashboard/summary')->assertOk();
        $this->actingAs($user)->getJson('/api/employees')->assertStatus(403);
        $this->actingAs($user)->getJson('/api/contracts')->assertStatus(403);
        $this->actingAs($user)->getJson('/api/settings')->assertStatus(403);
    }

    public function test_a_role_that_grants_settings_may_manage_roles(): void
    {
        $user = $this->loginWithRole('مدير', ['settings']);

        $this->actingAs($user)->getJson('/api/roles')->assertOk();
        $this->actingAs($user)->getJson('/api/roles/permissions')->assertOk();

        $this->actingAs($user)
            ->postJson('/api/roles', ['name' => 'مشرف', 'allowed_modules' => ['daily_logs']])
            ->assertStatus(201);
    }

    public function test_built_in_roles_are_held_to_their_own_group(): void
    {
        $operator = $this->builtIn('operator');
        $accountant = $this->builtIn('accountant');

        // An operator belongs to the operations group and nowhere else.
        $this->actingAs($operator)->getJson('/api/daily-logs')->assertOk();
        $this->actingAs($operator)->getJson('/api/clients')->assertStatus(403);
        $this->actingAs($operator)->getJson('/api/settings')->assertStatus(403);

        // An accountant belongs to the finance group and nowhere else.
        $this->actingAs($accountant)->getJson('/api/reports/pending-cash')->assertOk();
        $this->actingAs($accountant)->getJson('/api/daily-logs')->assertStatus(403);
        $this->actingAs($accountant)->getJson('/api/clients')->assertStatus(403);
    }

    public function test_an_admin_login_passes_every_gate(): void
    {
        $admin = $this->builtIn('admin');

        $this->actingAs($admin)->getJson('/api/roles')->assertOk();
        $this->actingAs($admin)->getJson('/api/settings')->assertOk();
        $this->actingAs($admin)->getJson('/api/daily-logs')->assertOk();
        $this->actingAs($admin)->getJson('/api/reports/pending-cash')->assertOk();
    }

    public function test_the_permission_denial_names_what_is_missing(): void
    {
        $user = $this->loginWithRole('موظف عقود', ['contracts']);

        $this->actingAs($user)
            ->putJson('/api/settings', ['whatsapp_phone_number_id' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'غير مصرح لك — هذا الإجراء يحتاج صلاحية «إعدادات النظام: تعديل».');
    }
}
