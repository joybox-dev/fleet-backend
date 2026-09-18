<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ConsolidatedPayrollDeduction;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\PayrollDeductionOverride;
use App\Models\Role;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\EmployeeLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The owner's control over individual charges before a month is approved: a fine deferred to a
 * later month is charged there and nowhere else; an advance instalment is whatever he set for
 * this month, never more than what is left; decisions are refused where they could not hold.
 *
 * Fixture: a fixed 260/26 contract (10.000 a day). March: two days (20.000), a 6.000 fine dated
 * in March, and a 9.000 advance repaid 4.000 a month. April: three days (30.000).
 */
class PayrollDeductionOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Employee $driver;

    private Contract $contract;

    private Vehicle $vehicle;

    private Violation $fine;

    private SalaryAdvance $advance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Override Co',
            'code' => 'ovrco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@ovr.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);

        $this->driver = Employee::create([
            'name' => 'Decided Driver',
            'employee_number' => 'EMP-OVR-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-OVR-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id,
            'contract_number' => 'CON-OVR',
            'name' => 'Override Contract',
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

        ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $this->contract->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        foreach (['2026-03-02', '2026-03-03', '2026-04-01', '2026-04-02', '2026-04-03'] as $date) {
            DailyLog::create([
                'employee_id' => $this->driver->id,
                'contract_id' => $this->contract->id,
                'vehicle_id' => $this->vehicle->id,
                'log_date' => $date,
                'driver_status' => 'working',
                'orders_count' => 0,
                'company_id' => $this->company->id,
                'created_by' => $this->admin->id,
            ]);
        }

        $this->fine = Violation::create([
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'violation_date' => '2026-03-10',
            'violation_type' => 'Speeding',
            'reference_number' => 'F-1',
            'amount' => 6.000,
            'driver_deduction' => 6.000,
            'driver_share' => 6.000,
            'contract_share' => 0.000,
            'is_driver_liable' => true,
            'company_id' => $this->company->id,
            'created_by' => $this->admin->id,
        ]);

        $this->advance = SalaryAdvance::create([
            'employee_id' => $this->driver->id,
            'company_id' => $this->company->id,
            'amount' => 9.000,
            'monthly_installment' => 4.000,
            'total_installments' => 3,
            'paid_installments' => 0,
            'remaining_balance' => 9.000,
            'advance_date' => '2026-03-01',
            'status' => 'active',
            'approved_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);
    }

    private function approveContract(int $month): void
    {
        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", ['year' => 2026, 'month' => $month])->assertOk();
    }

    private function approveMonth(int $month): void
    {
        $this->approveContract($month);
        $this->postJson("/api/payroll/consolidated/2026/{$month}/approve")->assertOk();
    }

    /** @return array<string, mixed> */
    private function driverRow(int $month): array
    {
        $sheet = $this->getJson("/api/payroll/consolidated/2026/{$month}")->assertOk()->json();
        foreach ($sheet['drivers'] as $row) {
            if ((int) $row['employee_id'] === $this->driver->id) {
                return $row;
            }
        }
        $this->fail("driver missing from month {$month}");
    }

    /** @param  array<string, mixed>  $payload */
    private function decide(int $month, array $payload): TestResponse
    {
        return $this->postJson("/api/payroll/consolidated/2026/{$month}/deduction-overrides", $payload + ['reason' => 'قرار المالك']);
    }

    public function test_a_deferred_fine_is_charged_in_the_month_it_was_deferred_to_and_nowhere_else(): void
    {
        $this->decide(3, ['source_type' => 'violation', 'source_id' => $this->fine->id, 'action' => 'defer', 'defer_to_year' => 2026, 'defer_to_month' => 4])
            ->assertCreated();

        // An open contract sheet no longer takes the fine off the contract's own net, and says why.
        $contractRow = fn () => collect($this->getJson("/api/payroll/contract-sheet/{$this->contract->id}?year=2026&month=3")->json('drivers'))
            ->firstWhere('employee_id', $this->driver->id);
        $this->assertSame(0.0, (float) $contractRow()['violations_deduction']);
        $this->assertSame(6.0, (float) $contractRow()['violations_deferred']);

        $this->approveContract(3);
        $this->approveContract(4);

        // A frozen sheet keeps what was approved but still names the deferral.
        $this->assertSame(0.0, (float) $contractRow()['violations_deduction']);
        $this->assertSame(6.0, (float) $contractRow()['violations_deferred']);

        // March, still open: the fine is out of the pending figure and shown as a decision.
        $march = $this->driverRow(3);
        $this->assertSame(0.0, (float) $march['pending_violations_deduction']);
        $this->assertSame(4.0, (float) $march['pending_deductions_total'], 'only the advance instalment');
        $this->assertCount(1, $march['deferred_items']);
        $this->assertSame('04/2026', $march['deferred_items'][0]['override']['defer_to']);
        $this->assertSame('قرار المالك', $march['deferred_items'][0]['override']['reason']);

        // April, still open: the fine is there, labelled with where it came from.
        $april = $this->driverRow(4);
        $this->assertSame(6.0, (float) $april['pending_violations_deduction']);
        $fineItem = collect($april['deduction_items'])->firstWhere('source_type', 'violation');
        $this->assertStringContainsString('مؤجَّلة من 03/2026', $fineItem['label']);

        // Approval follows the decision: March charges the advance only, April the fine and the next instalment.
        $this->postJson('/api/payroll/consolidated/2026/3/approve')->assertOk();
        $this->assertSame(4.0, (float) $this->driverRow(3)['deductions_total']);
        $this->assertSame(0, (int) $this->fine->fresh()->is_deducted);

        $this->postJson('/api/payroll/consolidated/2026/4/approve')->assertOk();
        $aprilApproved = $this->driverRow(4);
        $this->assertSame(10.0, (float) $aprilApproved['deductions_total'], '6.000 fine + 4.000 instalment');
        $this->assertSame(20.0, (float) $aprilApproved['final_net_payout']);
        $this->assertSame(1, (int) $this->fine->fresh()->is_deducted);
        $this->assertSame(1, ConsolidatedPayrollDeduction::withoutGlobalScopes()->where('source_type', 'violation')->where('source_id', $this->fine->id)->count(), 'charged once');
    }

    public function test_this_months_instalment_is_what_the_owner_set_never_more_than_what_is_left(): void
    {
        $this->approveContract(3);

        $this->decide(3, ['source_type' => 'advance', 'source_id' => $this->advance->id, 'action' => 'amount', 'amount' => 2.5])->assertCreated();
        $item = collect($this->driverRow(3)['deduction_items'])->firstWhere('source_type', 'advance');
        $this->assertSame(2.5, (float) $item['amount']);
        $this->assertStringContainsString('معدَّل هذا الشهر', $item['label']);

        $this->postJson('/api/payroll/consolidated/2026/3/approve')->assertOk();
        $advance = $this->advance->fresh();
        $this->assertSame(6.5, (float) $advance->remaining_balance);
        $this->assertSame(1, (int) $advance->paid_installments);
        $this->assertSame('active', $advance->status);

        // Unapprove keeps the decision; setting it to nothing skips the month entirely.
        $this->postJson('/api/payroll/consolidated/2026/3/unapprove')->assertOk();
        $this->assertSame(9.0, (float) $this->advance->fresh()->remaining_balance);
        $this->decide(3, ['source_type' => 'advance', 'source_id' => $this->advance->id, 'action' => 'amount', 'amount' => 0])->assertCreated();
        $row = $this->driverRow(3);
        $this->assertSame(0.0, (float) $row['pending_advances_deduction']);
        $this->assertCount(1, $row['deferred_items']);

        $this->postJson('/api/payroll/consolidated/2026/3/approve')->assertOk();
        $this->assertSame(9.0, (float) $this->advance->fresh()->remaining_balance);
        $this->assertSame(0, (int) $this->advance->fresh()->paid_installments);
        $this->assertSame(6.0, (float) $this->driverRow(3)['deductions_total'], 'the fine alone');
    }

    public function test_decisions_that_could_not_hold_are_refused(): void
    {
        $this->approveContract(3);

        // More than the advance has left.
        $this->decide(3, ['source_type' => 'advance', 'source_id' => $this->advance->id, 'action' => 'amount', 'amount' => 20])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        // A different amount on a fine: the fine itself is where that is corrected.
        $this->decide(3, ['source_type' => 'violation', 'source_id' => $this->fine->id, 'action' => 'amount', 'amount' => 3])
            ->assertStatus(422)->assertJsonValidationErrors(['action']);
        // Deferring to the same month, or backwards.
        $this->decide(3, ['source_type' => 'violation', 'source_id' => $this->fine->id, 'action' => 'defer', 'defer_to_year' => 2026, 'defer_to_month' => 3])
            ->assertStatus(422)->assertJsonValidationErrors(['defer_to_month']);
        // A charge that does not exist.
        $this->decide(3, ['source_type' => 'violation', 'source_id' => 999999, 'action' => 'defer', 'defer_to_year' => 2026, 'defer_to_month' => 4])
            ->assertStatus(422);

        // Deferring into a month already approved.
        $this->approveMonth(4);
        $this->decide(3, ['source_type' => 'violation', 'source_id' => $this->fine->id, 'action' => 'defer', 'defer_to_year' => 2026, 'defer_to_month' => 4])
            ->assertStatus(422)->assertJsonValidationErrors(['defer_to_month']);

        // A month that is approved takes no decisions; one that charged the fine takes none either.
        $this->postJson('/api/payroll/consolidated/2026/4/unapprove')->assertOk();
        $this->postJson('/api/payroll/consolidated/2026/3/approve')->assertOk();
        $this->decide(3, ['source_type' => 'violation', 'source_id' => $this->fine->id, 'action' => 'defer', 'defer_to_year' => 2026, 'defer_to_month' => 5])
            ->assertStatus(422);
        $this->assertSame(0, PayrollDeductionOverride::withoutGlobalScopes()->count());
    }

    public function test_cancelling_a_decision_returns_the_charge_to_its_own_month(): void
    {
        $this->approveContract(3);
        $id = $this->decide(3, ['source_type' => 'violation', 'source_id' => $this->fine->id, 'action' => 'defer', 'defer_to_year' => 2026, 'defer_to_month' => 4])
            ->assertCreated()->json('override.id');
        $this->assertSame(0.0, (float) $this->driverRow(3)['pending_violations_deduction']);

        $this->deleteJson("/api/payroll/deduction-overrides/{$id}")->assertOk();
        $this->assertSame(6.0, (float) $this->driverRow(3)['pending_violations_deduction']);
    }

    public function test_the_statement_moves_the_fine_with_the_decision(): void
    {
        $this->decide(3, ['source_type' => 'violation', 'source_id' => $this->fine->id, 'action' => 'defer', 'defer_to_year' => 2026, 'defer_to_month' => 4])
            ->assertCreated();

        $months = collect(EmployeeLedgerService::history($this->driver, '2026-03', '2026-05')['months'])->keyBy('label');
        $this->assertSame(0.0, (float) $months['03/2026']['deductions']['violations']);
        $this->assertSame(6.0, (float) $months['04/2026']['deductions']['violations']);
        // The statement counts a charge in one month only, even though payroll keeps offering it.
        $this->assertSame(0.0, (float) $months['05/2026']['deductions']['violations']);
    }

    public function test_a_deferred_charge_waits_visibly_for_a_driver_who_is_not_on_the_sheet_yet(): void
    {
        $this->decide(3, ['source_type' => 'violation', 'source_id' => $this->fine->id, 'action' => 'defer', 'defer_to_year' => 2026, 'defer_to_month' => 4])
            ->assertCreated();

        // No April contract approved: the driver has no April row, but the charge is named.
        $april = $this->getJson('/api/payroll/consolidated/2026/4')->assertOk()->json();
        $this->assertSame([], $april['drivers']);
        $this->assertCount(1, $april['deferred_charges_off_sheet']);
        $this->assertSame($this->driver->id, $april['deferred_charges_off_sheet'][0]['employee_id']);
        $this->assertSame(6.0, (float) $april['deferred_charges_off_sheet'][0]['amount']);
        $this->assertSame('03/2026', $april['deferred_charges_off_sheet'][0]['deferred_from']);

        // It does not fall off the calendar: May still names it, until a sheet collects it.
        $may = $this->getJson('/api/payroll/consolidated/2026/5')->assertOk()->json();
        $this->assertCount(1, $may['deferred_charges_off_sheet']);

        // The moment he has an April row the charge is on it, and approval takes it once.
        $this->approveContract(4);
        $row = $this->driverRow(4);
        $this->assertSame(6.0, (float) $row['pending_violations_deduction']);
        $this->assertSame([], $this->getJson('/api/payroll/consolidated/2026/4')->json('deferred_charges_off_sheet'));
        $this->postJson('/api/payroll/consolidated/2026/4/approve')->assertOk();
        $this->assertSame(1, (int) $this->fine->fresh()->is_deducted);
        $this->assertSame([], $this->getJson('/api/payroll/consolidated/2026/5')->json('deferred_charges_off_sheet'));
    }

    /** A fine recorded after its month was closed: dated 2026-03-20, 9.000, on the same driver. */
    private function lateFine(string $date = '2026-03-20', float $amount = 9.000, string $reference = 'F-LATE'): Violation
    {
        return Violation::create([
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'violation_date' => $date.' 15:30:00',
            'violation_type' => 'Parking',
            'reference_number' => $reference,
            'amount' => $amount,
            'driver_deduction' => $amount,
            'driver_share' => $amount,
            'contract_share' => 0.000,
            'is_driver_liable' => $amount > 0,
            'company_id' => $this->company->id,
            'created_by' => $this->admin->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function pastFines(int $month): array
    {
        return $this->getJson("/api/payroll/consolidated/2026/{$month}/past-fines")->assertOk()->json();
    }

    public function test_a_fine_its_own_month_never_collected_is_carried_by_hand_into_an_open_month(): void
    {
        // March is closed with what it knew about; the second fine reaches the office afterwards.
        $this->approveMonth(3);
        $late = $this->lateFine();
        $this->approveContract(4);

        // No sheet will ever reach it on its own — April says so, and leaves it out of the figures.
        $waiting = $this->pastFines(4);
        $this->assertSame(1, $waiting['count']);
        $this->assertSame(9.0, (float) $waiting['total']);
        $this->assertSame($late->id, $waiting['fines'][0]['id']);
        $this->assertSame($this->driver->id, $waiting['fines'][0]['employee_id']);
        $this->assertSame('03/2026', $waiting['fines'][0]['month_label']);
        $this->assertTrue($waiting['fines'][0]['own_month_approved']);
        $this->assertSame(0.0, (float) $this->driverRow(4)['pending_violations_deduction']);

        $override = $this->decide(4, ['source_type' => 'violation', 'source_id' => $late->id, 'action' => 'carry'])
            ->assertCreated()->json('override');
        $this->assertSame('carry', $override['action']);
        $this->assertSame('04/2026', $override['defer_to']);
        $this->assertSame([2026, 3], [$override['year'], $override['month']], 'filed on the fine\'s own month, so March can never take it too');

        // April now carries it, named for what it is and whose decision it was.
        $april = $this->driverRow(4);
        $this->assertSame(9.0, (float) $april['pending_violations_deduction']);
        $this->assertSame(13.0, (float) $april['pending_deductions_total'], '9.000 fine + 4.000 instalment');
        $item = collect($april['deduction_items'])->firstWhere('source_id', $late->id);
        $this->assertStringContainsString('من شهر 03/2026، خصم يدوي', $item['label']);
        $this->assertSame('قرار المالك', $item['carried']['reason']);
        $this->assertSame('Admin', $item['carried']['created_by_name']);
        $this->assertSame(0, $this->pastFines(4)['count'], 'on its way to a sheet — not offered twice');

        // March stays exactly as approved; its contract sheet names the fine that went elsewhere.
        $this->assertSame(10.0, (float) $this->driverRow(3)['deductions_total']);
        $marchContract = collect($this->getJson("/api/payroll/contract-sheet/{$this->contract->id}?year=2026&month=3")->json('drivers'))
            ->firstWhere('employee_id', $this->driver->id);
        $this->assertSame(9.0, (float) $marchContract['violations_deferred']);

        // Approval takes it once, and the ledger remembers it was taken by hand.
        $this->postJson('/api/payroll/consolidated/2026/4/approve')->assertOk();
        $approved = $this->driverRow(4);
        $this->assertSame(13.0, (float) $approved['deductions_total']);
        $this->assertSame(17.0, (float) $approved['final_net_payout']);
        $this->assertSame(1, (int) $late->fresh()->is_deducted);
        $ledger = ConsolidatedPayrollDeduction::withoutGlobalScopes()->where('source_type', 'violation')->where('source_id', $late->id)->get();
        $this->assertCount(1, $ledger);
        $this->assertStringContainsString('خصم يدوي', $ledger[0]->label);

        // Collected is collected: the decision cannot be pulled from under an approved month.
        $this->deleteJson("/api/payroll/deduction-overrides/{$override['id']}")->assertStatus(422);

        // Reopening April releases the fine and keeps the decision, like every other one.
        $this->postJson('/api/payroll/consolidated/2026/4/unapprove')->assertOk();
        $this->assertSame(0, (int) $late->fresh()->is_deducted);
        $this->assertSame(9.0, (float) $this->driverRow(4)['pending_violations_deduction']);
    }

    public function test_the_manual_carry_is_only_for_a_fine_of_an_earlier_month(): void
    {
        $this->approveContract(4);
        $carry = fn (int $month, string $type, int $id) => $this->decide($month, ['source_type' => $type, 'source_id' => $id, 'action' => 'carry']);

        // A fine of the month itself is taken by that month's approval — no second way to charge it.
        $april = $this->lateFine('2026-04-02', 5.000, 'F-APR');
        $carry(4, 'violation', $april->id)->assertStatus(422)->assertJsonValidationErrors(['action']);
        // Nor one dated after it.
        $may = $this->lateFine('2026-05-05', 5.000, 'F-MAY');
        $carry(4, 'violation', $may->id)->assertStatus(422)->assertJsonValidationErrors(['action']);
        // Every other charge rolls forward by itself.
        $carry(4, 'advance', $this->advance->id)->assertStatus(422)->assertJsonValidationErrors(['action']);
        // A fine the company bears takes nothing off the driver.
        $companyBorne = $this->lateFine('2026-03-22', 0.000, 'F-CO');
        $carry(4, 'violation', $companyBorne->id)->assertStatus(422)->assertJsonValidationErrors(['source_id']);
        // A fine already deferred is on its way somewhere.
        $this->decide(3, ['source_type' => 'violation', 'source_id' => $this->fine->id, 'action' => 'defer', 'defer_to_year' => 2026, 'defer_to_month' => 5])->assertCreated();
        $carry(4, 'violation', $this->fine->id)->assertStatus(422)->assertJsonValidationErrors(['source_id']);
        // And an approved month takes no decisions at all.
        $late = $this->lateFine();
        $this->postJson('/api/payroll/consolidated/2026/4/approve')->assertOk();
        $carry(4, 'violation', $late->id)->assertStatus(422);

        $this->assertSame(0, PayrollDeductionOverride::withoutGlobalScopes()->where('action', 'carry')->count());
        // The last day of a month, with a time on it, still belongs to that month.
        $endOfMonth = $this->lateFine('2026-03-31', 3.000, 'F-EOM');
        $this->assertSame('03/2026', collect($this->pastFines(5)['fines'])->firstWhere('id', $endOfMonth->id)['month_label']);
    }

    public function test_a_carry_is_taken_back_for_as_long_as_the_fine_is_uncollected(): void
    {
        $this->approveMonth(3);
        $late = $this->lateFine();
        $this->approveContract(4);

        $id = $this->decide(4, ['source_type' => 'violation', 'source_id' => $late->id, 'action' => 'carry'])->assertCreated()->json('override.id');
        $this->assertSame(9.0, (float) $this->driverRow(4)['pending_violations_deduction']);

        // Its own month is approved — that is why it was carried — and the decision still comes off.
        $this->deleteJson("/api/payroll/deduction-overrides/{$id}")->assertOk();
        $this->assertSame(0.0, (float) $this->driverRow(4)['pending_violations_deduction']);
        $this->assertSame(1, $this->pastFines(4)['count'], 'waiting again');

        // Carried a second time into a later month, the same row moves rather than doubling.
        $this->decide(4, ['source_type' => 'violation', 'source_id' => $late->id, 'action' => 'carry'])->assertCreated();
        $this->decide(5, ['source_type' => 'violation', 'source_id' => $late->id, 'action' => 'carry'])->assertCreated();
        $this->assertSame(1, PayrollDeductionOverride::withoutGlobalScopes()->where('source_id', $late->id)->count());
        $this->assertSame(0.0, (float) $this->driverRow(4)['pending_violations_deduction'], 'travelling past April now');
    }

    public function test_a_fine_carried_out_of_a_month_still_open_is_charged_once_in_the_month_it_was_carried_to(): void
    {
        // March was never closed; the owner, working on April, takes March's fine there.
        $this->approveContract(3);
        $this->approveContract(4);
        $this->decide(4, ['source_type' => 'violation', 'source_id' => $this->fine->id, 'action' => 'carry'])->assertCreated();

        $march = $this->driverRow(3);
        $this->assertSame(0.0, (float) $march['pending_violations_deduction']);
        $this->assertSame('carry', $march['deferred_items'][0]['override']['action']);
        $this->assertSame('04/2026', $march['deferred_items'][0]['override']['defer_to']);
        $this->assertSame(6.0, (float) $this->driverRow(4)['pending_violations_deduction']);

        // Whichever order the months close in, the fine is taken once.
        $this->postJson('/api/payroll/consolidated/2026/3/approve')->assertOk();
        $this->assertSame(0, (int) $this->fine->fresh()->is_deducted);
        $this->postJson('/api/payroll/consolidated/2026/4/approve')->assertOk();
        $this->assertSame(1, (int) $this->fine->fresh()->is_deducted);
        $this->assertSame(1, ConsolidatedPayrollDeduction::withoutGlobalScopes()->where('source_type', 'violation')->where('source_id', $this->fine->id)->count());
    }

    public function test_the_statement_and_the_fines_list_follow_a_carried_fine(): void
    {
        $this->approveMonth(3);
        $late = $this->lateFine();
        $this->decide(4, ['source_type' => 'violation', 'source_id' => $late->id, 'action' => 'carry'])->assertCreated();

        // The statement counts it in the month that takes it, and in that month only.
        $months = collect(EmployeeLedgerService::history($this->driver, '2026-03', '2026-05')['months'])->keyBy('label');
        $this->assertSame(6.0, (float) $months['03/2026']['deductions']['violations'], 'March as approved');
        $this->assertSame(9.0, (float) $months['04/2026']['deductions']['violations']);
        $this->assertSame(0.0, (float) $months['05/2026']['deductions']['violations']);

        // The fines screen says where each pending fine is headed, and which months are closed.
        $list = $this->getJson('/api/violations')->assertOk()->json();
        $this->assertSame(['2026-03'], $list['approved_months']);
        $row = collect($list['data'])->firstWhere('id', $late->id);
        $this->assertSame('carry', $row['scheduled']['action']);
        $this->assertSame('04/2026', $row['scheduled']['to']);
        $this->assertNull(collect($list['data'])->firstWhere('id', $this->fine->id)['scheduled']);
    }

    public function test_deciding_needs_the_payroll_edit_permission(): void
    {
        $role = Role::create(['name' => 'مراقب', 'company_id' => $this->company->id, 'allowed_modules' => ['employees']]);
        $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@ovr.test', 'password' => bcrypt('password'), 'role' => 'مراقب', 'company_id' => $this->company->id]);
        Employee::create(['name' => 'Viewer', 'employee_number' => 'EMP-V', 'company_id' => $this->company->id, 'status' => 'active', 'role_category' => 'admin', 'admin_role_id' => $role->id, 'user_id' => $viewer->id, 'date_of_joining' => '2026-01-01']);

        $this->actingAs($viewer);
        $this->decide(3, ['source_type' => 'violation', 'source_id' => $this->fine->id, 'action' => 'defer', 'defer_to_year' => 2026, 'defer_to_month' => 4])
            ->assertStatus(403);
    }
}
