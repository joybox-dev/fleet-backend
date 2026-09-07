<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عقد الدوائية pays its سيكل a fixed monthly salary and its صالون by bands — two vehicle types,
 * two methods, one contract. Both engines already work that way: `calculateDriverContractPayroll`
 * and the revenue service read the method out of the vehicle type's OWN rule, and the contract's
 * `driver_payment_method` column is only consulted for a type that has no rule at all.
 *
 * Three guards on the way in did not: the column was filled from the rules only when every type
 * agreed, so a mixed contract arrived with it blank and was refused «The driver payment method
 * field is required»; a rule whose method differed from that column was refused outright; and the
 * zones-need-a-zone-client check was asked of the column, which can only name one method, instead
 * of the vehicle type actually priced by zone.
 */
class AContractPricesEachVehicleTypeItsOwnWayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Mixed Methods Co',
            'code' => 'mixedmethods',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Mixed Admin',
            'email' => 'admin@mixedmethods.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->client = Client::create(['name' => 'Mixed Client', 'company_id' => $this->company->id]);

        $this->actingAs($this->user);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-MIXED',
            'name' => 'صيدليات الدوائية',
            'payment_type' => 'per_order',
            'start_date' => '2026-05-01',
            'end_date' => '2028-04-30',
            'currency' => 'KWD',
            'default_required_work_days' => 26,
            'is_validity_enabled' => false,

            // The سيكل is billed and paid flat; the صالون is billed by zone and paid by bands.
            'client_pricing_rules' => [
                '1' => ['payment_method' => 'fixed', 'fixed_amount' => 500],
                '2' => ['payment_method' => 'zones', 'zones' => [
                    ['id' => 'Z1', 'name' => 'الفئة 1', 'price' => 0.400],
                    ['id' => 'Z2', 'name' => 'الفئة 2', 'price' => 0.500],
                ]],
            ],
            'driver_pricing_rules' => [
                '1' => ['payment_method' => 'fixed', 'fixed_amount' => 220, 'fixed_target' => 0],
                '2' => ['payment_method' => 'zones_tiers', 'zones_tiers' => [
                    ['id' => 'Z1', 'name' => 'الفئة 1', 'tiers' => [['min' => 1, 'max' => null, 'price' => 0.300]]],
                    ['id' => 'Z2', 'name' => 'الفئة 2', 'tiers' => [['min' => 1, 'max' => null, 'price' => 0.400]]],
                ]],
            ],
        ], $overrides);
    }

    public function test_a_contract_may_price_one_vehicle_type_flat_and_another_by_zone(): void
    {
        // The payload the screen sends: per-type rules, and no top-level method at all.
        $this->postJson('/api/contracts', $this->payload())->assertCreated();

        $contract = Contract::withoutGlobalScopes()->where('contract_number', 'CON-MIXED')->first();
        $this->assertNotNull($contract);
        $this->assertSame('fixed', $contract->driver_pricing_rules['1']['payment_method']);
        $this->assertSame('zones_tiers', $contract->driver_pricing_rules['2']['payment_method']);
    }

    public function test_the_summary_column_is_filled_from_the_rules_rather_than_demanded(): void
    {
        $this->postJson('/api/contracts', $this->payload())->assertCreated();

        $contract = Contract::withoutGlobalScopes()->where('contract_number', 'CON-MIXED')->first();

        // It is only the fallback for a vehicle type with no rule, so any of the contract's own
        // methods is honest — what matters is that it is set, and set to one the contract uses.
        $this->assertContains($contract->driver_payment_method, ['fixed', 'zones_tiers']);
        $this->assertContains($contract->client_payment_method, ['fixed', 'zones']);
    }

    public function test_a_zone_paid_type_still_needs_that_same_type_billed_by_zone(): void
    {
        // The صالون is paid by zone while its client side is flat — the real mistake this guards.
        $payload = $this->payload();
        $payload['client_pricing_rules']['2'] = ['payment_method' => 'fixed', 'fixed_amount' => 700];

        $this->postJson('/api/contracts', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('driver_payment_method');
    }

    public function test_the_other_types_zone_map_does_not_excuse_it(): void
    {
        // Type 1 is billed by zone and type 2 is not; type 2 is the one paid by zone. Asking the
        // contract's summary column would have found a zone client somewhere and let it through.
        $payload = $this->payload();
        $payload['client_pricing_rules']['1'] = ['payment_method' => 'zones', 'zones' => [
            ['id' => 'Z1', 'name' => 'الفئة 1', 'price' => 0.250],
        ]];
        $payload['client_pricing_rules']['2'] = ['payment_method' => 'fixed', 'fixed_amount' => 700];

        $this->postJson('/api/contracts', $payload)->assertStatus(422);
    }

    public function test_a_mixed_contract_can_be_edited_without_restating_its_method(): void
    {
        $this->postJson('/api/contracts', $this->payload())->assertCreated();
        $contract = Contract::withoutGlobalScopes()->where('contract_number', 'CON-MIXED')->first();

        // The صالون moves to the new monthly-band method; the سيكل stays on its fixed salary.
        $rules = $contract->driver_pricing_rules;
        $rules['2'] = ['payment_method' => 'tiered_zones', 'tiered_zones' => [
            ['id' => 'B1', 'min' => 1, 'max' => 250, 'label' => 'سيء', 'bonus' => 0,
                'prices' => ['Z1' => 0.300, 'Z2' => 0.400]],
            ['id' => 'B2', 'min' => 251, 'max' => null, 'label' => 'جيد', 'bonus' => 20,
                'prices' => ['Z1' => 0.500, 'Z2' => 0.600]],
        ]];

        $this->putJson("/api/contracts/{$contract->id}", [
            'driver_pricing_rules' => $rules,
            'client_pricing_rules' => $contract->client_pricing_rules,
        ])->assertOk();

        $this->assertSame(
            'tiered_zones',
            $contract->fresh()->driver_pricing_rules['2']['payment_method']
        );
    }

    public function test_a_rule_with_no_method_of_its_own_is_still_refused(): void
    {
        $payload = $this->payload();
        unset($payload['driver_pricing_rules']['2']['payment_method']);

        $this->postJson('/api/contracts', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('driver_pricing_rules');
    }
}
