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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The owner's row at the top of the dashboard: revenue by day, the month's contribution, pending
 * cash as one figure, and the decisions waiting on him — each read from the services the reports
 * already use.
 *
 * Fixture: contracts that pay the driver a fixed 260 over 26 days, so 10.000 a logged day. «زون»
 * bills the client 0.500 an order by zone, «ثابت» bills a flat 300.000 a month, «خاسر» bills
 * 0.100 an order — less than its driver costs. Today is 2026-09-15, a 30-day month.
 */
class OwnerPulseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Client $client;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');
        // The pulse holds the month's contract figures under a key built from row counts and
        // timestamps; with the clock frozen, two tests can produce the same key for different data.
        Cache::flush();

        $this->company = Company::create([
            'name' => 'Pulse Co',
            'code' => 'pulseco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@pulse.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-PULSE-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $clientRule */
    private function contract(string $name, array $clientRule): Contract
    {
        return Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-'.uniqid(),
            'name' => $name,
            'payment_type' => 'fixed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'client_payment_method' => $clientRule['payment_method'],
            'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'is_active' => true,
            'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => $clientRule],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function zones(float $price): array
    {
        return ['payment_method' => 'zones', 'zones' => [['id' => 'z1', 'name' => 'الفئة 1', 'price' => $price]]];
    }

    private function driverOn(Contract $contract, string $number): Employee
    {
        $driver = Employee::create([
            'name' => "Driver {$number}",
            'employee_number' => $number,
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
            'official_salary' => 100.000,
        ]);
        ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $contract->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        return $driver;
    }

    /** @param  array<string, int>|null  $zones */
    private function log(Employee $driver, Contract $contract, string $date, int $orders, ?array $zones = null, float $cashPending = 0.0): void
    {
        DailyLog::create([
            'company_id' => $this->company->id,
            'employee_id' => $driver->id,
            'vehicle_id' => $this->vehicle->id,
            'contract_id' => $contract->id,
            'log_date' => $date,
            'driver_status' => 'working',
            'orders_count' => $orders,
            'orders_online' => $orders,
            'orders_cash' => 0,
            'income_amount' => 0,
            'cash_pending' => $cashPending,
            'created_by' => $this->admin->id,
            'notes' => $zones === null ? null : json_encode(['zone_orders' => $zones]),
        ]);
    }

    /** @return array<string, mixed> */
    private function pulse(): array
    {
        return $this->getJson('/api/dashboard/pulse')->assertOk()->json();
    }

    public function test_revenue_is_read_by_day_and_the_month_carries_the_contribution(): void
    {
        $zone = $this->contract('زون', $this->zones(0.5));
        $flat = $this->contract('ثابت', ['payment_method' => 'fixed', 'fixed_amount' => 300]);
        $a = $this->driverOn($zone, 'D-A');
        $b = $this->driverOn($flat, 'D-B');

        $this->log($a, $zone, '2026-09-10', 100, ['z1' => 100]);   // 50.000
        $this->log($a, $zone, '2026-09-14', 40, ['z1' => 40]);     // 20.000 yesterday
        $this->log($a, $zone, '2026-09-15', 30, ['z1' => 30]);     // 15.000 today
        $this->log($b, $flat, '2026-09-15', 10);                    // a flat 300.000 month: 10.000 a day

        $pulse = $this->pulse();
        $revenue = $pulse['revenue'];

        $this->assertSame('2026-09-15', $pulse['as_of']);
        $this->assertSame(25.0, (float) $revenue['today'], '15.000 of orders plus one day of the flat fee');
        $this->assertSame(30.0, (float) $revenue['yesterday'], '20.000 of orders; the flat fee accrues on a day without a log too');
        $this->assertSame(385.0, (float) $revenue['month_to_date'], '85.000 of orders plus the flat month whole, as it is invoiced');
        $this->assertSame(300.0, (float) $revenue['fixed_month_to_date']);
        $this->assertSame(180, $revenue['orders_month_to_date']);
        $this->assertSame(0, $revenue['unpriced_orders']);

        // Four logged days at 10.000 each come off the 385.000.
        $contribution = $pulse['contribution'];
        $this->assertSame(385.0, (float) $contribution['revenue']);
        $this->assertSame(40.0, (float) $contribution['driver_cost']);
        $this->assertSame(345.0, (float) $contribution['contribution']);
        $this->assertSame(89.6, (float) $contribution['margin_pct']);
        $this->assertSame(2, $contribution['active_contracts']);
        $this->assertSame('ثابت', $contribution['contracts'][0]['name'], 'ranked by contribution: 290 before 55');
        $this->assertSame(290.0, (float) $contribution['contracts'][0]['contribution']);
        $this->assertSame(55.0, (float) $contribution['contracts'][1]['contribution']);
        $this->assertSame([], $pulse['decisions']['negative_contracts']);
    }

    public function test_orders_no_rule_can_price_are_counted_not_valued(): void
    {
        $zone = $this->contract('زون', $this->zones(0.5));
        $a = $this->driverOn($zone, 'D-A');
        $this->log($a, $zone, '2026-09-15', 50, ['z1' => 20]);   // 30 of the 50 carry no zone

        $revenue = $this->pulse()['revenue'];
        $this->assertSame(10.0, (float) $revenue['today']);
        $this->assertSame(30, $revenue['unpriced_orders']);
    }

    public function test_the_decisions_list_losing_contracts_and_sheets_not_yet_approved(): void
    {
        $zone = $this->contract('زون', $this->zones(0.5));
        $loser = $this->contract('خاسر', $this->zones(0.1));
        $a = $this->driverOn($zone, 'D-A');
        $c = $this->driverOn($loser, 'D-C');
        $this->log($a, $zone, '2026-09-15', 100, ['z1' => 100]);   // 50.000 against 10.000
        $this->log($c, $loser, '2026-09-15', 10, ['z1' => 10]);    // 1.000 against 10.000

        $decisions = $this->pulse()['decisions'];

        $this->assertCount(1, $decisions['negative_contracts']);
        $this->assertSame('خاسر', $decisions['negative_contracts'][0]['name']);
        $this->assertSame(-9.0, (float) $decisions['negative_contracts'][0]['contribution']);

        $this->assertEqualsCanonicalizing(['زون', 'خاسر'], array_column($decisions['unapproved_sheets'], 'name'));

        // Approving a sheet takes it off the list at once, cache or no cache.
        $this->postJson("/api/payroll/contract-sheet/{$zone->id}/approve", ['year' => 2026, 'month' => 9])->assertOk();

        $this->assertSame(['خاسر'], array_column($this->pulse()['decisions']['unapproved_sheets'], 'name'));
    }

    public function test_pending_cash_is_one_figure_and_paperwork_is_counted_per_vehicle(): void
    {
        $zone = $this->contract('زون', $this->zones(0.5));
        $a = $this->driverOn($zone, 'D-A');
        $this->log($a, $zone, '2026-09-14', 5, ['z1' => 5], 4.5);
        $this->log($a, $zone, '2026-09-15', 10, ['z1' => 10], 25.5);

        Vehicle::create([
            'plate_number' => 'V-EXPIRED', 'make' => 'Nissan', 'status' => 'available',
            'company_id' => $this->company->id, 'vehicle_type_id' => 1,
            'insurance_expiry' => '2026-09-10',          // lapsed five days ago
            'next_service_due' => '2026-12-01',          // fine, outside the notice window
        ]);
        Vehicle::create([
            'plate_number' => 'V-BLANK', 'make' => 'Kia', 'status' => 'idle',
            'company_id' => $this->company->id, 'vehicle_type_id' => 1,
        ]);
        Vehicle::create([
            'plate_number' => 'V-HELD', 'make' => 'Honda', 'status' => 'reserved',
            'company_id' => $this->company->id, 'vehicle_type_id' => 1,
            'reserved_by' => 'الداخلية', 'reserved_until' => '2026-09-18',
        ]);
        Vehicle::create([
            'plate_number' => 'V-HELD-LATER', 'make' => 'Honda', 'status' => 'reserved',
            'company_id' => $this->company->id, 'vehicle_type_id' => 1,
            'reserved_by' => 'المرور', 'reserved_until' => '2026-10-15',
        ]);

        $pulse = $this->pulse();

        $this->assertSame(30.0, (float) $pulse['pending_cash']['total']);
        $this->assertSame(1, $pulse['pending_cash']['drivers']);
        $this->assertArrayNotHasKey('names', $pulse['pending_cash']);

        $documents = $pulse['decisions']['vehicle_documents'];
        $this->assertSame(1, $documents['vehicles_count'], 'only the vehicle with a lapsed document');
        $this->assertSame(1, $documents['expired_count']);
        // The fixture vehicle, V-BLANK and the two held vehicles have nothing entered: a gap in
        // the records, not an expiry — counted apart so it can never inflate the headline.
        $this->assertSame(4, $documents['missing_only_count']);
        $this->assertSame('V-EXPIRED', $documents['vehicles'][0]['plate_number']);
        $this->assertSame('expired', $documents['vehicles'][0]['issues'][0]['severity']);
        $this->assertSame(-5, $documents['vehicles'][0]['issues'][0]['days_remaining']);
        $this->assertSame('تأمين السيارة', $documents['vehicles'][0]['issues'][0]['document']);
        $this->assertSame(2, $documents['vehicles'][0]['missing_documents']);

        $reservations = $pulse['decisions']['reservations'];
        $this->assertSame(2, $reservations['reserved_count']);
        $this->assertCount(1, $reservations['due'], 'only the hold ending within a week');
        $this->assertSame('V-HELD', $reservations['due'][0]['plate_number']);
        $this->assertSame('الداخلية', $reservations['due'][0]['reserved_by']);
        $this->assertSame(3, $reservations['due'][0]['days_left']);
    }

    public function test_a_vehicle_can_be_held_by_an_authority_until_a_date_and_is_not_assigned_meanwhile(): void
    {
        $vehicle = $this->vehicle;

        $this->putJson("/api/vehicles/{$vehicle->id}", ['status' => 'reserved'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reserved_by']);

        $this->putJson("/api/vehicles/{$vehicle->id}", [
            'status' => 'reserved', 'reserved_by' => 'المرور', 'reserved_until' => '2026-09-20', 'reserved_note' => 'حجز مخالفة',
        ])->assertOk();

        $vehicle->refresh();
        $this->assertSame('reserved', $vehicle->status);
        $this->assertSame('المرور', $vehicle->reserved_by);
        $this->assertSame('2026-09-20', $vehicle->reserved_until->toDateString());

        $driver = $this->driverOn($this->contract('زون', $this->zones(0.5)), 'D-A');
        $this->postJson("/api/vehicles/{$vehicle->id}/assign", ['employee_id' => $driver->id, 'assigned_date' => '2026-09-15'])
            ->assertStatus(422);

        // Releasing the hold clears who held it and until when.
        $this->putJson("/api/vehicles/{$vehicle->id}", ['status' => 'available'])->assertOk();
        $vehicle->refresh();
        $this->assertSame('available', $vehicle->status);
        $this->assertNull($vehicle->reserved_by);
        $this->assertNull($vehicle->reserved_until);
    }
}
