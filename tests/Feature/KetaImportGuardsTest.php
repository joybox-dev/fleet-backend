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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Keeta file is one more writer of a driver's day, and answers to the rules the screens do.
 *
 * It wrote with `updateOrCreate` on the driver and the date alone: a day he worked on another
 * contract was found, moved to Keeta's and overwritten; the cash of a day already collected and
 * handed over was reset to zero — outside the owner's «يمنع التعديل» ruling; and an approved
 * month was written into like any other. It now goes through DailyLogWriter.
 */
class KetaImportGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Vehicle $vehicle;

    private Contract $keeta;

    private Contract $talabat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Keeta Guard Co',
            'code' => 'ketaguard',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Keeta Admin',
            'email' => 'admin@keta.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Platforms', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Courier',
            'employee_number' => 'EMP-KETA-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-KETA-1',
            'make' => 'Honda',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->keeta = $this->contract($client->id, 'CON-KETA', 'Keeta');
        $this->talabat = $this->contract($client->id, 'CON-TALABAT', 'Talabat');

        foreach ([[$this->keeta, '45091'], [$this->talabat, null]] as [$contract, $courierId]) {
            ContractAssignment::create([
                'employee_id' => $this->driver->id,
                'contract_id' => $contract->id,
                'start_date' => '2026-01-01',
                'status' => 'active',
                'courier_id' => $courierId,
                'company_id' => $this->company->id,
            ]);
        }

        VehicleAssignment::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'assigned_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $this->actingAs($this->user);
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

    /** @param  array<string, mixed>  $with */
    private function day(Contract $contract, string $date, array $with = []): DailyLog
    {
        return DailyLog::create($with + [
            'company_id' => $this->company->id,
            'employee_id' => $this->driver->id,
            'contract_id' => $contract->id,
            'vehicle_id' => $this->vehicle->id,
            'log_date' => $date,
            'orders_count' => 0,
            'driver_status' => 'working',
            'created_by' => $this->user->id,
        ]);
    }

    /** @param  array<string, mixed>  $with */
    private function import(string $date, int $orders, array $with = []): TestResponse
    {
        return $this->postJson('/api/keta/confirm', ['rows' => [$with + [
            'row_number' => 2,
            'date' => $date,
            'courier_id' => '45091',
            'shift_valid' => true,
            'online_hours' => 10.5,
            'orders_count' => $orders,
            'ontime_rate' => 96,
            'avg_delivery_time' => 24,
            'employee_id' => $this->driver->id,
            'contract_id' => $this->keeta->id,
        ]]]);
    }

    public function test_a_file_creates_the_day_and_a_second_file_updates_it(): void
    {
        $this->import('2026-04-06', 14)->assertOk()->assertJsonPath('imported', 1)->assertJsonPath('failed', 0);
        $this->import('2026-04-06', 17)->assertOk()->assertJsonPath('imported', 1);

        $days = DailyLog::withoutGlobalScopes()->get();
        $this->assertCount(1, $days);
        $this->assertSame(17, (int) $days[0]->orders_count);
        $this->assertSame(17, (int) $days[0]->orders_online);
        $this->assertSame('working', $days[0]->driver_status);
        $this->assertSame(10.5, (float) $days[0]->online_hours);
    }

    /** The owner's ruling: cash already handed to the accountant is not un-collected by anybody. */
    public function test_importing_again_leaves_collected_and_settled_cash_alone(): void
    {
        $day = $this->day($this->keeta, '2026-04-06', [
            'orders_count' => 9, 'orders_online' => 6, 'orders_cash' => 3,
            'cash_collected' => 21.750, 'cash_settled' => 15.000, 'cash_pending' => 6.750,
        ]);

        $this->import('2026-04-06', 12)->assertOk()->assertJsonPath('imported', 1);

        $day->refresh();
        $this->assertSame(12, (int) $day->orders_count);
        $this->assertSame(3, (int) $day->orders_cash, 'the cash orders he entered are his, not the file\'s');
        $this->assertSame(9, (int) $day->orders_online);
        $this->assertSame(21.75, (float) $day->cash_collected);
        $this->assertSame(15.0, (float) $day->cash_settled);
        $this->assertSame(6.75, (float) $day->cash_pending);
        $this->assertSame($this->user->id, (int) $day->created_by);
    }

    public function test_a_day_worked_on_another_contract_is_not_taken_over(): void
    {
        $other = $this->day($this->talabat, '2026-04-06', [
            'orders_count' => 5, 'orders_online' => 5, 'cash_collected' => 8.5, 'cash_pending' => 8.5,
        ]);

        $this->import('2026-04-06', 11)->assertOk()->assertJsonPath('imported', 1);

        $other->refresh();
        $this->assertSame($this->talabat->id, (int) $other->contract_id, 'it used to be moved to the Keeta contract');
        $this->assertSame(5, (int) $other->orders_count);
        $this->assertSame(8.5, (float) $other->cash_collected);

        $mine = DailyLog::withoutGlobalScopes()->where('contract_id', $this->keeta->id)->sole();
        $this->assertSame(11, (int) $mine->orders_count);
    }

    public function test_a_day_set_aside_as_leave_becomes_a_working_day_when_the_file_shows_deliveries(): void
    {
        $leave = $this->day($this->keeta, '2026-04-06', ['driver_status' => 'unpaid_leave']);
        $rest = $this->day($this->keeta, '2026-04-07', ['driver_status' => 'paid_leave']);

        $this->import('2026-04-06', 6)->assertOk();
        $this->import('2026-04-07', 0)->assertOk();

        $this->assertSame('working', $leave->fresh()->driver_status);
        $this->assertSame('paid_leave', $rest->fresh()->driver_status, 'a day with no deliveries keeps the status it was given');
    }

    public function test_an_approved_month_is_not_written_into(): void
    {
        $day = $this->day($this->keeta, '2026-03-02', ['orders_count' => 4, 'orders_online' => 4]);

        $this->postJson("/api/payroll/contract-sheet/{$this->keeta->id}/approve", ['year' => 2026, 'month' => 3])->assertOk();
        $this->postJson('/api/payroll/consolidated/2026/3/approve')->assertOk();

        $result = $this->import('2026-03-02', 40)->assertOk()->json();

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('اعتماد', $result['errors'][0]);
        $this->assertSame(4, (int) $day->fresh()->orders_count);
    }

    /** It used to reach the database without a vehicle, fail on NOT NULL, and sink the whole batch with it. */
    public function test_a_day_with_no_vehicle_is_named_and_the_rest_of_the_file_is_saved(): void
    {
        VehicleAssignment::withoutGlobalScopes()->update(['assigned_date' => '2026-04-07']);

        $row = fn (string $date, int $n) => [
            'row_number' => $n, 'date' => $date, 'courier_id' => '45091', 'shift_valid' => true, 'online_hours' => 10,
            'orders_count' => 8, 'ontime_rate' => 95, 'avg_delivery_time' => 25,
            'employee_id' => $this->driver->id, 'contract_id' => $this->keeta->id,
        ];

        $result = $this->postJson('/api/keta/confirm', ['rows' => [$row('2026-04-06', 2), $row('2026-04-07', 3)]])->assertOk()->json();

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('لا مركبة', $result['errors'][0]);
        $this->assertSame('2026-04-07', substr((string) DailyLog::withoutGlobalScopes()->sole()->log_date, 0, 10));
    }

    /** The rows travel through the browser between preview and confirm; they are not taken on trust. */
    public function test_a_row_naming_a_contract_he_is_not_on_is_refused(): void
    {
        $stranger = $this->contract($this->keeta->client_id, 'CON-OTHER', 'Not his');

        $result = $this->import('2026-04-06', 9, ['contract_id' => $stranger->id])->assertOk()->json();

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('غير معيّن', $result['errors'][0]);
        $this->assertSame(0, DailyLog::withoutGlobalScopes()->count());
    }
}
