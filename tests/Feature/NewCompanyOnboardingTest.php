<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A company sold today, set up end to end through the same API the screens call: the platform
 * creates it and its first login, that login fills in the lists, roles, a client, a priced contract,
 * a vehicle, a driver and an office employee, links them, records two days, closes and pays the
 * month, and reads the reports — while an existing company with look-alike data stays apart.
 *
 * Fixture month: March 2026, a fixed 260/26 contract (10.000 a day) billed a fixed 500, two days
 * worked — 20.000 earned, paid 15 by bank and 5 in cash.
 */
class NewCompanyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Company $existing;

    private User $platformOwner;

    private User $existingAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-10 10:00:00');

        // The company already on the system, with data that looks like what the new one will type.
        $this->existing = Company::create(['name' => 'Existing Co', 'code' => 'existing', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true]);
        $this->platformOwner = User::create([
            'name' => 'Owner', 'email' => 'owner@platform.test', 'password' => bcrypt('password'),
            'role' => 'admin', 'company_id' => $this->existing->id, 'is_super_admin' => true,
        ]);
        $this->existingAdmin = User::create([
            'name' => 'Existing admin', 'email' => 'admin@existing.test', 'password' => bcrypt('password'),
            'role' => 'admin', 'company_id' => $this->existing->id,
        ]);
        Client::withoutGlobalScopes()->forceCreate(['name' => 'Client', 'company_id' => $this->existing->id]);
        Setting::withoutGlobalScopes()->forceCreate(['company_id' => $this->existing->id, 'key' => 'whatsapp_business_id', 'value' => 'existing-business']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{company: int, admin: User} */
    private function sellACompany(): array
    {
        $company = $this->actingAs($this->platformOwner)->postJson('/api/admin/companies', [
            'name' => 'Al Noor Delivery', 'name_ar' => 'شركة النور للتوصيل', 'code' => 'alnoor',
        ])->assertCreated()->json('company.id');

        $userId = $this->actingAs($this->platformOwner)->postJson("/api/admin/companies/{$company}/users/create", [
            'name' => 'Noor Manager', 'email' => 'manager@alnoor.test', 'password' => 'secret123', 'role' => 'admin',
        ])->assertCreated()->json('user.id');

        return ['company' => $company, 'admin' => User::find($userId)];
    }

    private function as(User $user): static
    {
        app()->forgetInstance('current_company_id');

        return $this->actingAs($user);
    }

    private function ok(TestResponse $response): TestResponse
    {
        if ($response->status() >= 300) {
            $this->fail("{$response->status()}: ".$response->getContent());
        }

        return $response;
    }

    public function test_a_new_company_is_set_up_and_closes_its_first_month(): void
    {
        ['company' => $companyId, 'admin' => $admin] = $this->sellACompany();

        $me = $this->ok($this->as($admin)->getJson('/api/auth/me'))->json();
        $this->assertSame($companyId, $me['current_company']['id']);
        $this->assertTrue($me['user']['permissions']['contracts.edit']);

        // Lists the company starts with.
        $types = collect($this->ok($this->as($admin)->getJson('/api/vehicle-types'))->json());
        $this->assertCount(3, $types, 'سيكل، سيارة صغيرة، سيارة كبيرة');
        $this->assertTrue($types->every(fn ($t) => (int) $t['company_id'] === $companyId));
        $small = (int) $types->firstWhere('name', 'Small Car')['id'];
        $this->assertCount(7, $this->ok($this->as($admin)->getJson('/api/vehicle-expense-types'))->json());
        $this->assertNotEmpty($this->ok($this->as($admin)->getJson('/api/expense-categories'))->json('data'));
        $leaveTypes = collect($this->ok($this->as($admin)->getJson('/api/leave-types'))->json());
        $this->assertNotEmpty($leaveTypes, 'a new company needs leave types to record any leave');

        // Its own settings, under the same key another company already uses.
        $this->ok($this->as($admin)->putJson('/api/settings', ['whatsapp_business_id' => 'noor-business']));
        $this->assertSame('noor-business', $this->ok($this->as($admin)->getJson('/api/settings'))->json('data.whatsapp_business_id'));
        $this->assertSame('existing-business', $this->ok($this->as($this->existingAdmin)->getJson('/api/settings'))->json('data.whatsapp_business_id'));

        // Roles, then the people and things.
        $roleId = $this->ok($this->as($admin)->postJson('/api/roles', [
            'name' => 'محاسب', 'allowed_modules' => ['payroll.view', 'contract_payroll.view', 'reports.view', 'contracts.view'],
        ]))->json('id') ?? $this->ok($this->as($admin)->getJson('/api/roles'))->json('0.id');

        $clientId = $this->ok($this->as($admin)->postJson('/api/clients', ['name' => 'Client']))->json('id');

        $contractId = $this->ok($this->as($admin)->postJson('/api/contracts', [
            'client_id' => $clientId, 'contract_number' => 'NOOR-1', 'name' => 'عقد النور',
            'start_date' => '2026-01-01', 'default_required_work_days' => 26,
            'client_payment_method' => 'fixed',
            'client_pricing_rules' => [(string) $small => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_payment_method' => 'fixed',
            'driver_pricing_rules' => [(string) $small => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
        ]))->json('id');

        $vehicleId = $this->ok($this->as($admin)->postJson('/api/vehicles', [
            'plate_number' => 'N-100', 'vehicle_type_id' => $small, 'status' => 'available', 'ownership_type' => 'owned',
        ]))->json('id') ?? $this->ok($this->as($admin)->getJson('/api/vehicles'))->json('data.0.id');

        $driverId = $this->ok($this->as($admin)->postJson('/api/employees', [
            'name' => 'Noor Driver', 'role_category' => 'driver', 'date_of_joining' => '2026-01-01',
            'employee_type' => 'local_transfer', 'gender' => 'male', 'pay_type' => 'fixed',
            'official_salary' => 100, 'actual_salary' => 0,
        ]))->json('id');

        $office = $this->ok($this->as($admin)->postJson('/api/employees', [
            'name' => 'Noor Accountant', 'role_category' => 'admin', 'date_of_joining' => '2026-01-01',
            'employee_type' => 'local_transfer', 'gender' => 'female', 'pay_type' => 'fixed',
            'official_salary' => 300, 'actual_salary' => 300,
            'admin_role_id' => $roleId, 'email' => 'accountant@alnoor.test', 'password' => 'secret123',
        ]))->json();

        // Links, then two days of work.
        $this->ok($this->as($admin)->postJson('/api/contract-assignments', [
            'employee_id' => $driverId, 'contract_id' => $contractId, 'start_date' => '2026-01-01', 'status' => 'active',
        ]));
        $this->ok($this->as($admin)->postJson("/api/vehicles/{$vehicleId}/assign", [
            'employee_id' => $driverId, 'assigned_date' => '2026-01-01',
        ]));
        $saved = $this->ok($this->as($admin)->postJson('/api/daily-logs/bulk', ['logs' => [
            ['employee_id' => $driverId, 'vehicle_id' => $vehicleId, 'contract_id' => $contractId, 'log_date' => '2026-03-02', 'driver_status' => 'working', 'orders_count' => 5],
            ['employee_id' => $driverId, 'vehicle_id' => $vehicleId, 'contract_id' => $contractId, 'log_date' => '2026-03-03', 'driver_status' => 'working', 'orders_count' => 5],
        ]]))->json();
        $this->assertEmpty($saved['skipped'] ?? [], json_encode($saved['skipped'] ?? [], JSON_UNESCAPED_UNICODE));

        // Close and pay the month.
        $this->ok($this->as($admin)->postJson("/api/payroll/contract-sheet/{$contractId}/approve", ['year' => 2026, 'month' => 3]));
        $this->ok($this->as($admin)->postJson('/api/payroll/consolidated/2026/3/approve'));
        $row = collect($this->ok($this->as($admin)->getJson('/api/payroll/consolidated/2026/3'))->json('drivers'))->firstWhere('employee_id', $driverId);
        $this->assertEquals(20.0, $row['final_net_payout']);
        $this->ok($this->as($admin)->postJson('/api/payroll/consolidated/2026/3/disbursements', [
            'employee_id' => $driverId, 'bank_amount' => 15, 'cash_amount' => 5, 'paid_at' => '2026-04-05',
        ]));

        // The reports read the new company's month.
        $this->assertEquals(0.0, $this->ok($this->as($admin)->getJson("/api/statements/pay/{$driverId}"))->json('closing.amount'));
        $pl = $this->ok($this->as($admin)->getJson('/api/reports/profit-loss?year=2026&month=3'))->json();
        $this->assertGreaterThan(0, $pl['revenue']['total']);
        $this->assertEquals(300.0, collect($pl['admin_costs']['lines'])->firstWhere('key', 'admin_salaries')['amount'], 'the office employee\'s salary');

        // Leave and custody, on the lists the company was given or added.
        $this->ok($this->as($admin)->postJson('/api/leaves', [
            'employee_id' => $driverId, 'leave_type_id' => $leaveTypes->first()['id'], 'start_date' => '2026-04-12', 'end_date' => '2026-04-13',
        ]));
        $custodyType = $this->ok($this->as($admin)->postJson('/api/custody-types', ['name' => 'هاتف']))->json('id')
            ?? collect($this->ok($this->as($admin)->getJson('/api/custody-types'))->json())->first()['id'];
        $this->ok($this->as($admin)->postJson('/api/custody', [
            'employee_id' => $driverId, 'custody_type_id' => $custodyType, 'item_type' => 'phone',
            'issued_date' => '2026-04-01', 'handover_proof_path' => 'uploads/custody/proof.png',
        ]));

        // The office employee signs in with the role he was given, in his company only.
        $accountant = User::where('email', 'accountant@alnoor.test')->firstOrFail();
        $theirs = $this->ok($this->as($accountant)->getJson('/api/auth/me'))->json();
        $this->assertSame($companyId, $theirs['current_company']['id']);
        $this->assertTrue($theirs['user']['permissions']['payroll.view'] ?? false);
        $this->assertFalse($theirs['user']['permissions']['contracts.edit'] ?? false);
        $this->assertNotEmpty($office);

        // Nothing crossed over to the company that was already there.
        $existingClients = collect($this->ok($this->as($this->existingAdmin)->getJson('/api/clients'))->json('data') ?? $this->as($this->existingAdmin)->getJson('/api/clients')->json());
        $this->assertCount(1, $existingClients);
        $this->assertSame(1, VehicleType::withoutGlobalScopes()->where('company_id', $companyId)->where('name', 'Small Car')->count());
    }

    public function test_the_companies_list_carries_the_contact_details_the_edit_form_opens_with(): void
    {
        $id = $this->actingAs($this->platformOwner)->postJson('/api/admin/companies', [
            'name' => 'Contact Co', 'name_ar' => 'شركة الاتصال', 'code' => 'contactco',
            'phone' => '22990011', 'email' => 'info@contact.test', 'address' => 'الري', 'tax_number' => 'TAX-1',
        ])->assertCreated()->json('company.id');

        $row = collect($this->actingAs($this->platformOwner)->getJson('/api/admin/companies')->assertOk()->json('companies'))
            ->firstWhere('id', $id);

        // The edit form is filled from this row; a missing field opened empty and a save wiped it.
        $this->assertSame(['22990011', 'info@contact.test', 'الري', 'TAX-1'],
            [$row['phone'], $row['email'], $row['address'], $row['tax_number']]);
    }

    /**
     * Every screen a new company opens on its first day reads an empty company. A report that
     * divides by a count of zero, or reaches for a first row that is not there, would greet the
     * buyer with a server error, so every parameter-free GET of the API is opened once here.
     */
    public function test_every_screen_of_an_empty_new_company_opens(): void
    {
        ['admin' => $admin] = $this->sellACompany();

        $broken = [];
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($uri, 'api/') || str_contains($uri, '{')
                || str_starts_with($uri, 'api/admin') || str_contains($uri, 'export') || str_contains($uri, 'download')) {
                continue;
            }
            $status = $this->as($admin)->getJson('/'.$uri.'?year=2026&month=4')->status();
            if ($status >= 500) {
                $broken[] = "{$status} /{$uri}";
            }
        }

        $this->assertSame([], $broken, "screens that fail on an empty company:\n".implode("\n", $broken));
    }

    public function test_a_company_cannot_point_at_another_companys_records(): void
    {
        ['admin' => $admin] = $this->sellACompany();
        $foreignClient = Client::withoutGlobalScopes()->where('company_id', $this->existing->id)->value('id');
        $this->as($this->existingAdmin)->getJson('/api/vehicle-types')->assertOk();
        $foreignType = VehicleType::withoutGlobalScopes()->where('company_id', $this->existing->id)->value('id');
        $ownType = (int) collect($this->as($admin)->getJson('/api/vehicle-types')->json())->first()['id'];

        $contract = fn (int $clientId, int $typeId) => $this->as($admin)->postJson('/api/contracts', [
            'client_id' => $clientId, 'contract_number' => 'X-'.$clientId.'-'.$typeId, 'name' => 'x',
            'start_date' => '2026-01-01', 'default_required_work_days' => 26, 'vehicle_type_id' => $typeId,
            'client_payment_method' => 'fixed', 'client_pricing_rules' => [(string) $typeId => ['payment_method' => 'fixed', 'fixed_amount' => 1]],
            'driver_payment_method' => 'fixed', 'driver_pricing_rules' => [(string) $typeId => ['payment_method' => 'fixed', 'fixed_amount' => 1]],
        ]);

        $contract($foreignClient, $ownType)->assertStatus(422)->assertJsonValidationErrors('client_id');
        $ownClient = $this->as($admin)->postJson('/api/clients', ['name' => 'Own'])->json('id');
        $contract($ownClient, $foreignType)->assertStatus(422)->assertJsonValidationErrors('vehicle_type_id');

        $this->as($admin)->postJson('/api/vehicles', ['plate_number' => 'F-1', 'vehicle_type_id' => $foreignType])
            ->assertStatus(422)->assertJsonValidationErrors('vehicle_type_id');
    }

    /**
     * The contract screen offered the first company's vehicle types (ids 1–3) to every company, so
     * a new company priced its contract on types its own vehicles never carry and billed nothing.
     */
    public function test_a_contract_is_priced_only_on_the_companys_own_vehicle_types(): void
    {
        ['admin' => $admin] = $this->sellACompany();
        $this->as($this->existingAdmin)->getJson('/api/vehicle-types')->assertOk();
        $foreignType = (string) VehicleType::withoutGlobalScopes()->where('company_id', $this->existing->id)->value('id');
        $ownType = (string) collect($this->as($admin)->getJson('/api/vehicle-types')->json())->first()['id'];
        $client = $this->as($admin)->postJson('/api/clients', ['name' => 'Own'])->json('id');

        $payload = fn (string $type) => [
            'client_id' => $client, 'contract_number' => 'T-'.$type, 'name' => 'x', 'start_date' => '2026-01-01', 'default_required_work_days' => 26,
            'client_payment_method' => 'fixed', 'client_pricing_rules' => [$type => ['payment_method' => 'fixed', 'fixed_amount' => 100]],
            'driver_payment_method' => 'fixed', 'driver_pricing_rules' => [$type => ['payment_method' => 'fixed', 'fixed_amount' => 100]],
        ];

        $refused = $this->as($admin)->postJson('/api/contracts', $payload($foreignType))->assertStatus(422);
        $this->assertStringContainsString('غير موجود في شركتك', json_encode($refused->json('errors'), JSON_UNESCAPED_UNICODE));
        $this->as($admin)->postJson('/api/contracts', $payload($ownType))->assertCreated();
    }
}
