<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustodyItem;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Now that two companies can hold custody types with the same name, they hold them under DIFFERENT
 * ids — which makes it worth proving that a company cannot attach another company's type id to its
 * own custody item. The rule guarding it is `exists:custody_types,id`, and `exists` does not know
 * about the tenant scope.
 */
class CustodyTypeCannotBeBorrowedAcrossCompaniesTest extends TestCase
{
    use RefreshDatabase;

    private array $companies = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['alpha', 'beta'] as $code) {
            $company = Company::create([
                'name' => ucfirst($code).' Co',
                'code' => $code,
                'enabled_modules' => Company::DEFAULT_MODULES,
                'is_active' => true,
            ]);

            $user = User::create([
                'name' => 'Admin '.$code,
                'email' => "admin@{$code}.test",
                'password' => bcrypt('password'),
                'role' => 'admin',
                'company_id' => $company->id,
            ]);

            app()->instance('current_company_id', $company->id);

            $employee = Employee::create([
                'name' => 'سائق '.$code,
                'employee_number' => 'EMP-'.strtoupper($code),
                'company_id' => $company->id,
                'status' => 'active',
                'role_category' => 'driver',
                'date_of_joining' => '2026-01-01',
                'actual_salary' => 200.000,
            ]);

            $this->companies[$code] = compact('company', 'user', 'employee');
        }
    }

    private function asCompany(string $code): self
    {
        app()->instance('current_company_id', $this->companies[$code]['company']->id);
        $this->actingAs($this->companies[$code]['user']);

        return $this;
    }

    public function test_a_company_cannot_attach_another_companys_custody_type(): void
    {
        $alphaType = $this->asCompany('alpha')
            ->postJson('/api/custody-types', ['name' => 'هاتف', 'icon' => '📱'])
            ->assertSuccessful()->json('id');

        $response = $this->asCompany('beta')->postJson('/api/custody', [
            'employee_id' => $this->companies['beta']['employee']->id,
            'custody_type_id' => $alphaType,
            'item_description' => 'جهاز',
            'value' => 60.000,
            'issued_date' => '2026-03-01',
            'handover_proof_path' => 'custody/signed.pdf',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('custody_type_id');

        $this->assertSame(
            0,
            CustodyItem::withoutGlobalScopes()->where('custody_type_id', $alphaType)->count(),
            "no item outside alpha may point at alpha's type"
        );
    }

    public function test_a_company_cannot_hand_custody_to_another_companys_employee(): void
    {
        $this->asCompany('beta')->postJson('/api/custody', [
            'employee_id' => $this->companies['alpha']['employee']->id,
            'item_description' => 'جهاز',
            'issued_date' => '2026-03-01',
            'handover_proof_path' => 'custody/signed.pdf',
        ])->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    public function test_a_serial_another_company_used_is_still_available(): void
    {
        $issue = fn (string $code) => $this->asCompany($code)->postJson('/api/custody', [
            'employee_id' => $this->companies[$code]['employee']->id,
            'item_description' => 'هاتف',
            'serial_number' => 'SN-001',
            'issued_date' => '2026-03-01',
            'handover_proof_path' => 'custody/signed.pdf',
        ]);

        $issue('alpha')->assertSuccessful();

        // A different physical phone, in a different company, that happens to carry the same serial.
        $issue('beta')->assertSuccessful();

        // Inside one company it is still one serial, one item.
        $issue('alpha')->assertStatus(422)->assertJsonValidationErrors('serial_number');
    }

    public function test_a_deleted_items_serial_can_be_issued_again(): void
    {
        $this->asCompany('alpha');

        $payload = [
            'employee_id' => $this->companies['alpha']['employee']->id,
            'item_description' => 'هاتف',
            'serial_number' => 'SN-REUSE',
            'issued_date' => '2026-03-01',
            'handover_proof_path' => 'custody/signed.pdf',
        ];

        $id = $this->postJson('/api/custody', $payload)->assertSuccessful()->json('id');
        $this->deleteJson("/api/custody/{$id}")->assertSuccessful();

        // custody_items soft-deletes, so the old rule left the serial burned forever.
        $this->postJson('/api/custody', $payload)->assertSuccessful();
    }

    public function test_the_legacy_item_type_follows_the_name_not_the_id(): void
    {
        // The install migration seeds ids 1..5 for the first company, so alpha's and beta's own
        // "هاتف" both land outside that range — the old id map filed them as "other".
        foreach (['alpha', 'beta'] as $code) {
            $typeId = $this->asCompany($code)
                ->postJson('/api/custody-types', ['name' => 'هاتف', 'icon' => '📱'])
                ->assertSuccessful()->json('id');

            $this->asCompany($code)->postJson('/api/custody', [
                'employee_id' => $this->companies[$code]['employee']->id,
                'custody_type_id' => $typeId,
                'item_description' => 'iPhone',
                'issued_date' => '2026-03-01',
                'handover_proof_path' => 'custody/signed.pdf',
            ])->assertSuccessful()->assertJsonPath('item_type', 'phone');
        }
    }

    public function test_an_item_with_no_type_at_all_still_saves(): void
    {
        // item_type is NOT NULL, and nothing filled it in when no custody type was chosen.
        $this->asCompany('alpha')->postJson('/api/custody', [
            'employee_id' => $this->companies['alpha']['employee']->id,
            'item_description' => 'غرض غير مصنّف',
            'issued_date' => '2026-03-01',
            'handover_proof_path' => 'custody/signed.pdf',
        ])->assertSuccessful()->assertJsonPath('item_type', 'other');
    }

    public function test_a_company_can_still_use_its_own_type_of_the_same_name(): void
    {
        $this->asCompany('alpha')->postJson('/api/custody-types', ['name' => 'هاتف'])->assertSuccessful();

        $betaType = $this->asCompany('beta')
            ->postJson('/api/custody-types', ['name' => 'هاتف'])
            ->assertSuccessful()->json('id');

        $this->asCompany('beta')->postJson('/api/custody', [
            'employee_id' => $this->companies['beta']['employee']->id,
            'custody_type_id' => $betaType,
            'item_description' => 'جهاز',
            'value' => 60.000,
            'issued_date' => '2026-03-01',
            'handover_proof_path' => 'custody/signed.pdf',
        ])->assertSuccessful();
    }
}
