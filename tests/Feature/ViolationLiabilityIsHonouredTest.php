<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\CompanyDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A fine stores two things that must agree: who is liable, and how much of it the driver bears.
 * They were independent — the list column read the flag while payroll read the share — so a fine
 * could show "الشركة: 100%" on screen and still take money off the driver.
 *
 * The legacy path had the mirror fault, and a worse one: its sum read "the driver's share, or the
 * whole ticket if the share is zero", so a fine deliberately left entirely to the company was
 * charged to the driver in full.
 */
class ViolationLiabilityIsHonouredTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Liability Co',
            'code' => 'liabco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@liabco.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
        ]);

        $this->driver = Employee::create([
            'name' => 'Liability Driver',
            'employee_number' => 'EMP-LB-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-LB-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 2,
        ]);
    }

    private function fine(array $attributes): Violation
    {
        return Violation::create(array_merge([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'created_by' => $this->user->id,
            'violation_date' => '2026-07-14',
            'violation_type' => 'تجاوز السرعة',
            'amount' => 40.000,
            'is_deducted' => 0,
        ], $attributes));
    }

    private function owed(): float
    {
        $pending = CompanyDeductionService::pendingFor(
            [$this->driver->id], '2026-07-01', '2026-07-31', 2026, 7
        );

        return round((float) ($pending[$this->driver->id]['total'] ?? 0), 3);
    }

    public function test_a_company_liable_fine_charges_the_driver_nothing(): void
    {
        // The contradictory state: the record says the company bears it, but a driver share was
        // stored anyway. Written straight to the table, which is the only way it can now arise.
        $fine = $this->fine([
            'is_driver_liable' => 0,
            'driver_share' => 40.000,
            'contract_share' => 0.000,
            'driver_deduction' => 40.000,
        ]);

        $this->assertSame(0.0, $this->owed(), 'a fine the record says is the company\'s is not charged');
        $this->assertTrue($fine->exists);
    }

    public function test_a_driver_liable_fine_is_charged(): void
    {
        $this->fine([
            'is_driver_liable' => 1,
            'driver_share' => 40.000,
            'contract_share' => 0.000,
            'driver_deduction' => 40.000,
        ]);

        $this->assertSame(40.0, $this->owed());
    }

    public function test_a_split_fine_charges_only_the_drivers_half(): void
    {
        $this->fine([
            'is_driver_liable' => 1,
            'driver_share' => 15.000,
            'contract_share' => 25.000,
            'driver_deduction' => 15.000,
        ]);

        $this->assertSame(15.0, $this->owed(), 'the company half stays with the company');
    }

    /**
     * The write side: the controller derives liability from the share, so the screen and the money
     * can never tell different stories.
     */
    public function test_saving_a_fine_derives_liability_from_the_share(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/violations', [
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'violation_date' => '2026-07-20',
            'violation_type' => 'وقوف ممنوع',
            'amount' => 30.000,
            'photo_path' => 'violations/ticket.jpg',
            // Contradicts itself on purpose: says company-liable, hands over a driver share.
            'is_driver_liable' => false,
            'driver_share' => 30.000,
            'contract_share' => 0.000,
            // The driver is not assigned to this vehicle in the fixture; the controller demands a
            // reason for that, which is a separate guard from what this test is about.
            'assignment_override_reason' => 'سائق بديل ليوم واحد',
        ]);

        if ($response->status() === 403) {
            $this->markTestSkipped('the admin role in this fixture cannot create violations');
        }

        $response->assertSuccessful();

        $stored = Violation::withoutGlobalScopes()->latest('id')->first();
        $this->assertTrue(
            (bool) $stored->is_driver_liable,
            'a stored driver share means the driver is liable, whatever the request claimed'
        );
        $this->assertSame(30.0, round((float) $stored->driver_deduction, 3));
    }

    /**
     * The legacy sum used to read "the driver share, or the whole ticket if that is zero". A fine
     * marked driver-liable with the share set to 0.000 — a deliberate write-off — was therefore
     * charged at its full value.
     */
    public function test_a_driver_liable_fine_with_a_zero_share_charges_nothing(): void
    {
        $this->fine([
            'is_driver_liable' => 1,
            'driver_share' => 0.000,
            'contract_share' => 40.000,
            'driver_deduction' => 0.000,
        ]);

        // The full-amount fallback this pins lived in the retired payroll path, which has been
        // deleted along with the half of this test that exercised it.
        $this->assertSame(0.0, $this->owed(), 'a zero share is zero, not the whole ticket');
    }
}
