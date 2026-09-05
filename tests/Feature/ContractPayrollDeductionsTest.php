<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the company-level deductions applied by the contract payroll path
 * (contractSheet -> approveContractSheet -> consolidatedSheet).
 */
class ContractPayrollDeductionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Client $client;

    private Employee $driver;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Deductions Test Company',
            'code' => 'dedtest',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@deductions.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'Deductions Client',
            'company_id' => $this->company->id,
        ]);

        $this->driver = Employee::create([
            'name' => 'Deductions Driver',
            'employee_number' => 'EMP-DED-1',
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->vehicle = Vehicle::create([
            'plate_number' => 'V-DED-1',
            'make' => 'Toyota',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        $this->actingAs($this->user);
    }

    /**
     * Contract paying a flat 10.000 KWD per worked day (260 / 26 required days).
     */
    private function makeContract(string $name): Contract
    {
        return Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-'.$name,
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
            'driver_pricing_rules' => ['1' => [
                'payment_method' => 'fixed',
                'fixed_amount' => 260,
                'fixed_target' => 0,
            ]],
            'is_validity_enabled' => false,
        ]);
    }

    private function assignDriver(Contract $contract, string $startDate = '2026-03-01'): ContractAssignment
    {
        return ContractAssignment::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $contract->id,
            'start_date' => $startDate,
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);
    }

    /**
     * One worked day => 10.000 KWD of gross contract earnings.
     */
    private function logWorkedDay(Contract $contract, string $date): void
    {
        DailyLog::create([
            'employee_id' => $this->driver->id,
            'contract_id' => $contract->id,
            'vehicle_id' => $this->vehicle->id,
            'log_date' => $date,
            'driver_status' => 'working',
            'orders_count' => 0,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function makeViolation(string $date, float $amount, float $driverShare, bool $alreadyDeducted = false): Violation
    {
        return Violation::create([
            'is_deducted' => $alreadyDeducted,
            'employee_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'violation_date' => $date,
            'violation_type' => 'Speeding',
            'amount' => $amount,
            'driver_deduction' => $driverShare,
            'driver_share' => $driverShare,
            'contract_share' => $amount - $driverShare,
            'is_driver_liable' => $driverShare > 0,
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function contractSheet(Contract $contract, int $year, int $month): array
    {
        return $this->getJson("/api/payroll/contract-sheet/{$contract->id}?year={$year}&month={$month}")
            ->assertOk()
            ->json();
    }

    private function approveSheet(Contract $contract, int $year, int $month): void
    {
        $this->postJson("/api/payroll/contract-sheet/{$contract->id}/approve", [
            'year' => $year,
            'month' => $month,
        ])->assertOk();
    }

    /**
     * Company-level deductions only land once the consolidated month is approved.
     */
    private function approveConsolidated(int $year, int $month): void
    {
        $this->postJson("/api/payroll/consolidated/{$year}/{$month}/approve")->assertOk();
    }

    private function consolidatedDriverRow(int $year, int $month): ?array
    {
        $rows = $this->getJson("/api/payroll/consolidated/{$year}/{$month}")
            ->assertOk()
            ->json('drivers');

        foreach ($rows as $row) {
            if ((int) $row['employee_id'] === $this->driver->id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Fix #1: the sheet summed a non-existent `driver_deduction_amount` column, so the driver's
     * share of a traffic fine was silently always 0.
     */
    /**
     * A fine entered against the wrong driver and then deleted was still taken off him here. The
     * projection that feeds every other screen had always excluded it; this sheet has its own
     * violation query, and withoutGlobalScopes() strips the soft-delete scope along with the
     * company one — so the deleted row came back, and an approval froze it into the month.
     */
    public function test_contract_sheet_does_not_deduct_a_deleted_violation(): void
    {
        $contract = $this->makeContract('Deleted Fine');
        $this->assignDriver($contract);
        $this->logWorkedDay($contract, '2026-03-02');
        $violation = $this->makeViolation('2026-03-10', 30.000, 30.000);

        $this->assertSame(30.0, (float) $this->contractSheet($contract, 2026, 3)['drivers'][0]['violations_deduction']);

        $violation->delete();

        $row = $this->contractSheet($contract, 2026, 3)['drivers'][0];
        $this->assertSame(0.0, (float) $row['violations_deduction'], 'a deleted fine is not owed');
        $this->assertSame(10.0, (float) $row['net_payout'], 'the net returns to the full earnings');
    }

    public function test_contract_sheet_deducts_the_driver_share_of_a_traffic_violation(): void
    {
        $contract = $this->makeContract('Violations');
        $this->assignDriver($contract);
        $this->logWorkedDay($contract, '2026-03-02');
        $this->makeViolation('2026-03-10', 30.000, 30.000);

        $driverRow = $this->contractSheet($contract, 2026, 3)['drivers'][0];

        $this->assertSame(30.0, (float) $driverRow['violations_deduction']);
        $this->assertSame(10.0, (float) $driverRow['gross_contract_earnings']);
        $this->assertSame(-20.0, (float) $driverRow['net_payout']);
    }

    /**
     * A company-liable fine stores driver_deduction = 0, which must stay 0 rather than falling
     * back to the full amount.
     */
    public function test_company_liable_violation_is_not_charged_to_the_driver(): void
    {
        $contract = $this->makeContract('CompanyLiable');
        $this->assignDriver($contract);
        $this->logWorkedDay($contract, '2026-03-02');
        $this->makeViolation('2026-03-10', 45.000, 0.000);

        $driverRow = $this->contractSheet($contract, 2026, 3)['drivers'][0];

        $this->assertSame(0.0, (float) $driverRow['violations_deduction']);
        $this->assertSame(10.0, (float) $driverRow['net_payout']);
    }

    /**
     * A fine belongs to the employee, not to a contract. Summing the per-contract snapshots
     * charged a driver working two contracts in one month twice for the same fine.
     */
    public function test_violation_is_charged_once_for_a_driver_working_two_contracts(): void
    {
        $first = $this->makeContract('First');
        $second = $this->makeContract('Second');
        $this->assignDriver($first);
        $this->assignDriver($second);
        $this->logWorkedDay($first, '2026-03-02');
        $this->logWorkedDay($second, '2026-03-03');
        $this->makeViolation('2026-03-10', 30.000, 30.000);

        $this->approveSheet($first, 2026, 3);
        $this->approveSheet($second, 2026, 3);
        $this->approveConsolidated(2026, 3);

        $row = $this->consolidatedDriverRow(2026, 3);

        $this->assertNotNull($row);
        $this->assertSame(20.0, (float) $row['gross_contract_earnings'], 'both contracts should be summed');
        $this->assertSame(30.0, (float) $row['violations_deduction'], 'the fine must not be doubled');
        $this->assertSame(-10.0, (float) $row['final_net_payout']);
    }

    /**
     * Fix #2: the consolidated sheet is read-only, so `remaining_balance` is never decremented.
     * The instalment must therefore be derived from the advance's own schedule and stop once
     * the principal is covered, instead of repeating every month forever.
     */
    public function test_salary_advance_follows_its_schedule_and_stops_when_repaid(): void
    {
        $contract = $this->makeContract('Advances');
        $this->assignDriver($contract, '2026-01-01');

        SalaryAdvance::create([
            'employee_id' => $this->driver->id,
            'company_id' => $this->company->id,
            'amount' => 100.000,
            'monthly_installment' => 40.000,
            'total_installments' => 3,
            'paid_installments' => 0,
            'remaining_balance' => 100.000,
            'advance_date' => '2026-03-01',
            'status' => 'active',
            'approved_by' => $this->user->id,
        ]);

        // 40 + 40 + 20 = the full 100.000 principal, then nothing.
        $expected = [
            2 => 0.0,   // February: before the advance was granted
            3 => 40.0,
            4 => 40.0,
            5 => 20.0,  // final instalment is capped at the remaining principal
            6 => 0.0,   // fully repaid
        ];

        foreach ($expected as $month => $expectedInstallment) {
            $this->logWorkedDay($contract, sprintf('2026-%02d-02', $month));
            $this->approveSheet($contract, 2026, $month);
            $this->approveConsolidated(2026, $month);

            $row = $this->consolidatedDriverRow(2026, $month);

            $this->assertNotNull($row, "driver missing from consolidated sheet for month {$month}");
            $this->assertSame(
                $expectedInstallment,
                (float) $row['advances_deduction'],
                "unexpected advance instalment for month {$month}"
            );
        }

        // Three instalments covered the whole principal, so the advance is closed.
        $this->assertDatabaseHas('salary_advances', [
            'employee_id' => $this->driver->id,
            'remaining_balance' => 0.000,
            'status' => 'completed',
        ]);
    }

    /**
     * Both payroll paths are live. A fine the legacy PayrollRun already collected carries
     * `is_deducted`, and charging it again on the contract path would take it twice.
     */
    public function test_a_violation_already_collected_elsewhere_is_not_charged_again(): void
    {
        $contract = $this->makeContract('AlreadyDeducted');
        $this->assignDriver($contract);
        $this->logWorkedDay($contract, '2026-03-02');

        $this->makeViolation('2026-03-10', 50.000, 50.000, alreadyDeducted: true);
        $this->makeViolation('2026-03-12', 12.000, 12.000);

        $driverRow = $this->contractSheet($contract, 2026, 3)['drivers'][0];

        $this->assertSame(12.0, (float) $driverRow['violations_deduction'], 'only the outstanding fine');
        $this->assertSame(50.0, (float) $driverRow['violations_already_deducted'], 'reported, not charged');
        $this->assertSame(-2.0, (float) $driverRow['net_payout'], '10 earned − 12 outstanding');
    }

    /**
     * The consolidated sheet resolves fines per employee, so it needs the same guard.
     */
    public function test_consolidated_sheet_also_skips_already_collected_violations(): void
    {
        $contract = $this->makeContract('ConsolidatedAlreadyDeducted');
        $this->assignDriver($contract);
        $this->logWorkedDay($contract, '2026-03-02');

        $this->makeViolation('2026-03-10', 50.000, 50.000, alreadyDeducted: true);
        $this->makeViolation('2026-03-12', 4.000, 4.000);

        $this->approveSheet($contract, 2026, 3);
        $this->approveConsolidated(2026, 3);

        $row = $this->consolidatedDriverRow(2026, 3);

        $this->assertSame(4.0, (float) $row['violations_deduction']);
        $this->assertSame(6.0, (float) $row['final_net_payout'], '10 earned − 4 outstanding');
    }

    /**
     * Unapproving must release only what this run charged; the legacy run's flag stays put.
     */
    public function test_unapproving_does_not_release_a_legacy_payroll_deduction(): void
    {
        $contract = $this->makeContract('LegacyFlagSafe');
        $this->assignDriver($contract);
        $this->logWorkedDay($contract, '2026-03-02');

        $legacy = $this->makeViolation('2026-03-10', 50.000, 50.000, alreadyDeducted: true);
        $fresh = $this->makeViolation('2026-03-12', 4.000, 4.000);

        $this->approveSheet($contract, 2026, 3);
        $this->approveConsolidated(2026, 3);

        $this->assertTrue((bool) $fresh->fresh()->is_deducted, 'this run collected it');

        $this->postJson('/api/payroll/consolidated/2026/3/unapprove')->assertOk();

        $this->assertTrue((bool) $legacy->fresh()->is_deducted, 'legacy deduction must survive');
        $this->assertFalse((bool) $fresh->fresh()->is_deducted, 'this run released its own');
    }

    /**
     * Fix #3: contractSheet queried advances with a status that does not exist and never used
     * the result. Advances belong to the consolidated sheet only.
     */
    public function test_contract_sheet_never_deducts_salary_advances(): void
    {
        $contract = $this->makeContract('NoAdvances');
        $this->assignDriver($contract);
        $this->logWorkedDay($contract, '2026-03-02');

        SalaryAdvance::create([
            'employee_id' => $this->driver->id,
            'company_id' => $this->company->id,
            'amount' => 100.000,
            'monthly_installment' => 40.000,
            'total_installments' => 3,
            'paid_installments' => 0,
            'remaining_balance' => 100.000,
            'advance_date' => '2026-03-01',
            'status' => 'active',
            'approved_by' => $this->user->id,
        ]);

        $driverRow = $this->contractSheet($contract, 2026, 3)['drivers'][0];

        $this->assertSame(0.0, (float) $driverRow['global_deductions']['advances']);
        $this->assertSame(10.0, (float) $driverRow['net_payout'], 'the advance belongs to the consolidated sheet');
    }
}
