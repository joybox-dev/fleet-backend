<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An approved consolidated month is frozen: it serves its snapshot and is never recomputed. A
 * daily log written into it afterwards is therefore work the driver is never paid for.
 *
 * The month-wide lock used to be read off the retired legacy payroll run, which is never created
 * any more and so was always false. What was left was a lock scoped to a single contract — and on
 * the bulk path, to whichever contract happened to be in row zero. A driver on a second contract,
 * or a bulk save whose rows spanned two, walked straight into a closed month.
 */
class DailyLogLockedByConsolidatedMonthTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Vehicle $vehicle;

    private Contract $approved;

    private Contract $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Lock Test Company',
            'code' => 'locktest',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Lock Admin',
            'email' => 'admin@lock.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Lock Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Lock Driver',
            'employee_number' => 'EMP-LOCK-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-LOCK-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->approved = $this->contract($client->id, 'CON-LOCK-A', 'Approved Contract');
        $this->other = $this->contract($client->id, 'CON-LOCK-B', 'Second Contract');

        foreach ([$this->approved, $this->other] as $contract) {
            ContractAssignment::create([
                'employee_id' => $this->driver->id,
                'contract_id' => $contract->id,
                'start_date' => '2026-01-01',
                'status' => 'active',
                'company_id' => $this->company->id,
            ]);
        }

        DailyLog::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->approved->id,
            'vehicle_id' => $this->vehicle->id,
            'log_date' => '2026-03-02',
            'driver_status' => 'working',
            'orders_count' => 0,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user);

        // Only the first contract's sheet is approved — the second stays open, which is exactly
        // the hole the month-wide lock has to cover.
        $this->postJson("/api/payroll/contract-sheet/{$this->approved->id}/approve", [
            'year' => 2026, 'month' => 3,
        ])->assertOk();
        $this->postJson('/api/payroll/consolidated/2026/3/approve')->assertOk();
    }

    private function contract(int $clientId, string $number, string $name): Contract
    {
        return Contract::create([
            'client_id' => $clientId,
            'contract_number' => $number,
            'name' => $name,
            'payment_type' => 'fixed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed',
            'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);
    }

    private function payload(int $contractId, string $date): array
    {
        return [
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'contract_id' => $contractId,
            'log_date' => $date,
            'orders_count' => 0,
            'driver_status' => 'working',
        ];
    }

    public function test_a_log_on_an_unapproved_contract_is_blocked_by_the_closed_month(): void
    {
        $this->postJson('/api/daily-logs', $this->payload($this->other->id, '2026-03-15'))
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'المجمّع'));

        $this->assertSame(0, DailyLog::withoutGlobalScopes()->where('contract_id', $this->other->id)->count());
    }

    public function test_a_bulk_save_is_blocked_by_the_closed_month(): void
    {
        $this->postJson('/api/daily-logs/bulk', [
            'logs' => [
                $this->payload($this->other->id, '2026-03-16'),
                $this->payload($this->other->id, '2026-03-17'),
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'المجمّع'));

        $this->assertSame(0, DailyLog::withoutGlobalScopes()->where('contract_id', $this->other->id)->count());
    }

    public function test_an_open_month_still_accepts_logs(): void
    {
        // April was never approved; nothing about March may reach into it.
        $this->postJson('/api/daily-logs', $this->payload($this->other->id, '2026-04-06'))
            ->assertStatus(201);
    }

    public function test_reopening_the_month_makes_it_writable_again(): void
    {
        $this->postJson('/api/payroll/consolidated/2026/3/unapprove')->assertOk();

        // The contract sheet for the second contract is still open, so nothing blocks this now.
        $this->postJson('/api/daily-logs', $this->payload($this->other->id, '2026-03-18'))
            ->assertStatus(201);
    }
}
