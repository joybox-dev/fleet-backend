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
 * A settlement is a receipt for money that physically reached the accountant. Lowering a day's
 * collected cash afterwards left `cash_settled` stranded above `cash_collected`: the books then
 * held cash the driver had never taken in. The imported ledger carries 37.748 KWD of exactly that,
 * on one day whose collection reads 8.300 against a settlement of 46.048.
 *
 * The single-record path was worse still — it subtracted without a floor, so the day's pending cash
 * went negative and the contract's cash card read −11.990 د.ك while the settlement screen, which
 * only counts rows above zero, went on showing the old figure.
 *
 * The owner's ruling is to stop the edit rather than move the money: "يمنع التعديل". A day already
 * in the broken state may still be raised toward its settled figure, so the 37.748 can be repaired.
 */
class SettledCashCannotBeUncollectedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Vehicle $vehicle;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Settled Co',
            'code' => 'settledco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Settled Admin',
            'email' => 'admin@settled.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Settled Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Settled Driver',
            'employee_number' => 'EMP-SET-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'PLATE-SET',
            'make' => 'Honda',
            'status' => 'working',
            'company_id' => $this->company->id,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-SET',
            'name' => 'Settled Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => 26,
            'is_validity_enabled' => false,
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

    /** A day whose cash has been handed over in full. */
    private function settledDay(float $collected = 10.090, float $settled = 10.090): DailyLog
    {
        return DailyLog::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'vehicle_id' => $this->vehicle->id,
            'log_date' => '2026-03-02',
            'driver_status' => 'working',
            'orders_count' => 4,
            'orders_online' => 4,
            'orders_cash' => 0,
            'cash_collected' => $collected,
            'cash_settled' => $settled,
            'cash_pending' => max(0, $collected - $settled),
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function bulkPayload(array $overrides = []): array
    {
        return ['logs' => [array_merge([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'vehicle_id' => $this->vehicle->id,
            'log_date' => '2026-03-02',
            'orders_count' => 4,
            'orders_cash' => 0,
            'cash_collected' => 3.000,
        ], $overrides)]];
    }

    public function test_the_single_record_edit_refuses_to_drop_collected_cash_below_the_settlement(): void
    {
        $log = $this->settledDay();

        $this->putJson("/api/daily-logs/{$log->id}", [
            'orders_count' => 4,
            'orders_online' => 4,
            'orders_cash' => 0,
            'cash_collected' => 3.000,
        ])->assertStatus(422)->assertJsonValidationErrors('cash_collected');

        $log->refresh();
        $this->assertEqualsWithDelta(10.090, (float) $log->cash_collected, 0.0005);
        $this->assertEqualsWithDelta(10.090, (float) $log->cash_settled, 0.0005);
        $this->assertEqualsWithDelta(0.0, (float) $log->cash_pending, 0.0005, 'pending must never be driven below zero');
    }

    public function test_the_contract_grid_reports_the_row_instead_of_writing_it(): void
    {
        $log = $this->settledDay();

        $response = $this->postJson('/api/daily-logs/bulk', $this->bulkPayload())->assertOk();

        $response->assertJsonPath('skipped_count', 1);
        $response->assertJsonPath('skipped.0.reason', 'cash_already_settled');
        $response->assertJsonPath('count', 0);

        $this->assertEqualsWithDelta(10.090, (float) $log->fresh()->cash_collected, 0.0005);
    }

    public function test_cash_above_the_settlement_still_moves_freely(): void
    {
        $log = $this->settledDay(20.000, 10.090);

        $this->putJson("/api/daily-logs/{$log->id}", [
            'orders_count' => 4,
            'orders_online' => 4,
            'orders_cash' => 0,
            'cash_collected' => 12.000,
        ])->assertOk();

        $log->refresh();
        $this->assertEqualsWithDelta(12.000, (float) $log->cash_collected, 0.0005);
        $this->assertEqualsWithDelta(1.910, (float) $log->cash_pending, 0.0005);
    }

    public function test_a_day_already_over_settled_can_still_be_raised_back_toward_its_receipt(): void
    {
        // The shape the client's ledger is already in: 8.300 collected against 46.048 settled.
        $log = $this->settledDay(8.300, 46.048);

        $this->putJson("/api/daily-logs/{$log->id}", [
            'orders_count' => 4,
            'orders_online' => 4,
            'orders_cash' => 0,
            'cash_collected' => 30.000,
        ])->assertOk();

        $this->assertEqualsWithDelta(30.000, (float) $log->fresh()->cash_collected, 0.0005);
    }

    public function test_an_unsettled_day_is_not_touched_by_the_rule(): void
    {
        $log = $this->settledDay(10.090, 0.0);

        $this->putJson("/api/daily-logs/{$log->id}", [
            'orders_count' => 4,
            'orders_online' => 4,
            'orders_cash' => 0,
            'cash_collected' => 3.000,
        ])->assertOk();

        $log->refresh();
        $this->assertEqualsWithDelta(3.000, (float) $log->cash_collected, 0.0005);
        $this->assertEqualsWithDelta(3.000, (float) $log->cash_pending, 0.0005);
    }
}
