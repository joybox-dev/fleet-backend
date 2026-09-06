<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cash-settlement screen used to list a card per (driver, vehicle) while a settlement without a
 * day attached ran FIFO over the driver's whole ledger. A driver who had held two plates therefore
 * got two cards, and settling the smaller one paid down the *other* card's oldest days: the card the
 * accountant clicked did not move, and a different one did.
 *
 * The owner's ruling settles it — "الدين دين السائق مو المركبة". One card per driver, carrying every
 * plate the balance was collected on, and its total is exactly what a full settlement clears.
 *
 * The same card also called its record count "days late". A driver with eight pending days going
 * back six weeks read as "متأخر 8 أيام"; lateness is now measured from the oldest unsettled day.
 */
class CashIsOwedByTheDriverNotTheVehicleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Contract $contract;

    private Vehicle $firstBike;

    private Vehicle $secondBike;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Cash Co',
            'code' => 'cashco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Cash Admin',
            'email' => 'admin@cash.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Cash Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Two Plate Driver',
            'employee_number' => 'EMP-CASH-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->firstBike = Vehicle::create([
            'plate_number' => 'PLATE-OLD',
            'make' => 'Honda',
            'status' => 'working',
            'company_id' => $this->company->id,
        ]);

        $this->secondBike = Vehicle::create([
            'plate_number' => 'PLATE-NEW',
            'make' => 'Yamaha',
            'status' => 'working',
            'company_id' => $this->company->id,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-CASH',
            'name' => 'Cash Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => 26,
            'is_validity_enabled' => false,
        ]);

        $this->actingAs($this->user);
    }

    private function log(string $date, Vehicle $vehicle, float $cash): DailyLog
    {
        return DailyLog::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'vehicle_id' => $vehicle->id,
            'log_date' => $date,
            'driver_status' => 'working',
            'orders_count' => 1,
            'cash_collected' => $cash,
            'cash_settled' => 0,
            'cash_pending' => $cash,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);
    }

    /** The four days he ran on the old plate, then the one on the new one. */
    private function twoPlateMonth(): void
    {
        $this->log('2026-03-02', $this->firstBike, 5.590);
        $this->log('2026-03-03', $this->firstBike, 1.500);
        $this->log('2026-03-04', $this->firstBike, 1.020);
        $this->log('2026-03-05', $this->firstBike, 6.400);
        $this->log('2026-03-06', $this->secondBike, 15.650);
    }

    /** @return array<string, mixed> */
    private function card(): array
    {
        $response = $this->getJson('/api/cash-settlements/pending')->assertOk();
        $drivers = $response->json('drivers');
        $mine = collect($drivers)->where('employee_id', $this->driver->id)->values();

        $this->assertCount(1, $mine, 'the driver must appear on exactly one card, whatever he drove');

        return $mine->first();
    }

    public function test_one_card_carries_the_whole_driver_balance_across_plates(): void
    {
        $this->twoPlateMonth();

        $card = $this->card();

        $this->assertEqualsWithDelta(30.160, $card['total_pending'], 0.0005);
        $this->assertSame(5, $card['days_outstanding']);
        $this->assertEqualsCanonicalizing(['PLATE-OLD', 'PLATE-NEW'], $card['vehicle_plates']);
    }

    public function test_settling_the_card_total_clears_every_plate(): void
    {
        $this->twoPlateMonth();

        $this->postJson('/api/cash-settlements', [
            'employee_id' => $this->driver->id,
            'settlement_date' => '2026-03-10',
            'amount' => 30.160,
        ])->assertCreated();

        $this->assertEqualsWithDelta(
            0.0,
            (float) DailyLog::where('employee_id', $this->driver->id)->sum('cash_pending'),
            0.0005,
            'the card total was the whole debt, so paying it must leave nothing behind on either plate'
        );

        $this->assertSame(
            [],
            collect($this->getJson('/api/cash-settlements/pending')->json('drivers'))
                ->where('employee_id', $this->driver->id)->values()->all()
        );
    }

    public function test_a_part_payment_walks_the_days_in_date_order_across_plates(): void
    {
        $this->twoPlateMonth();

        // 5.590 + 1.500 + 1.020 = 8.110 closes the first three days; the fourth takes the rest.
        $this->postJson('/api/cash-settlements', [
            'employee_id' => $this->driver->id,
            'settlement_date' => '2026-03-10',
            'amount' => 10.000,
        ])->assertCreated();

        $pendingByDate = DailyLog::where('employee_id', $this->driver->id)
            ->orderBy('log_date')->pluck('cash_pending', 'log_date')
            ->map(fn ($v) => round((float) $v, 3))->all();

        $this->assertSame([
            '2026-03-02' => 0.0,
            '2026-03-03' => 0.0,
            '2026-03-04' => 0.0,
            '2026-03-05' => 4.510,
            '2026-03-06' => 15.650,
        ], $pendingByDate);
    }

    public function test_lateness_is_measured_from_the_oldest_unsettled_day_not_the_record_count(): void
    {
        $this->travelTo('2026-03-20');
        $this->twoPlateMonth();

        $card = $this->card();

        $this->assertSame('2026-03-02', substr((string) $card['oldest_date'], 0, 10));
        $this->assertSame(18, $card['days_late'], 'eighteen days since 2 March — not the five records');
        $this->assertNotSame($card['days_outstanding'], $card['days_late']);
    }
}
