<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustodyType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * custody_types predates multi-tenancy, so its name was unique across the whole installation and
 * company_id was added later without revisiting the index. Every company shared one namespace: the
 * second company to add a custody type called "هاتف" got a raw 1062 duplicate-key error, and no name
 * the first company had taken was ever available to anyone else again.
 */
class CustodyTypeNamesAreScopedToTheCompanyTest extends TestCase
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

            $this->companies[$code] = [
                'company' => $company,
                'user' => User::create([
                    'name' => 'Admin '.$code,
                    'email' => "admin@{$code}.test",
                    'password' => bcrypt('password'),
                    'role' => 'admin',
                    'company_id' => $company->id,
                ]),
            ];
        }
    }

    private function asCompany(string $code): self
    {
        $company = $this->companies[$code]['company'];
        app()->instance('current_company_id', $company->id);
        $this->actingAs($this->companies[$code]['user']);

        return $this;
    }

    public function test_two_companies_can_each_have_a_custody_type_called_the_same_thing(): void
    {
        // create_custody_types seeds five default names and enforce_company_id_not_null hands them
        // to one company, so "هاتف" is already taken before either of these companies exists — which
        // is exactly the situation the old global index made unrecoverable.
        $before = CustodyType::withoutGlobalScopes()->where('name', 'هاتف')->count();

        $this->asCompany('alpha')
            ->postJson('/api/custody-types', ['name' => 'هاتف', 'icon' => '📱'])
            ->assertSuccessful();

        // This is the exact request that used to die on a duplicate-key error.
        $this->asCompany('beta')
            ->postJson('/api/custody-types', ['name' => 'هاتف', 'icon' => '📱'])
            ->assertSuccessful();

        $this->assertSame(
            $before + 2,
            CustodyType::withoutGlobalScopes()->where('name', 'هاتف')->count(),
            'each company keeps its own row'
        );
    }

    public function test_the_same_name_twice_inside_one_company_is_still_refused(): void
    {
        $this->asCompany('alpha')
            ->postJson('/api/custody-types', ['name' => 'شريحة'])
            ->assertSuccessful();

        $this->asCompany('alpha')
            ->postJson('/api/custody-types', ['name' => 'شريحة'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name')
            // The settings screen shows this string verbatim, so it has to be Arabic.
            ->assertJsonFragment(['name' => ['يوجد نوع عهدة بهذا الاسم في شركتك.']]);
    }

    public function test_renaming_onto_a_name_another_company_uses_is_allowed(): void
    {
        $this->asCompany('alpha')->postJson('/api/custody-types', ['name' => 'زي رسمي'])->assertSuccessful();

        $betaType = $this->asCompany('beta')
            ->postJson('/api/custody-types', ['name' => 'قبعة'])
            ->assertSuccessful()->json('id');

        $this->asCompany('beta')
            ->putJson("/api/custody-types/{$betaType}", ['name' => 'زي رسمي'])
            ->assertSuccessful();
    }

    public function test_renaming_onto_a_name_the_same_company_uses_is_refused(): void
    {
        $this->asCompany('alpha')->postJson('/api/custody-types', ['name' => 'كاش'])->assertSuccessful();
        $second = $this->asCompany('alpha')
            ->postJson('/api/custody-types', ['name' => 'كرت بنزين'])
            ->assertSuccessful()->json('id');

        $this->asCompany('alpha')
            ->putJson("/api/custody-types/{$second}", ['name' => 'كاش'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_a_type_can_keep_its_own_name_when_only_its_icon_changes(): void
    {
        $id = $this->asCompany('alpha')
            ->postJson('/api/custody-types', ['name' => 'شنطة', 'icon' => '🎒'])
            ->assertSuccessful()->json('id');

        $this->asCompany('alpha')
            ->putJson("/api/custody-types/{$id}", ['name' => 'شنطة', 'icon' => '👜'])
            ->assertSuccessful();
    }

    public function test_a_deleted_name_becomes_available_again(): void
    {
        $this->asCompany('alpha');

        $id = $this->postJson('/api/custody-types', ['name' => 'جهاز لاسلكي'])->assertSuccessful()->json('id');
        $this->deleteJson("/api/custody-types/{$id}")->assertSuccessful();

        // The table does not soft-delete, so nothing lingers to block the name.
        $this->postJson('/api/custody-types', ['name' => 'جهاز لاسلكي'])->assertSuccessful();
    }

    public function test_one_company_never_sees_another_companys_types(): void
    {
        $this->asCompany('alpha')->postJson('/api/custody-types', ['name' => 'هاتف'])->assertSuccessful();
        $this->asCompany('beta')->postJson('/api/custody-types', ['name' => 'هاتف'])->assertSuccessful();

        $alphaList = $this->asCompany('alpha')->getJson('/api/custody-types')->assertSuccessful()->json();
        $betaList = $this->asCompany('beta')->getJson('/api/custody-types')->assertSuccessful()->json();

        $this->assertCount(1, $alphaList);
        $this->assertCount(1, $betaList);
        $this->assertNotSame($alphaList[0]['id'], $betaList[0]['id']);
    }
}
