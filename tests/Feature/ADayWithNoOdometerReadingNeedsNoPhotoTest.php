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
 * A day that carries a closing odometer reading has to carry a photo of it. The check asked
 * `filled('odometer_end')`, and a zero is filled — but zero is exactly what the edit form sends for
 * "nobody read the odometer". Every daily log in the imported fleet has a null reading, so opening
 * one from the driver's profile and saving it came back 422 asking for a photo of a reading that
 * does not exist. The day's cash could not be corrected at all.
 *
 * The rule is now what it always meant: a reading is a number above zero.
 */
class ADayWithNoOdometerReadingNeedsNoPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Contract $contract;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Odometer Co',
            'code' => 'odoco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Odometer Admin',
            'email' => 'admin@odo.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Odometer Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Odometer Driver',
            'employee_number' => 'EMP-ODO-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'PLATE-ODO',
            'make' => 'Honda',
            'status' => 'working',
            'company_id' => $this->company->id,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-ODO',
            'name' => 'Odometer Contract',
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

    private function day(): DailyLog
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
            'cash_collected' => 10.090,
            'cash_settled' => 0,
            'cash_pending' => 10.090,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_the_days_cash_can_be_corrected_when_no_odometer_was_read(): void
    {
        $log = $this->day();

        $this->putJson("/api/daily-logs/{$log->id}", [
            'orders_count' => 4,
            'orders_online' => 4,
            'orders_cash' => 0,
            'cash_collected' => 3.000,
            'odometer_start' => 0,
            'odometer_end' => 0,
        ])->assertOk();

        $this->assertEqualsWithDelta(3.000, (float) $log->fresh()->cash_collected, 0.0005);
    }

    public function test_a_real_closing_reading_still_has_to_carry_its_photo(): void
    {
        $log = $this->day();

        $this->putJson("/api/daily-logs/{$log->id}", [
            'orders_count' => 4,
            'orders_online' => 4,
            'orders_cash' => 0,
            'odometer_start' => 12000,
            'odometer_end' => 12140,
        ])->assertStatus(422)->assertJsonValidationErrors('odometer_photo_path');
    }

    public function test_a_reading_with_its_photo_is_accepted(): void
    {
        $log = $this->day();

        $this->putJson("/api/daily-logs/{$log->id}", [
            'orders_count' => 4,
            'orders_online' => 4,
            'orders_cash' => 0,
            'odometer_start' => 12000,
            'odometer_end' => 12140,
            'odometer_photo_path' => 'odometers/2026-03-02.jpg',
        ])->assertOk();

        $this->assertSame(12140, (int) $log->fresh()->odometer_end);
    }
}
