<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wherever money is taken off a driver, the record has to carry the evidence for it — and until
 * now not one deduction type required any. Maintenance and accidents, the largest amounts in the
 * system, had no file field on either screen that creates a record and refused the invoice once
 * approved, so a charge could never have its invoice attached at all. Custody and salary advances
 * had no proof column in the database whatsoever. Fines and driver expenses had a field that
 * nothing enforced: every one of the owner's seven recorded expenses had no receipt, and each was
 * still deducted.
 *
 * The same file also pins the other half of the maintenance problem: its liable driver was picked
 * by hand from a dropdown of the whole company, with nothing checking that the person had ever held
 * that vehicle on that date.
 */
class EveryDeductionCarriesItsProofTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Employee $otherDriver;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Proof Co',
            'code' => 'proofco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@proof.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        foreach ([['driver', 'EMP-PF-1'], ['otherDriver', 'EMP-PF-2']] as [$prop, $number]) {
            $this->$prop = Employee::create([
                'name' => 'سائق '.$number,
                'employee_number' => $number,
                'company_id' => $this->company->id,
                'status' => 'active',
                'role_category' => 'driver',
                'date_of_joining' => '2026-01-01',
                'actual_salary' => 200.000,
            ]);
        }

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-PF-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);

        // The vehicle belonged to the first driver through March.
        VehicleAssignment::create([
            'company_id' => $this->company->id,
            'vehicle_id' => $this->vehicle->id,
            'employee_id' => $this->driver->id,
            'assigned_date' => '2026-03-01',
            'is_active' => true,
        ]);

        $this->actingAs($this->user);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Proof
    // ══════════════════════════════════════════════════════════════════════

    private function fine(array $extra = []): array
    {
        return array_merge([
            'vehicle_id' => $this->vehicle->id,
            'employee_id' => $this->driver->id,
            'violation_date' => '2026-03-10',
            'violation_type' => 'تجاوز السرعة',
            'amount' => 30.000,
            'is_driver_liable' => true,
        ], $extra);
    }

    public function test_a_fine_the_driver_pays_needs_its_ticket(): void
    {
        $this->postJson('/api/violations', $this->fine())
            ->assertStatus(422)->assertJsonValidationErrors('photo_path');

        $this->postJson('/api/violations', $this->fine(['photo_path' => 'violations/ticket.jpg']))
            ->assertSuccessful();
    }

    public function test_a_fine_the_company_pays_does_not(): void
    {
        // Nothing reaches the driver, so nothing has to be proved to him.
        $this->postJson('/api/violations', $this->fine([
            'is_driver_liable' => false,
            'driver_share' => 0,
            'reference_number' => 'REF-COMPANY',
        ]))->assertSuccessful();
    }

    public function test_a_driver_borne_expense_needs_its_receipt(): void
    {
        $payload = [
            'employee_id' => $this->driver->id,
            'expense_type' => 'fuel',
            'amount' => 12.000,
            'borne_by' => 'driver',
            'expense_date' => '2026-03-11',
        ];

        $this->postJson('/api/driver-expenses', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('receipt_path');

        $this->postJson('/api/driver-expenses', $payload + ['receipt_path' => 'expenses/r.jpg'])
            ->assertSuccessful();
    }

    public function test_custody_needs_a_signed_handover_and_proof_of_damage(): void
    {
        $issue = [
            'employee_id' => $this->driver->id,
            'item_type' => 'phone',
            'item_description' => 'هاتف',
            'value' => 60.000,
            'issued_date' => '2026-03-01',
        ];

        $this->postJson('/api/custody', $issue)
            ->assertStatus(422)->assertJsonValidationErrors('handover_proof_path');

        $created = $this->postJson('/api/custody', $issue + ['handover_proof_path' => 'custody/signed.pdf'])
            ->assertSuccessful()->json();

        $id = $created['id'] ?? $created['data']['id'];

        $this->postJson("/api/custody/{$id}/return", [
            'returned_date' => '2026-03-20',
            'return_condition' => 'damaged',
            'deduction_amount' => 25.000,
        ])->assertStatus(422)->assertJsonValidationErrors('return_proof_path');

        $this->postJson("/api/custody/{$id}/return", [
            'returned_date' => '2026-03-20',
            'return_condition' => 'damaged',
            'deduction_amount' => 25.000,
            'return_proof_path' => 'custody/damage.jpg',
        ])->assertSuccessful();
    }

    public function test_an_advance_needs_the_voucher_the_driver_signed(): void
    {
        $payload = [
            'employee_id' => $this->driver->id,
            'amount' => 100.000,
            'monthly_installment' => 25.000,
            'advance_date' => '2026-03-01',
        ];

        $this->postJson('/api/salary-advances', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('voucher_path');

        $this->postJson('/api/salary-advances', $payload + ['voucher_path' => 'advances/voucher.pdf'])
            ->assertSuccessful();
    }

    // ══════════════════════════════════════════════════════════════════════
    // Maintenance: proof at approval, and liability by date
    // ══════════════════════════════════════════════════════════════════════

    private function repair(array $extra = []): array
    {
        return array_merge([
            'vehicle_id' => $this->vehicle->id,
            'maintenance_type' => 'accident',
            'maintenance_date' => '2026-03-12',
            'estimated_cost' => 500.000,
            'is_driver_liable' => true,
            'liable_employee_id' => $this->driver->id,
            'driver_bearing_percentage' => 40,
        ], $extra);
    }

    public function test_a_driver_liable_repair_cannot_be_approved_without_its_invoice(): void
    {
        $id = $this->postJson('/api/maintenance', $this->repair())->assertSuccessful()->json('id');

        $this->postJson("/api/maintenance/{$id}/approve", ['actual_cost' => 500.000])
            ->assertStatus(422)->assertJsonValidationErrors('invoice_path');

        $this->postJson("/api/maintenance/{$id}/approve", [
            'actual_cost' => 500.000,
            'invoice_path' => 'maintenance/garage.pdf',
        ])->assertSuccessful();
    }

    public function test_a_repair_is_refused_for_a_driver_who_never_held_that_vehicle(): void
    {
        $response = $this->postJson('/api/maintenance', $this->repair([
            'liable_employee_id' => $this->otherDriver->id,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('liable_employee_id');
        $this->assertStringContainsString('لم يكن مسؤولاً', (string) $response->json('message'));

        // Naming him deliberately is allowed, in writing.
        $this->postJson('/api/maintenance', $this->repair([
            'liable_employee_id' => $this->otherDriver->id,
            'assignment_override_reason' => 'سائق بديل ليوم واحد بالاتفاق',
        ]))->assertSuccessful();
    }

    public function test_a_driver_liable_repair_with_nobody_named_is_refused(): void
    {
        $this->postJson('/api/maintenance', $this->repair(['liable_employee_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('liable_employee_id');
    }

    public function test_a_driver_liable_accident_with_no_percentage_is_refused(): void
    {
        // It used to save as 0.000 charged while still displaying as the driver's responsibility.
        $payload = $this->repair();
        unset($payload['driver_bearing_percentage']);

        $this->postJson('/api/maintenance', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('driver_bearing_percentage');
    }

    public function test_a_company_borne_repair_needs_none_of_this(): void
    {
        $id = $this->postJson('/api/maintenance', $this->repair([
            'is_driver_liable' => false,
            'liable_employee_id' => null,
        ]))->assertSuccessful()->json('id');

        $this->postJson("/api/maintenance/{$id}/approve", ['actual_cost' => 500.000])->assertSuccessful();
    }
}
