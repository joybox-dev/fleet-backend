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
use App\Models\VehicleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An order cannot be written in a way the client would then be billed nothing for.
 *
 * The contract prices each vehicle type against its own zone list: the same names, different ids —
 * exactly the client's «آرض الطبيعة». On that contract 468 orders of two months were billed at
 * zero: days saved with no zone, days left with the zone boxes emptied, and every day of the two
 * drivers who changed vehicle type mid-month, whose zones the month grid could not offer. Nothing
 * refused any of it. The writers now ask the reader that prices the month.
 *
 * The driver is paid by tier here on purpose: zones are the CLIENT's question.
 */
class DailyLogZoneGuardTest extends TestCase
{
    use RefreshDatabase;

    private const SEDAN_EXPRESS = '1784972443092';

    private const SEDAN_NORMAL = '1784972484741';

    private const VAN_EXPRESS = '1784972514443';

    private const VAN_NORMAL = '1784972551705';

    private Company $company;

    private User $user;

    private Employee $driver;

    private Vehicle $sedan;

    private Vehicle $van;

    private Vehicle $bike;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Zone Guard Co',
            'code' => 'zoneguard',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Zone Admin',
            'email' => 'admin@zone.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Zone Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Zone Driver',
            'employee_number' => 'EMP-ZONE-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->bike = $this->vehicle('V-ZONE-BIKE', 1);
        $this->sedan = $this->vehicle('V-ZONE-SEDAN', 2);
        $this->van = $this->vehicle('V-ZONE-VAN', 3);

        $tiers = ['payment_method' => 'tiers', 'tiers' => [['min' => '1', 'max' => '', 'price' => '0.500']]];

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-ZONE',
            'name' => 'Zone Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'is_validity_enabled' => false,
            'client_pricing_rules' => [
                '1' => ['payment_method' => 'fixed', 'fixed_amount' => 300],
                '2' => ['payment_method' => 'zones', 'zones' => [
                    ['id' => self::SEDAN_EXPRESS, 'name' => 'اكسبريس', 'price' => '1.500'],
                    ['id' => self::SEDAN_NORMAL, 'name' => 'العادي', 'price' => '1.000'],
                ]],
                '3' => ['payment_method' => 'zones', 'zones' => [
                    ['id' => self::VAN_EXPRESS, 'name' => 'اكسبريس', 'price' => '2.500'],
                    ['id' => self::VAN_NORMAL, 'name' => 'العادي', 'price' => '2.000'],
                ]],
            ],
            'driver_pricing_rules' => ['1' => $tiers, '2' => $tiers, '3' => $tiers],
        ]);

        ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user);
    }

    private function vehicle(string $plate, int $typeId): Vehicle
    {
        return Vehicle::create([
            'plate_number' => $plate,
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => $typeId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $with
     * @return array<string, mixed>
     */
    private function day(string $date, Vehicle $vehicle, int $orders, array $with = []): array
    {
        return $with + [
            'employee_id' => $this->driver->id,
            'vehicle_id' => $vehicle->id,
            'contract_id' => $this->contract->id,
            'log_date' => $date,
            'orders_count' => $orders,
            'orders_online' => $orders,
        ];
    }

    /** @param  array<string, int>  $split */
    private function split(array $split): string
    {
        return json_encode(['zone_orders' => $split, 'rejected_orders_count' => 0]);
    }

    public function test_orders_with_no_zone_are_refused_where_the_client_is_billed_by_zone(): void
    {
        $this->postJson('/api/daily-logs', $this->day('2026-09-01', $this->sedan, 9))
            ->assertStatus(422)
            ->assertJsonValidationErrors('zone')
            ->assertJsonPath('message', fn ($m) => str_contains($m, '9 طلب') && str_contains($m, 'بصفر'));

        // The boxes typed into and emptied again: a key with no orders under it is no zone.
        $this->postJson('/api/daily-logs', $this->day('2026-09-01', $this->sedan, 9, ['notes' => $this->split([self::SEDAN_EXPRESS => 0])]))
            ->assertStatus(422);

        $this->assertSame(0, DailyLog::withoutGlobalScopes()->count());
    }

    public function test_a_zone_named_in_the_column_or_a_full_split_is_accepted(): void
    {
        $this->postJson('/api/daily-logs', $this->day('2026-09-01', $this->sedan, 9, ['zone' => 'اكسبريس']))->assertStatus(201);
        $this->postJson('/api/daily-logs', $this->day('2026-09-02', $this->sedan, 9, [
            'notes' => $this->split([self::SEDAN_EXPRESS => 6, self::SEDAN_NORMAL => 3]),
        ]))->assertStatus(201);

        // A rest day, and a day on a vehicle type the client pays a flat fee for, ask nothing.
        $this->postJson('/api/daily-logs', $this->day('2026-09-03', $this->sedan, 0))->assertStatus(201);
        $this->postJson('/api/daily-logs', $this->day('2026-09-04', $this->bike, 14))->assertStatus(201);
    }

    /**
     * The driver who changed from a sedan to a van on the 5th: the van's days carry the van's ids.
     * Saved under the sedan's they match no rule of the van's and bill at nothing, in silence.
     */
    public function test_a_split_under_another_vehicle_types_ids_is_refused(): void
    {
        $this->postJson('/api/daily-logs', $this->day('2026-09-05', $this->van, 8, [
            'notes' => $this->split([self::SEDAN_EXPRESS => 8]),
        ]))->assertStatus(422)->assertJsonValidationErrors('zone');

        $this->postJson('/api/daily-logs', $this->day('2026-09-05', $this->van, 8, [
            'notes' => $this->split([self::VAN_EXPRESS => 5, self::VAN_NORMAL => 3]),
        ]))->assertStatus(201);

        // Part of a day is still part: five orders zoned of eight leaves three billed at nothing.
        $this->postJson('/api/daily-logs', $this->day('2026-09-06', $this->van, 8, [
            'notes' => $this->split([self::VAN_EXPRESS => 5]),
        ]))->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '3 طلب'));
    }

    public function test_the_month_grid_saves_the_good_days_and_names_the_one_it_refused(): void
    {
        $result = $this->postJson('/api/daily-logs/bulk', ['logs' => [
            $this->day('2026-09-01', $this->sedan, 4, ['zone' => 'العادي', 'notes' => $this->split([self::SEDAN_NORMAL => 4])]),
            $this->day('2026-09-02', $this->sedan, 7),
            $this->day('2026-09-03', $this->van, 2, ['notes' => $this->split([self::VAN_NORMAL => 2])]),
        ]])->assertOk()->json();

        $this->assertSame(2, $result['count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame('orders_without_zone', $result['skipped'][0]['reason']);
        $this->assertSame(['2026-09-02'], $result['skipped_dates']);
        $this->assertSame(2, DailyLog::withoutGlobalScopes()->count());
    }

    /**
     * What is already on file is not held hostage: a day saved before the guard keeps accepting
     * edits that leave its billing alone. It is a change that would leave orders unbilled — more
     * of them, or the zone taken away — that is refused.
     */
    public function test_an_old_unzoned_day_stays_editable_until_its_orders_are_touched(): void
    {
        $old = DailyLog::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->sedan->id,
            'contract_id' => $this->contract->id,
            'log_date' => '2026-09-10',
            'orders_count' => 6,
            'orders_online' => 6,
            'driver_status' => 'working',
            'created_by' => $this->user->id,
        ]);

        $this->putJson("/api/daily-logs/{$old->id}", ['orders_count' => 6, 'zone' => null, 'notes' => null, 'cash_collected' => 4.25])
            ->assertOk();
        $this->assertSame(4.25, (float) $old->fresh()->cash_collected);

        $this->putJson("/api/daily-logs/{$old->id}", ['orders_count' => 8])
            ->assertStatus(422)->assertJsonValidationErrors('zone');

        $this->putJson("/api/daily-logs/{$old->id}", ['orders_count' => 8, 'zone' => 'العادي'])->assertOk();
        $this->assertSame('العادي', $old->fresh()->zone);

        $this->putJson("/api/daily-logs/{$old->id}", ['zone' => null])->assertStatus(422);
    }

    /**
     * The month grid enters each day against the vehicle held THAT day, so it has to be told which
     * one that was — the employee list only ever names the vehicle he holds today.
     */
    public function test_the_contract_month_names_the_vehicle_he_held_on_each_stretch(): void
    {
        VehicleAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->driver->id, 'vehicle_id' => $this->sedan->id,
            'assigned_date' => '2026-07-01', 'unassigned_date' => '2026-09-04', 'is_active' => false,
        ]);
        VehicleAssignment::create([
            'company_id' => $this->company->id, 'employee_id' => $this->driver->id, 'vehicle_id' => $this->van->id,
            'assigned_date' => '2026-09-05', 'unassigned_date' => null, 'is_active' => true,
        ]);

        $spans = $this->getJson("/api/contracts/{$this->contract->id}/dashboard?year=2026&month=9")
            ->assertOk()
            ->json('vehicle_assignments_by_employee.'.$this->driver->id);

        $this->assertSame([
            ['vehicle_id' => $this->sedan->id, 'vehicle_type_id' => 2, 'plate_number' => 'V-ZONE-SEDAN', 'from' => '2026-07-01', 'to' => '2026-09-04'],
            ['vehicle_id' => $this->van->id, 'vehicle_type_id' => 3, 'plate_number' => 'V-ZONE-VAN', 'from' => '2026-09-05', 'to' => null],
        ], $spans);
    }
}
