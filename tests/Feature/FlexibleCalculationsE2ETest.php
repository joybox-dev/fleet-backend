<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlexibleCalculationsE2ETest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA; // Buraq (Kuwait - KWD)

    protected Company $companyB; // Eagle (Saudi - SAR)

    protected User $adminA;

    protected User $adminB;

    protected Client $clientKheta;

    protected Client $clientDeliveroo;

    protected Client $clientLulu;

    protected Client $clientFixed;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Company A (Kuwait) and its Admin
        $this->companyA = Company::create([
            'name' => 'Buraq Logistics',
            'code' => 'buraq',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        $this->adminA = User::create([
            'name' => 'Buraq Admin',
            'email' => 'buraq@fleetops.kw',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->companyA->id,
            'is_active' => true,
        ]);

        // 2. Create Company B (Saudi) and its Admin
        $this->companyB = Company::create([
            'name' => 'Eagle Delivery',
            'code' => 'eagle',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        $this->adminB = User::create([
            'name' => 'Eagle Admin',
            'email' => 'eagle@fleetops.sa',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->companyB->id,
            'is_active' => true,
        ]);

        // Set Company A context by default
        app()->instance('current_company_id', $this->companyA->id);
    }

    /**
     * Test Tenant isolation (SaaS checks).
     */
    public function test_saas_tenant_isolation(): void
    {
        // Act as Admin A
        $this->actingAs($this->adminA);

        // Create a contract in Company A
        $clientA = Client::create([
            'name' => 'Client A',
            'company_id' => $this->companyA->id,
        ]);
        $contractA = Contract::create([
            'client_id' => $clientA->id,
            'contract_number' => 'CON-A',
            'name' => 'Contract A',
            'payment_type' => 'per_order',
            'company_id' => $this->companyA->id,
            'is_active' => true,
            'start_date' => '2026-01-01',
        ]);

        // Act as Admin B
        $this->actingAs($this->adminB);
        app()->instance('current_company_id', $this->companyB->id);

        // Try to fetch Contract A -> should not see it (index or show)
        $response = $this->getJson('/api/contracts');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data')); // Asserting on pagination data key

        $responseShow = $this->getJson("/api/contracts/{$contractA->id}");
        $responseShow->assertStatus(404); // Or 403 depending on global scopes
    }
}
