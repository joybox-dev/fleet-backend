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
 * The bulk endpoint used to answer "تم حفظ السجلات بنجاح" after silently dropping every row
 * whose date fell outside the driver's contract assignment. A whole month could be cleared in
 * the editor, reported as saved, and left exactly as it was — which is how stale orders kept
 * reappearing. Whatever is not written must be named in the response.
 */
class DailyLogBulkReportingTest extends TestCase
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
            'name' => 'Bulk Logs Co',
            'code' => 'bulklog',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@bulklog.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'C', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Bulk Driver',
            'employee_number' => 'EMP-BL-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-01-01',
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-BL-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-BL',
            'name' => 'Bulk Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'rate_per_order' => 1.000,
        ]);

        // Mirrors the real data that caused this: the assignment starts late in the month while
        // logs already exist for the whole month.
        ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'start_date' => '2026-07-30',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user);
    }

    private function existingLog(string $date, int $orders): DailyLog
    {
        return DailyLog::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'vehicle_id' => $this->vehicle->id,
            'log_date' => $date,
            'driver_status' => 'working',
            'orders_count' => $orders,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function payloadFor(array $dates): array
    {
        return ['logs' => array_map(fn ($d) => [
            'employee_id' => $this->driver->id,
            'contract_id' => (string) $this->contract->id,
            'vehicle_id' => $this->vehicle->id,
            'log_date' => $d,
            'orders_count' => 0,
            'driver_status' => 'unpaid_leave',
        ], $dates)];
    }

    public function test_rows_outside_the_assignment_window_are_reported_not_silently_dropped(): void
    {
        $outside = $this->existingLog('2026-07-15', 12);
        $inside = $this->existingLog('2026-07-30', 9);

        $response = $this->postJson('/api/daily-logs/bulk', $this->payloadFor(['2026-07-15', '2026-07-30']))
            ->assertOk();

        $response->assertJsonPath('count', 1);
        $response->assertJsonPath('skipped_count', 1);
        $response->assertJsonPath('partial', true);
        $response->assertJsonPath('skipped.0.reason', 'not_assigned');
        $this->assertSame(['2026-07-15'], $response->json('skipped_dates'));

        // And the data matches what the response claims.
        $this->assertSame(12, (int) $outside->fresh()->orders_count, 'untouched, as reported');
        $this->assertSame(0, (int) $inside->fresh()->orders_count, 'saved, as reported');
    }

    public function test_a_fully_valid_batch_still_reports_a_clean_success(): void
    {
        $this->existingLog('2026-07-30', 9);

        $response = $this->postJson('/api/daily-logs/bulk', $this->payloadFor(['2026-07-30']))->assertOk();

        $response->assertJsonPath('count', 1);
        $response->assertJsonPath('skipped_count', 0);
        $response->assertJsonPath('partial', false);
        $response->assertJsonPath('message', 'تم حفظ السجلات بنجاح.');
    }

    /**
     * The exact shape of the reported bug: a month cleared in the editor, nothing written.
     */
    public function test_a_batch_where_nothing_can_be_saved_says_so(): void
    {
        $july = [];
        foreach (range(1, 29) as $day) {
            $date = sprintf('2026-07-%02d', $day);
            $this->existingLog($date, 10);
            $july[] = $date;
        }

        $response = $this->postJson('/api/daily-logs/bulk', $this->payloadFor($july))->assertOk();

        $response->assertJsonPath('count', 0);
        $response->assertJsonPath('skipped_count', 29);
        $response->assertJsonPath('partial', true);
        $this->assertStringContainsString('لم يتم حفظ 29', $response->json('message'));

        // 290 orders remain, and the response no longer pretends otherwise.
        $this->assertSame(290, (int) DailyLog::where('employee_id', $this->driver->id)->sum('orders_count'));
    }

    public function test_rows_missing_required_fields_are_reported_too(): void
    {
        $response = $this->postJson('/api/daily-logs/bulk', ['logs' => [
            ['employee_id' => $this->driver->id, 'log_date' => '2026-07-30'], // no contract_id
        ]])->assertOk();

        $response->assertJsonPath('skipped_count', 1);
        $response->assertJsonPath('skipped.0.reason', 'incomplete');
    }
}
