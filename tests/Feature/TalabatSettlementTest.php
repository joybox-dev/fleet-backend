<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\TalabatStatement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ContractRevenueService;
use App\Services\TalabatRevenueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Talabat pays every order twice — for the pickup and for the drop-off — at prices per vehicle that
 * changed mid-September 2026: car 0.500 + 0.550 and bike 0.400 + 0.450 until the 15th, car 0.590 +
 * 0.590 and bike 0.550 + 0.300 from the 16th. Its monthly statement is the figure; until then the
 * orders logged here are priced at their day's rates. What it charges the company as a whole (the
 * cash deficit, contract fees) comes off the transfer, not the revenue (owner, 2026-11-08).
 *
 * September below: A (car, rider 4001) 10 orders a day on the 1st–2nd and the 16th–17th; B (bike,
 * rider 4002 not yet on his assignment, «Robin Preet») 5 a day on the 1st and the 16th.
 * The statement: A 40 deliveries → pickups 20.000 + 22.000 + 6.000 service − 2.500 = 45.500;
 * B 10 → 9.500 + 7.500 = 17.000; rider 4999 nobody's → 30.000. Riders 92.500; COD deficit −50.000,
 * contract fees −35.000; transfer 7.500.
 */
class TalabatSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Contract $contract;

    private Employee $a;

    private Employee $b;

    private Vehicle $car;

    private Vehicle $bike;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');

        $this->company = Company::create(['name' => 'Talabat Co', 'code' => 'tlbco', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true]);
        app()->instance('current_company_id', $this->company->id);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@tlb.test', 'password' => bcrypt('password'), 'role' => 'admin', 'company_id' => $this->company->id]);

        $client = Client::create(['name' => 'Talabat', 'company_id' => $this->company->id]);
        $bikeType = DB::table('vehicle_types')->insertGetId(['name' => 'Motorbike', 'name_ar' => 'دراجة نارية', 'company_id' => $this->company->id, 'created_at' => now(), 'updated_at' => now()]);
        $carType = DB::table('vehicle_types')->insertGetId(['name' => 'Small Car', 'name_ar' => 'سيارة صغيرة', 'company_id' => $this->company->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->car = Vehicle::create(['plate_number' => 'T-1', 'make' => 'Toyota', 'status' => 'working', 'company_id' => $this->company->id, 'vehicle_type_id' => $carType]);
        $this->bike = Vehicle::create(['plate_number' => 'T-2', 'make' => 'Honda', 'status' => 'working', 'company_id' => $this->company->id, 'vehicle_type_id' => $bikeType]);

        // The contract's own price list (a flat 500) is what months before Talabat's statement read.
        $this->contract = Contract::create([
            'client_id' => $client->id, 'contract_number' => 'CON-TLB', 'name' => 'طلبات',
            'payment_type' => 'fixed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed', 'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id, 'currency' => 'KWD', 'default_required_work_days' => 26,
            'client_pricing_rules' => [(string) $carType => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_pricing_rules' => [(string) $carType => ['payment_method' => 'fixed', 'fixed_amount' => 300, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);

        $this->a = $this->driver('Ali Khan', '4001');
        $this->b = $this->driver('Robinpreet Singh', null);
        foreach (['2026-09-01', '2026-09-02', '2026-09-16', '2026-09-17'] as $day) {
            $this->log($this->a, $this->car, $day, 10);
        }
        foreach (['2026-09-01', '2026-09-16'] as $day) {
            $this->log($this->b, $this->bike, $day, 5);
        }
        $this->log($this->a, $this->car, '2026-08-10', 10);
        TalabatRevenueService::forget();
    }

    protected function tearDown(): void
    {
        TalabatRevenueService::forget();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_until_the_statement_each_order_is_a_pickup_and_a_drop_off_at_its_days_rates(): void
    {
        $this->contract->forceFill(['talabat_settlement_from' => '2026-09-01'])->saveQuietly();

        $month = TalabatRevenueService::forMonth($this->contract->fresh(), 2026, 9);
        $this->assertTrue($month['estimated']);
        // A: 20 × 1.050 + 20 × 1.180 = 44.600; B: 5 × 0.850 + 5 × 0.850 = 8.500.
        $this->assertEqualsWithDelta(53.1, $month['totals']['revenue'], 0.0005);
        $this->assertEqualsWithDelta(44.6, $month['drivers'][$this->a->id]['revenue'], 0.0005);
        $this->assertEqualsWithDelta(8.5, $month['drivers'][$this->b->id]['revenue'], 0.0005);
        $this->assertCount(4, $month['periods'], 'two periods × two vehicles');

        // Every report that prices the contract-month reads the same figure; August keeps its 500.
        $logs = DailyLog::where('contract_id', $this->contract->id)->whereBetween('log_date', ['2026-09-01', '2026-09-30'])->with('vehicle')->get();
        $this->assertEqualsWithDelta(53.1, ContractRevenueService::forContractMonth($this->contract->fresh(), $logs)['revenue'], 0.0005);
        $august = DailyLog::where('contract_id', $this->contract->id)->whereBetween('log_date', ['2026-08-01', '2026-08-31'])->with('vehicle')->get();
        $this->assertEqualsWithDelta(500.0, ContractRevenueService::forContractMonth($this->contract->fresh(), $august)['revenue'], 0.0005);

        // A day of the month is its own orders at its own rates.
        $this->assertEqualsWithDelta(11.8, TalabatRevenueService::dayRevenue($this->contract->fresh(), 2026, 9, $logs->where('employee_id', $this->a->id)->filter(fn ($l) => substr((string) $l->log_date, 0, 10) === '2026-09-16')), 0.0005);
    }

    public function test_rates_are_set_on_the_contract_form_and_the_old_price_list_is_not_asked_for(): void
    {
        $this->actingAs($this->admin)->putJson("/api/contracts/{$this->contract->id}", [
            'talabat_settlement_from' => '2026-09-14',
            'talabat_rates' => [['from' => '2026-09-01', 'car_pickup' => 0.6, 'car_dropoff' => 0.6, 'bike_pickup' => 0.5, 'bike_dropoff' => 0.4]],
            'client_pricing_rules' => ['9' => ['payment_method' => 'fixed']],
        ])->assertOk();

        $contract = $this->contract->fresh();
        $this->assertSame('2026-09-01', $contract->talabat_settlement_from->toDateString());
        // 40 × 1.200 + 10 × 0.900.
        $this->assertEqualsWithDelta(57.0, TalabatRevenueService::forMonth($contract, 2026, 9)['totals']['revenue'], 0.0005);
    }

    public function test_the_statement_is_the_month_and_the_company_lines_come_off_the_transfer_only(): void
    {
        $preview = $this->actingAs($this->admin)
            ->post("/api/contracts/{$this->contract->id}/talabat/statement/preview", ['file' => $this->statement()], ['Accept' => 'application/json'])
            ->assertOk()->json();

        $this->assertSame([2026, 9, 'total'], [$preview['year'], $preview['month'], $preview['shape']]);
        $this->assertEqualsWithDelta(92.5, $preview['totals']['riders_total'], 0.0005);
        $this->assertEqualsWithDelta(-85.0, $preview['totals']['company_total'], 0.0005);
        $this->assertEqualsWithDelta(7.5, $preview['totals']['net_payment'], 0.0005);
        $this->assertTrue($preview['checks'][0]['ok'], $preview['checks'][0]['text']);
        $byRider = collect($preview['riders'])->keyBy('rider_id');
        $this->assertSame($this->a->id, $byRider['4001']['employee_id']);
        $this->assertNull($byRider['4002']['employee_id']);
        // «ROBINPREET SINGH DARSHAN SINGH 1A - B - TC» resembles our «Robinpreet Singh».
        $this->assertSame($this->b->id, $byRider['4002']['suggestions'][0]['id']);
        $this->assertEqualsWithDelta(-2.5, $byRider['4001']['adjustments'], 0.0005);

        $this->actingAs($this->admin)->postJson("/api/contracts/{$this->contract->id}/talabat/statement", [
            'token' => $preview['token'],
            'links' => ['4002' => $this->b->id],
        ])->assertCreated()->assertJsonPath('linked', 1)->assertJsonPath('settlement_from', '2026-09');

        // The link is remembered on his assignment for next month.
        $this->assertSame('4002', ContractAssignment::where('employee_id', $this->b->id)->value('courier_id'));

        $contract = $this->contract->fresh();
        $month = TalabatRevenueService::forMonth($contract, 2026, 9);
        $this->assertFalse($month['estimated']);
        $this->assertEqualsWithDelta(92.5, $month['totals']['revenue'], 0.0005);
        $this->assertEqualsWithDelta(45.5, $month['drivers'][$this->a->id]['revenue'], 0.0005);
        $this->assertEqualsWithDelta(17.0, $month['drivers'][$this->b->id]['revenue'], 0.0005);
        $this->assertCount(1, $month['unmatched']);
        $this->assertEqualsWithDelta(7.5, $month['statement']['net_payment'], 0.0005);

        // The client's account: the bill is the revenue, the cash deficit and the fees come off it.
        $lines = collect($this->actingAs($this->admin)->getJson("/api/statements/client/{$contract->client_id}?from=2026-09-01&to=2026-09-30")->assertOk()->json('lines'));
        $this->assertEqualsWithDelta(92.5, (float) $lines->firstWhere('kind', 'invoice')['debit'], 0.0005);
        $this->assertEqualsWithDelta(85.0, $lines->where('kind', 'adjustment')->sum(fn ($l) => (float) $l['credit']), 0.0005);

        // Deleting the statement returns the month to its estimate.
        $this->actingAs($this->admin)->deleteJson("/api/contracts/{$this->contract->id}/talabat/statement/".TalabatStatement::value('id'))->assertOk();
        $this->assertTrue(TalabatRevenueService::forMonth($contract, 2026, 9)['estimated']);
    }

    public function test_a_file_that_is_not_talabats_or_spans_two_months_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post("/api/contracts/{$this->contract->id}/talabat/statement/preview", ['file' => UploadedFile::fake()->createWithContent('x.csv', "a,b\n1,2\n")], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $csv = "Month,Year,Rider ID,Rider Name,Vehicle,Pickup Payment,Dropoff Payment,Net Cost,Net Payment\n9,2026,1,A,Car,1,1,2,2\n10,2026,2,B,Car,1,1,2,2\n";
        $this->actingAs($this->admin)
            ->post("/api/contracts/{$this->contract->id}/talabat/statement/preview", ['file' => UploadedFile::fake()->createWithContent('two.csv', $csv)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    private function statement(): UploadedFile
    {
        $csv = implode("\n", [
            'Month,Year,Rider ID,Rider Name,3PL Legal Name,Vehicle,Evaluated Hours,Total Completed Deliveries,Pickups Count,Pickup Cancellations,Dropoffs Count,Dropoff Cancellations,Pickup Payment,Dropoff Payment,Service-Level Based Achievement total Payment,Operator Log in & use to the Rider (operator) App Deductions,Rider Deduction,Net Cost,COD Deficit,Contract fees,Net Payment',
            '9,2026,4001,ALI KHAN 1A - TC,The Courier - (TC),Car,80.5,40,40,0,40,0,20,22,6,-2.5,0,45.5,0,0,45.5',
            '9,2026,4002,ROBINPREET SINGH DARSHAN SINGH 1A - B - TC,The Courier - (TC),Motorbike,20,10,10,0,10,0,9.5,7.5,0,0,0,17,0,0,17',
            '9,2026,4999,SOMEONE ELSE 1A - TC,The Courier - (TC),Car,30,25,25,0,25,0,14.75,14.75,0.5,0,0,30,0,0,30',
            '9,2026,,,The Courier - (TC),,0,0,0,0,0,0,0,0,0,0,0,0,-50,-35,-85',
            ',,,,,,,,,,,,,,,,,,,,',
        ])."\n";

        return UploadedFile::fake()->createWithContent('talabat-sept.csv', $csv);
    }

    private function driver(string $name, ?string $riderId): Employee
    {
        $employee = Employee::create([
            'name' => $name, 'company_id' => $this->company->id, 'status' => 'active',
            'role_category' => 'driver', 'date_of_joining' => '2026-01-01', 'actual_salary' => 0, 'official_salary' => 100,
        ]);
        ContractAssignment::create([
            'employee_id' => $employee->id, 'contract_id' => $this->contract->id, 'courier_id' => $riderId,
            'start_date' => '2026-01-01', 'status' => 'active', 'company_id' => $this->company->id,
        ]);

        return $employee;
    }

    private function log(Employee $employee, Vehicle $vehicle, string $day, int $orders): void
    {
        DailyLog::create([
            'employee_id' => $employee->id, 'contract_id' => $this->contract->id, 'vehicle_id' => $vehicle->id,
            'log_date' => $day, 'driver_status' => 'working', 'orders_count' => $orders,
            'company_id' => $this->company->id, 'created_by' => $this->admin->id,
        ]);
    }
}
