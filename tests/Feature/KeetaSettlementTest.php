<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\KeetaInvoice;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ContractRevenueService;
use App\Services\KeetaRevenueService;
use App\Services\KeetaWorkbookReader;
use App\Services\OwnerPulseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Keeta settles a month by its own statement: a price per order that only Keeta can compute, an
 * incentive per rider that only Keeta ranks, and its own deductions. The statement is imported and
 * becomes the month's revenue; until it arrives the month is estimated from the last statement and
 * from Keeta's «expected level» export.
 *
 * The statement below: riders 111 (ours, «A»), 222 (ours, «B», invalid) and 999 (no driver of ours).
 * Orders 600 + 400 + 250 = 1250 priced 1000.000 (0.800 an order); A's incentive 270; tips 5.000;
 * food damage −4.250 and a quality deduction −2.000, both A's. Invoice 1263.750, payable 1268.750.
 */
class KeetaSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Contract $contract;

    private Vehicle $vehicle;

    private Employee $a;

    private Employee $b;

    private Employee $c;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 10:00:00');

        $this->company = Company::create([
            'name' => 'Keeta Co', 'code' => 'keetaco', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@keeta.test', 'password' => bcrypt('password'),
            'role' => 'admin', 'company_id' => $this->company->id,
        ]);

        $client = Client::create(['name' => 'Keeta', 'company_id' => $this->company->id]);
        $this->vehicle = Vehicle::create([
            'plate_number' => 'K-1', 'make' => 'Toyota', 'status' => 'working', 'company_id' => $this->company->id, 'vehicle_type_id' => 1,
        ]);
        // The contract's own price list: a flat 500 a month. Months before Keeta settles by
        // statement must keep reading it.
        $this->contract = Contract::create([
            'client_id' => $client->id, 'contract_number' => 'CON-KEETA', 'name' => 'كيتا',
            'payment_type' => 'fixed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed', 'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id, 'currency' => 'KWD', 'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 300, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);

        $this->a = $this->driver('Driver A', 'EMP-A', '111');
        $this->b = $this->driver('Driver B', 'EMP-B', '222');
        $this->c = $this->driver('Driver C', 'EMP-C', null);

        $this->logs($this->a, '2026-08', 20, 30);
        $this->logs($this->b, '2026-08', 20, 20);
        $this->logs($this->c, '2026-08', 1, 7);
        $this->logs($this->a, '2026-09', 5, 30);
        $this->logs($this->c, '2026-09', 2, 10);
        $this->logs($this->a, '2026-07', 1, 10);

        KeetaRevenueService::forget();
    }

    protected function tearDown(): void
    {
        KeetaRevenueService::forget();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_statement_is_read_in_arabic_and_its_riders_matched_by_courier_id(): void
    {
        $preview = $this->actingAs($this->admin)
            ->post("/api/contracts/{$this->contract->id}/keeta/statement/preview", ['file' => $this->statementFile('ar')], ['Accept' => 'application/json'])
            ->assertStatus(200)->json();

        $this->assertSame(2026, $preview['year']);
        $this->assertSame(8, $preview['month']);
        $this->assertSame('2026-08', $preview['settlement_from_after']);
        $this->assertEqualsWithDelta(1263.75, $preview['totals']['invoice_amount'], 0.0005);
        $this->assertEqualsWithDelta(1268.75, $preview['totals']['total_payable'], 0.0005);
        $this->assertSame(1250, $preview['totals']['orders_keeta']);
        $this->assertSame(3, $preview['totals']['riders']);
        $this->assertSame(2, $preview['totals']['valid_riders']);

        $byCourier = collect($preview['riders'])->keyBy('courier_id');
        $this->assertSame($this->a->id, $byCourier['111']['employee_id']);
        $this->assertSame($this->b->id, $byCourier['222']['employee_id']);
        $this->assertNull($byCourier['999']['employee_id']);
        $this->assertFalse($byCourier['222']['is_valid']);
        $this->assertEqualsWithDelta(743.75, $byCourier['111']['revenue'], 0.0005);

        // Order lines are counted and dropped; the incentive, the penalty and the deduction stay.
        $this->assertCount(3, $preview['lines']);
        $penalty = collect($preview['lines'])->firstWhere('amount', -4.25);
        $this->assertSame('أصناف خاطئة', $penalty['violation_type']);
        $this->assertSame($this->a->id, $penalty['employee_id']);

        $checks = collect($preview['checks']);
        $this->assertTrue($checks[0]['ok'], $checks[0]['text']);
        $this->assertTrue($checks[1]['ok'], $checks[1]['text']);
        $this->assertFalse($checks[2]['ok'], 'one rider has no driver of ours');
        $this->assertStringContainsString('Driver C', $checks[3]['text']);
    }

    public function test_a_confirmed_statement_is_the_months_revenue_to_the_fils(): void
    {
        $this->importStatement('ar');

        $contract = $this->contract->fresh();
        $this->assertSame('2026-08-01', $contract->keeta_settlement_from->toDateString());

        $august = $this->monthLogs('2026-08');
        $billed = ContractRevenueService::forContractMonth($contract, $august);
        $this->assertEqualsWithDelta(1263.75, $billed['revenue'], 0.0005);
        $this->assertFalse($billed['keeta']['estimated']);

        $split = ContractRevenueService::forContractDrivers($contract, $august);
        $this->assertEqualsWithDelta(743.75, $split['drivers'][$this->a->id]['revenue'], 0.0005, 'his tips are his, not revenue');
        $this->assertEqualsWithDelta(320.0, $split['drivers'][$this->b->id]['revenue'], 0.0005);
        $this->assertEqualsWithDelta(0.0, $split['drivers'][$this->c->id]['revenue'], 0.0005, 'no row on the statement');
        $this->assertEqualsWithDelta(1263.75, $split['contract']['revenue'], 0.0005, 'the unmatched rider counts in the contract');

        $month = $this->actingAs($this->admin)->getJson("/api/contracts/{$this->contract->id}/keeta?year=2026&month=8")->assertStatus(200)->json('result');
        $this->assertFalse($month['estimated']);
        $this->assertSame(600, $month['drivers'][$this->a->id]['orders_keeta']);
        $this->assertSame(600, $month['drivers'][$this->a->id]['orders_ours']);
        $this->assertCount(1, $month['unmatched']);
        $this->assertEqualsWithDelta(200.0, $month['totals']['unmatched_revenue'], 0.0005);
        $this->assertEqualsWithDelta(-6.25, $month['totals']['adjustments'], 0.0005);

        $dashboard = $this->actingAs($this->admin)->getJson("/api/contracts/{$this->contract->id}/dashboard?year=2026&month=8")->assertStatus(200)->json();
        $this->assertEqualsWithDelta(1263.75, $dashboard['financials']['actual']['revenue'], 0.0005);
        $this->assertTrue($dashboard['keeta']['applies']);
        $this->assertFalse($dashboard['keeta']['estimated']);
    }

    public function test_the_english_export_reads_to_the_same_figures(): void
    {
        $preview = $this->actingAs($this->admin)
            ->post("/api/contracts/{$this->contract->id}/keeta/statement/preview", ['file' => $this->statementFile('en')], ['Accept' => 'application/json'])
            ->assertStatus(200)->json();

        $this->assertSame(7, $preview['month']);
        $this->assertEqualsWithDelta(1263.75, $preview['totals']['invoice_amount'], 0.0005);
        $this->assertEqualsWithDelta(-4.25, $preview['totals']['food_compensation'], 0.0005);
        $byCourier = collect($preview['riders'])->keyBy('courier_id');
        $this->assertTrue($byCourier['111']['is_valid'], '«valid» followed by a line break is still valid');
        $this->assertFalse($byCourier['222']['is_valid']);
        $this->assertSame('Rider A', $byCourier['111']['name'], 'stray spaces trimmed');
    }

    public function test_a_month_without_its_statement_is_estimated_from_the_last_one(): void
    {
        $this->importStatement('ar');

        $september = KeetaRevenueService::forMonth($this->contract->fresh(), 2026, 9);

        $this->assertTrue($september['estimated']);
        $this->assertSame('last_invoice', $september['basis']['price_source']);
        $this->assertEqualsWithDelta(0.8, $september['basis']['price_per_order'], 0.00005);
        // A: 150 orders × 0.800 + the 270 he was paid in August. C: 20 × 0.800, no incentive on record.
        $this->assertEqualsWithDelta(390.0, $september['drivers'][$this->a->id]['revenue'], 0.0005);
        $this->assertEqualsWithDelta(16.0, $september['drivers'][$this->c->id]['revenue'], 0.0005);
        $this->assertEqualsWithDelta(406.0, $september['totals']['revenue'], 0.0005);

        $billed = ContractRevenueService::forContractMonth($this->contract->fresh(), $this->monthLogs('2026-09'));
        $this->assertTrue($billed['keeta']['estimated']);
        $this->assertEqualsWithDelta(406.0, $billed['revenue'], 0.0005);
    }

    public function test_a_statement_is_listed_the_way_its_invoice_adds_up(): void
    {
        $this->importStatement('ar');

        $statement = $this->actingAs($this->admin)->getJson("/api/contracts/{$this->contract->id}/keeta?year=2026&month=9")
            ->assertStatus(200)->json('statements.0');

        $this->assertSame(8, $statement['month']);
        $this->assertSame(1250, $statement['orders_count']);
        $this->assertEqualsWithDelta(1000.0, $statement['order_pricing'], 0.0005);
        $this->assertEqualsWithDelta(0.8, $statement['average_order_price'], 0.00005);
        $this->assertEqualsWithDelta(270.0, $statement['incentives'], 0.0005);
        $this->assertEqualsWithDelta(-6.25, $statement['adjustments'], 0.0005);
        $this->assertEqualsWithDelta(5.0, $statement['tips'], 0.0005);
        $this->assertEqualsWithDelta(1263.75, $statement['invoice_amount'], 0.0005);
        $this->assertEqualsWithDelta(
            $statement['invoice_amount'],
            $statement['order_pricing'] + $statement['incentives'] + $statement['adjustments'],
            0.0005,
            'orders + incentives + adjustments = the invoice; the tips stay out',
        );

        // The report line is Keeta's own sum over Keeta's count, not our orders × an average.
        $billed = ContractRevenueService::forContractMonth($this->contract->fresh(), $this->monthLogs('2026-08'));
        $this->assertSame(1250, $billed['details'][0]['orders']);
        $this->assertStringNotContainsString('×', $billed['details'][0]['formula']);
    }

    public function test_an_estimate_is_its_orders_times_the_price_to_the_fils(): void
    {
        $this->importStatement('ar');
        // 1004.000 over 1250 orders: 0.8032 an order, so a driver's 2 orders are 1.6064 — each
        // share rounds, the month must not.
        KeetaInvoice::first()->update(['order_pricing' => 1004.0]);
        $this->logs($this->a, '2026-10', 1, 2);
        $this->logs($this->c, '2026-10', 1, 2);
        KeetaRevenueService::forget();

        $october = KeetaRevenueService::forMonth($this->contract->fresh(), 2026, 10);

        $this->assertEqualsWithDelta(0.8032, $october['totals']['price_per_order'], 0.00005);
        $this->assertEqualsWithDelta(3.213, $october['totals']['order_revenue'], 0.0005, '4 × 0.8032 = 3.2128');
        $this->assertEqualsWithDelta(3.213, array_sum(array_column($october['drivers'], 'order_revenue')), 0.0005, 'the drivers add up to the month');
        foreach ($october['drivers'] as $row) {
            $this->assertEqualsWithDelta(1.6064, $row['order_revenue'], 0.0011);
        }
        $this->assertEqualsWithDelta(273.213, $october['totals']['revenue'], 0.0005, 'plus the 270 A was paid in August');

        $billed = ContractRevenueService::forContractMonth($this->contract->fresh(), $this->monthLogs('2026-10'));
        $this->assertSame('4 طلب × 0.8032 د.ك = 3.213 د.ك', $billed['details'][0]['formula']);
    }

    public function test_keetas_expected_level_export_sets_the_estimated_incentive(): void
    {
        $this->importStatement('ar');

        $preview = $this->actingAs($this->admin)
            ->post("/api/contracts/{$this->contract->id}/keeta/levels/preview", ['file' => $this->levelsFile(), 'year' => 2026, 'month' => 9, 'taken_on' => '2026-09-19'], ['Accept' => 'application/json'])
            ->assertStatus(200)->json();
        $this->assertSame(3, $preview['riders']);
        $this->assertSame(2, $preview['matched']);
        $this->assertEqualsWithDelta(170.0, $preview['reward_matched'], 0.0005);
        $this->assertEqualsWithDelta(370.0, $preview['reward_unmatched'], 0.0005);

        $this->actingAs($this->admin)->postJson("/api/contracts/{$this->contract->id}/keeta/levels", ['token' => $preview['token']])->assertStatus(201);
        KeetaRevenueService::forget();

        $listed = $this->actingAs($this->admin)->getJson("/api/contracts/{$this->contract->id}/keeta?year=2026&month=9")
            ->assertStatus(200)->json('level_snapshots.0');
        $this->assertSame('2026-09-19', $listed['taken_on'], 'the screen shows a plain date, not a timestamp');

        $september = KeetaRevenueService::forMonth($this->contract->fresh(), 2026, 9);
        $this->assertSame('levels', $september['basis']['incentive_source']);
        $this->assertSame('2026-09-19', $september['basis']['levels_taken_on']);
        $this->assertSame('B', $september['drivers'][$this->a->id]['level']);
        // A: 120 for his orders + 170 for tier B. The rider ranked S is nobody of ours and adds nothing.
        $this->assertEqualsWithDelta(290.0, $september['drivers'][$this->a->id]['revenue'], 0.0005);
        $this->assertEqualsWithDelta(306.0, $september['totals']['revenue'], 0.0005);
        $this->assertSame('777', $september['unmatched'][0]['courier_id']);
    }

    public function test_months_before_the_first_statement_keep_the_contracts_own_pricing(): void
    {
        $july = $this->monthLogs('2026-07');
        $before = ContractRevenueService::forContractMonth($this->contract->fresh(), $july)['revenue'];

        $this->importStatement('ar');

        $after = ContractRevenueService::forContractMonth($this->contract->fresh(), $july);
        $this->assertEqualsWithDelta(500.0, $before, 0.0005);
        $this->assertEqualsWithDelta($before, $after['revenue'], 0.0005);
        $this->assertArrayNotHasKey('keeta', $after);
    }

    public function test_a_day_of_a_settled_month_is_its_orders_at_the_months_price(): void
    {
        $this->importStatement('ar');

        $day = DailyLog::where('employee_id', $this->a->id)->whereDate('log_date', '2026-08-03')->with('vehicle')->get();
        $billed = ContractRevenueService::forContractMonth($this->contract->fresh(), $day, 1, 0.0);

        $this->assertEqualsWithDelta(24.0, $billed['revenue'], 0.0005, '30 orders × 0.800, no incentive on a day');
    }

    public function test_importing_a_month_again_replaces_its_statement(): void
    {
        $this->importStatement('ar');
        $this->importStatement('ar', 2.0);

        $this->assertSame(1, KeetaInvoice::where('contract_id', $this->contract->id)->count());
        $this->assertEqualsWithDelta(2527.5, (float) KeetaInvoice::first()->invoice_amount, 0.0005);
    }

    public function test_contract_viewers_read_the_month_and_only_editors_import(): void
    {
        Role::create(['name' => 'عقود عرض', 'company_id' => $this->company->id, 'allowed_modules' => ['contracts.view']]);
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@keeta.test', 'password' => bcrypt('password'),
            'role' => 'عقود عرض', 'company_id' => $this->company->id,
        ]);

        $this->actingAs($viewer)->getJson("/api/contracts/{$this->contract->id}/keeta?year=2026&month=8")->assertStatus(200);
        $this->actingAs($viewer)
            ->post("/api/contracts/{$this->contract->id}/keeta/statement/preview", ['file' => $this->statementFile('ar')], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_a_file_that_is_not_a_keeta_statement_is_refused_with_a_reason(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Name', 'Amount'], ['x', 1]]);

        $this->actingAs($this->admin)
            ->post("/api/contracts/{$this->contract->id}/keeta/statement/preview", ['file' => $this->upload($book, 'other.xlsx')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', fn ($message) => str_contains($message, 'ليس كشف كيتا'));

        $this->actingAs($this->admin)->postJson("/api/contracts/{$this->contract->id}/keeta/statement", ['token' => 'nothing'])->assertStatus(422);
    }

    public function test_the_owner_dashboard_names_an_estimated_keeta_month(): void
    {
        $this->importStatement('ar');

        $pulse = OwnerPulseService::forDay($this->company->id, Carbon::parse('2026-09-05'));

        $this->assertSame($this->contract->id, $pulse['revenue']['estimated_parts'][0]['contract_id'] ?? null);
    }

    public function test_the_contract_form_switches_the_client_to_keetas_statement_and_back(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/contracts/{$this->contract->id}", ['keeta_settlement_from' => '2026-08-15'])
            ->assertStatus(200);
        KeetaRevenueService::forget();

        $contract = $this->contract->fresh();
        $this->assertSame('2026-08-01', $contract->keeta_settlement_from->toDateString(), 'always the first of its month');

        // No statement yet and none before it: August is its orders at Keeta's base fee.
        $august = ContractRevenueService::forContractMonth($contract, $this->monthLogs('2026-08'));
        $this->assertTrue($august['keeta']['estimated']);
        $this->assertEqualsWithDelta(1007 * 0.650, $august['revenue'], 0.0005);
        $this->assertEqualsWithDelta(500.0, ContractRevenueService::forContractMonth($contract, $this->monthLogs('2026-07'))['revenue'], 0.0005);

        $inventory = $this->actingAs($this->admin)->getJson('/api/reports/inventory')->assertStatus(200)->json('contracts');
        $this->assertSame(['keeta'], collect($inventory)->firstWhere('id', $this->contract->id)['client_payment_methods']);

        // Keeta prices its own orders: a vehicle type added for its drivers needs no client price.
        $rules = $this->contract->fresh()->client_pricing_rules;
        $rules['2'] = ['vehicle_type_id' => '2', 'payment_method' => 'fixed', 'fixed_amount' => ''];
        $this->actingAs($this->admin)
            ->putJson("/api/contracts/{$this->contract->id}", ['client_pricing_rules' => $rules, 'keeta_settlement_from' => '2026-08-01'])
            ->assertStatus(200);
        $this->actingAs($this->admin)
            ->putJson("/api/contracts/{$this->contract->id}", ['client_pricing_rules' => $rules, 'keeta_settlement_from' => null])
            ->assertStatus(422);

        // Editing an unrelated field leaves it alone; sending null returns the contract to its price list.
        $this->actingAs($this->admin)->putJson("/api/contracts/{$this->contract->id}", ['notes' => 'x'])->assertStatus(200);
        $this->assertNotNull($this->contract->fresh()->keeta_settlement_from);

        $this->actingAs($this->admin)->putJson("/api/contracts/{$this->contract->id}", ['keeta_settlement_from' => null])->assertStatus(200);
        KeetaRevenueService::forget();
        $this->assertNull($this->contract->fresh()->keeta_settlement_from);
        $this->assertEqualsWithDelta(500.0, ContractRevenueService::forContractMonth($this->contract->fresh(), $this->monthLogs('2026-08'))['revenue'], 0.0005);
    }

    public function test_a_new_contract_can_be_created_billed_by_keetas_statement(): void
    {
        // What the form sends for a new Keeta contract: the vehicle type the drivers work on with no
        // client price at all, the driver's pay, and the month Keeta's statement takes over.
        $payload = [
            'client_id' => Client::where('name', 'Keeta')->value('id'),
            'contract_number' => 'CON-KEETA-2',
            'name' => 'كيتا ٢',
            'status' => 'active',
            'currency' => 'KWD',
            'start_date' => '2026-10-01',
            'default_required_work_days' => 26,
            'default_absence_divisor' => 26,
            'client_pricing_rules' => ['2' => ['vehicle_type_id' => '2', 'payment_method' => 'fixed', 'fixed_amount' => '']],
            'driver_pricing_rules' => ['2' => ['vehicle_type_id' => '2', 'payment_method' => 'fixed', 'fixed_amount' => 300, 'fixed_target' => 400]],
            'is_validity_enabled' => false,
            'keeta_settlement_from' => '2026-10-01',
        ];

        $this->actingAs($this->admin)->postJson('/api/contracts', $payload)->assertStatus(201);

        $contract = Contract::where('contract_number', 'CON-KEETA-2')->firstOrFail();
        $this->assertSame('2026-10-01', $contract->keeta_settlement_from->toDateString());
        $this->assertSame('fixed', $contract->client_payment_method, 'inferred from the vehicle type; the form never sends it');

        // Without the Keeta tick the same contract is refused: its client price is empty.
        $payload['contract_number'] = 'CON-KEETA-3';
        unset($payload['keeta_settlement_from']);
        $this->actingAs($this->admin)->postJson('/api/contracts', $payload)->assertStatus(422);
    }

    public function test_money_and_months_are_read_in_every_shape_keeta_writes_them(): void
    {
        $this->assertEqualsWithDelta(15745.666, KeetaWorkbookReader::number('KWD 15,745.666'), 0.0005);
        $this->assertEqualsWithDelta(14757.175, KeetaWorkbookReader::number("\u{200F}14,757.175 د.ك.\u{200F}"), 0.0005);
        $this->assertEqualsWithDelta(-34.035, KeetaWorkbookReader::number('- 34.035'), 0.0005);
        $this->assertEqualsWithDelta(10086.513, KeetaWorkbookReader::number('10,086.513'), 0.0005);
        $this->assertSame(0.0, KeetaWorkbookReader::number(null));

        $this->assertSame([2026, 8], KeetaWorkbookReader::monthOf('أغسطس 2026'));
        $this->assertSame([2026, 7], KeetaWorkbookReader::monthOf('Jul 2026'));
        $this->assertSame([2026, 5], KeetaWorkbookReader::monthOf('مايو 2026'));
        $this->assertSame([2026, 9], KeetaWorkbookReader::monthOf('September 2026'));
        $this->assertSame([2026, 6], KeetaWorkbookReader::monthOf('2026-06'));
    }

    // ─── helpers ───────────────────────────────────────────────────────────────

    private function driver(string $name, string $number, ?string $courierId): Employee
    {
        $employee = Employee::create([
            'name' => $name, 'employee_number' => $number, 'company_id' => $this->company->id, 'status' => 'active',
            'role_category' => 'driver', 'date_of_joining' => '2026-01-01', 'actual_salary' => 0, 'official_salary' => 100,
        ]);
        ContractAssignment::create([
            'employee_id' => $employee->id, 'contract_id' => $this->contract->id, 'courier_id' => $courierId,
            'start_date' => '2026-01-01', 'status' => 'active', 'company_id' => $this->company->id,
        ]);

        return $employee;
    }

    private function logs(Employee $employee, string $month, int $days, int $orders): void
    {
        foreach (range(1, $days) as $day) {
            DailyLog::create([
                'employee_id' => $employee->id, 'contract_id' => $this->contract->id, 'vehicle_id' => $this->vehicle->id,
                'log_date' => sprintf('%s-%02d', $month, $day), 'driver_status' => 'working', 'orders_count' => $orders,
                'company_id' => $this->company->id, 'created_by' => $this->admin->id,
            ]);
        }
    }

    private function monthLogs(string $month): Collection
    {
        $start = Carbon::parse($month.'-01');

        return DailyLog::where('contract_id', $this->contract->id)
            ->whereBetween('log_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->with('vehicle')
            ->get();
    }

    private function importStatement(string $language, float $scale = 1.0): void
    {
        $preview = $this->actingAs($this->admin)
            ->post("/api/contracts/{$this->contract->id}/keeta/statement/preview", ['file' => $this->statementFile($language, $scale)], ['Accept' => 'application/json'])
            ->assertStatus(200)->json();
        $this->actingAs($this->admin)->postJson("/api/contracts/{$this->contract->id}/keeta/statement", ['token' => $preview['token']])->assertStatus(201);
        KeetaRevenueService::forget();
    }

    private function statementFile(string $language, float $scale = 1.0): UploadedFile
    {
        $ar = $language === 'ar';
        $money = fn (float $v) => $ar ? number_format($v * $scale, 3) : ($v < 0 ? '- '.number_format(-$v * $scale, 3) : number_format($v * $scale, 3));
        $total = fn (float $v) => $ar ? "\u{200F}".number_format($v * $scale, 3)." د.ك.\u{200F}" : 'KWD '.number_format($v * $scale, 3);
        $cycle = $ar ? 'أغسطس 2026' : 'Jul 2026';
        $prefix = ['1757258145520976', 'Mirsal (kuwait city)', '50000409', 'MERSAL GROUP', $cycle];

        $moneyHead = $ar
            ? ['التسعير حسب الطلب', 'حوافز تجربة التوصيل', 'حوافز سعة الطلب المتاحة الصالحة (زيادة)', 'إعانة مرنة', 'مكافآت أخرى', 'المكافأة الإضافية', 'مكافأة التأهل (لكل طلب)', 'البقشيش', 'الخصم', 'تعويض عن تلف الطعام ', 'تعديل آخر', 'ضريبة محجوز الضمان: مخصّصة للفترة الحالية', 'ضريبة محجوز الضمان: تحرير']
            : ['Order-Based pricing', 'Experience incentive', 'Valid DA Capacity Incentives', 'Flexible subsidy', 'Other Rewards', 'Extra Reward', 'Unlock reward (per order)', 'Tips', 'Deduction', 'food compensation', 'Other Adjustment', 'Withholding tax – reserve for current period', 'Withholding tax – release'];
        $prefixHead = $ar
            ? ['معرف الشريك', 'اسم الشريك', 'معرِّف المجموعة', 'اسم المجموعة', 'دورة الفوترة']
            : ['Partner ID', 'Partner Name', 'Group ID', 'Group name', 'Billing Cycle'];

        $book = new Spreadsheet;
        $partner = $book->getActiveSheet();
        $partner->setTitle($ar ? 'تفاصيل الشركاء' : 'partnerDetail');
        $partner->fromArray([
            array_merge($prefixHead, $moneyHead, $ar ? ['مبلغ الفاتورة', 'إجمالي المبلغ المستحق'] : ['Invoice Amount', 'Total payable amount']),
            array_merge($prefix, array_map($money, [1000, 270, 0, 0, 0, 0, 0, 5, 0, -4.25, -2, 0, 0]), [$total(1263.75), $total(1268.75)]),
        ], null, 'A1', true);

        $riderHead = array_merge($prefixHead, $ar
            ? ['معرّف سائق التوصيل', 'اسم سائق التوصيل', 'هاتف السائق', 'صالح', 'السبب', 'أيام الاتصال-صالحة', 'ساعات الاتصال اليومي-صالحة', 'ساعات الاتصال اليومي خلال وقت الذروة-صالحة', 'الطلبات المُسلمة']
            : ['Courier ID', 'Courier name', 'Phone', 'Is Valid', 'Reason', 'Online Days-Valid', 'Daily Onlines Hours-Valid', 'Daily Onlines Hours During Peak Time -Valid', 'Delivered Orders'],
            $moneyHead, [$ar ? 'إجمالي المبلغ المستحق' : 'Total payable amount']);
        $valid = $ar ? 'صالح' : "valid\n";
        $invalid = $ar ? 'غير صالح' : 'invalid';
        $rider = fn (string $id, string $name, string $isValid, int $orders, array $m, float $payable) => array_merge(
            $prefix, [$id, $name, '555**683', $isValid, $ar ? 'سبب آخر' : 'other reason', '28.0', '11.2', '0.0', (string) $orders],
            array_map($money, $m), [$total($payable)]
        );
        $riders = $book->createSheet();
        $riders->setTitle($ar ? 'تفاصيل سائق التوصيل' : 'riderDetail');
        $riders->fromArray([
            $riderHead,
            $rider('111', ' Rider A ', $valid, 600, [480, 270, 0, 0, 0, 0, 0, 3, 0, -4.25, -2, 0, 0], 746.75),
            $rider('222', 'Rider B', $invalid, 400, [320, 0, 0, 0, 0, 0, 0, 1.5, 0, 0, 0, 0, 0], 321.5),
            $rider('999', 'Stranger', $valid, 250, [200, 0, 0, 0, 0, 0, 0, 0.5, 0, 0, 0, 0, 0], 200.5),
        ], null, 'A1', true);

        $lineHead = array_merge($prefixHead, $ar
            ? ['معرّف سائق التوصيل', 'اسم سائق التوصيل', 'نوع المعاملة', 'معرّف العمل', 'ملاحظة', 'المبلغ التفصيلي', 'إجمالي المبلغ المستحق', 'معرّف التذكرة', 'معرّف المخالفة', 'نوع المخالفة', 'طرق العقاب']
            : ['Courier ID', 'Courier name', 'transaction type', 'Business ID', 'Note', 'detail amount', 'Total payable amount', 'Ticket ID', 'Violation ID', 'Violation type', 'Punishment methods']);
        $order = $ar ? 'معرّف مهمة التوصيل' : 'Delivery Task ID';
        $adjust = $ar ? 'معرف تعديل السائق' : 'Rider Adjustment ID';
        $penalty = $ar ? 'معرف عقوبة السائق' : 'Rider Penalty ID';
        $lines = $book->createSheet();
        $lines->setTitle($ar ? 'تفاصيل طلب السائق' : 'riderOrderDetail');
        $lines->fromArray([
            $lineHead,
            array_merge($prefix, ['111', 'Rider A', $order, '9001', '', ($ar ? 'التسعير حسب الطلب' : 'Order-Based pricing').':0.800', '0.800']),
            array_merge($prefix, ['111', 'Rider A', $order, '9002', '', ($ar ? 'التسعير حسب الطلب' : 'Order-Based pricing').":0.780\n".($ar ? 'البقشيش' : 'Tips').':0.100', '0.880']),
            array_merge($prefix, ['111', 'Rider A', $adjust, '9003', '', ($ar ? 'حوافز تجربة التوصيل' : 'Experience incentive').':270.000', '270.000']),
            array_merge($prefix, ['111', 'Rider A', $adjust, '9004', '', ($ar ? 'تخفيض جودة الخدمة' : 'Service quality reduction').':-2.000', '-2.000']),
            array_merge($prefix, ['111', 'Rider A', $penalty, '9005', '', ($ar ? 'تعويض عن تلف الطعام ' : 'food compensation').':-4.250', '-4.250', 'T-1', 'V-1',
                ($ar ? 'معرّف المخالفة:V-1 نوع المخالفة:أصناف خاطئة' : 'Violation ID:V-1 Violation type:أصناف خاطئة'), $ar ? 'خصم قيمة الطلب' : 'Deduct order value']),
        ], null, 'A1', true);

        return $this->upload($book, $ar ? 'شهر8.xlsx' : 'month7.xlsx');
    }

    private function levelsFile(): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['Courier ID', 'Name', 'Current estimated level', 'Current estimated reward amount', 'On-time rate', 'Order completion % (non-delivery related)', 'UTR (Utilization Rate)', 'Order volume', '1-to-1 order assignment acceptance rate (%)'],
            ['111', 'Rider A', 'B', '170', '0.99', '1.0', '1.2', '150.0', '0.98'],
            ['222', 'Rider B', '-', '', '0.0', '0', '', '0.0', '0'],
            ['777', 'Top Stranger', 'S', '370', '0.99', '1.0', '1.5', '480.0', '1.0'],
        ], null, 'A1', true);

        return $this->upload($book, 'levels.xlsx');
    }

    private function upload(Spreadsheet $book, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'keeta').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
