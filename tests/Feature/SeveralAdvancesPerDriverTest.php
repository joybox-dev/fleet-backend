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
use App\Services\CompanyDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phones and SIM lines handed to drivers are advances of their own (client, 2026-11): a driver can
 * hold a cash advance, a phone and a line at once, each collected on its own schedule; a line that
 * is cut is stopped on its own, and one passed to another driver moves its balance with it. One
 * active advance per driver used to be the rule, which forced them into a single figure that could
 * be neither stopped nor moved in part.
 *
 * Every one of these moves has to stay true to the payroll sheet, which is what collects the money.
 */
class SeveralAdvancesPerDriverTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $driver;

    private Employee $other;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Advances Co', 'code' => 'severaladv',
            'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true,
        ]);
        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin', 'email' => 'admin@several.test', 'password' => bcrypt('password'),
            'role' => 'admin', 'company_id' => $this->company->id, 'is_active' => true,
        ]);

        $client = Client::create(['name' => 'Client', 'company_id' => $this->company->id]);
        $vehicle = Vehicle::create([
            'plate_number' => 'V-SEV-1', 'make' => 'Toyota', 'status' => 'working',
            'company_id' => $this->company->id, 'vehicle_type_id' => 1,
        ]);

        $this->contract = Contract::create([
            'client_id' => $client->id, 'contract_number' => 'CON-SEV', 'name' => 'Contract',
            'payment_type' => 'fixed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'client_payment_method' => 'fixed', 'driver_payment_method' => 'fixed',
            'company_id' => $this->company->id, 'currency' => 'KWD', 'default_required_work_days' => 26,
            'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 500]],
            'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 260, 'fixed_target' => 0]],
            'is_validity_enabled' => false,
        ]);

        foreach (['driver' => 'EMP-SEV-1', 'other' => 'EMP-SEV-2'] as $prop => $number) {
            $this->{$prop} = Employee::create([
                'name' => $prop === 'driver' ? 'سائق أول' : 'سائق ثاني', 'employee_number' => $number,
                'company_id' => $this->company->id, 'status' => 'active', 'role_category' => 'driver',
                'date_of_joining' => '2026-01-01', 'actual_salary' => 0,
            ]);
            ContractAssignment::create([
                'employee_id' => $this->{$prop}->id, 'contract_id' => $this->contract->id,
                'start_date' => '2026-01-01', 'status' => 'active', 'company_id' => $this->company->id,
            ]);
            // Five worked days a month in March and April => 50.000 each month.
            foreach (['03', '04'] as $m) {
                foreach (['02', '03', '04', '05', '06'] as $d) {
                    DailyLog::create([
                        'employee_id' => $this->{$prop}->id, 'contract_id' => $this->contract->id,
                        'vehicle_id' => $vehicle->id, 'log_date' => "2026-{$m}-{$d}",
                        'driver_status' => 'working', 'orders_count' => 0,
                        'company_id' => $this->company->id, 'created_by' => $this->user->id,
                    ]);
                }
            }
        }

        $this->actingAs($this->user);
    }

    private function advance(string $purpose, float $amount, float $installment, ?Employee $for = null): SalaryAdvance
    {
        $id = $this->postJson('/api/salary-advances', [
            'employee_id' => ($for ?? $this->driver)->id,
            'amount' => $amount,
            'monthly_installment' => $installment,
            'advance_date' => '2026-03-01',
            'purpose' => $purpose,
            'voucher_path' => 'advances/voucher.pdf',
        ])->assertCreated()->json('id');

        return SalaryAdvance::findOrFail($id);
    }

    private function approve(int $month): void
    {
        $this->postJson("/api/payroll/contract-sheet/{$this->contract->id}/approve", ['year' => 2026, 'month' => $month])->assertOk();
        $this->postJson("/api/payroll/consolidated/2026/{$month}/approve")->assertOk();
    }

    /** @return array<string, mixed> */
    private function row(int $month, Employee $employee): array
    {
        $sheet = $this->getJson("/api/payroll/consolidated/2026/{$month}")->assertOk()->json();
        foreach ($sheet['drivers'] as $row) {
            if ((int) $row['employee_id'] === $employee->id) {
                return $row;
            }
        }
        $this->fail('driver missing from the sheet');
    }

    public function test_a_driver_holds_a_cash_advance_a_phone_and_a_line_at_once_and_the_sheet_takes_each(): void
    {
        $this->advance('cash', 30, 10);
        $this->advance('phone', 60, 15);
        $this->advance('sim', 48, 2);

        $this->approve(3);

        $this->assertSame(27.0, (float) $this->row(3, $this->driver)['advances_deduction'], '10 + 15 + 2');
        $this->assertSame(23.0, (float) $this->row(3, $this->driver)['final_net_payout'], '50 − 27');

        $this->assertEqualsCanonicalizing(
            [20.0, 45.0, 46.0],
            SalaryAdvance::pluck('remaining_balance')->map(fn ($v) => (float) $v)->all()
        );
    }

    public function test_the_sheet_line_names_what_the_advance_was_for(): void
    {
        $this->advance('cash', 30, 10);
        $this->advance('phone', 60, 15);

        $labels = collect(CompanyDeductionService::pendingFor([$this->driver->id], '2026-03-01', '2026-03-31', 2026, 3)[$this->driver->id]['items'])
            ->pluck('label')->all();

        $this->assertContains('قسط سلفة 1 من 3', $labels, 'cash keeps the line it always had');
        $this->assertContains('قسط سلفة (هاتف) 1 من 4', $labels);
    }

    public function test_a_stopped_line_keeps_what_was_taken_and_is_never_charged_again(): void
    {
        $line = $this->advance('sim', 48, 2);
        $phone = $this->advance('phone', 60, 15);
        $this->approve(3);

        $this->postJson("/api/salary-advances/{$line->id}/stop", ['reason' => 'أُوقف الخط', 'date' => '2026-04-01'])->assertOk();

        $line->refresh();
        $this->assertSame(SalaryAdvance::STATUS_STOPPED, $line->status);
        $this->assertSame(0.0, (float) $line->remaining_balance);
        $this->assertSame(46.0, (float) $line->closed_balance, 'the balance it dropped is on record');
        $this->assertSame(1, (int) $line->paid_installments, 'what March took stays taken');

        $this->approve(4);
        $this->assertSame(15.0, (float) $this->row(4, $this->driver)['advances_deduction'], 'only the phone in April');
        $this->assertSame(30.0, (float) $phone->fresh()->remaining_balance);

        // Nothing is left owing on the line, so it no longer stands in the way of removing him.
        $this->assertSame(46.0, (float) $line->fresh()->closed_balance);
    }

    public function test_a_line_passed_to_another_driver_carries_its_balance_and_is_collected_from_him(): void
    {
        $line = $this->advance('sim', 48, 2);
        $this->approve(3);

        $newId = $this->postJson("/api/salary-advances/{$line->id}/transfer", [
            'employee_id' => $this->other->id, 'monthly_installment' => 4, 'date' => '2026-04-01',
        ])->assertCreated()->json('data.id');

        $moved = SalaryAdvance::findOrFail($newId);
        $this->assertSame($this->other->id, (int) $moved->employee_id);
        $this->assertSame('sim', $moved->purpose);
        $this->assertSame(46.0, (float) $moved->amount);
        $this->assertSame(12, (int) $moved->total_installments, 'ceil(46 / 4)');
        $this->assertSame($line->id, (int) $moved->transferred_from_id);
        $this->assertNull($moved->voucher_path, 'no cash changed hands');

        $line->refresh();
        $this->assertSame(SalaryAdvance::STATUS_TRANSFERRED, $line->status);
        $this->assertSame(0.0, (float) $line->remaining_balance);
        $this->assertSame($moved->id, (int) $line->transferred_to_id);

        $this->approve(4);
        $this->assertSame(0.0, (float) $this->row(4, $this->driver)['advances_deduction']);
        $this->assertSame(4.0, (float) $this->row(4, $this->other)['advances_deduction']);
        $this->assertSame(42.0, (float) $moved->fresh()->remaining_balance);
    }

    public function test_a_transfer_is_undone_only_while_the_new_driver_has_paid_nothing(): void
    {
        $line = $this->advance('sim', 48, 2);
        $this->approve(3);
        $newId = $this->postJson("/api/salary-advances/{$line->id}/transfer", [
            'employee_id' => $this->other->id, 'monthly_installment' => 4, 'date' => '2026-04-01',
        ])->assertCreated()->json('data.id');

        $this->approve(4);
        $this->postJson("/api/salary-advances/{$line->id}/reopen")->assertStatus(422);

        $this->postJson('/api/payroll/consolidated/2026/4/unapprove')->assertOk();
        $this->postJson("/api/salary-advances/{$line->id}/reopen")->assertOk();

        $this->assertSame('active', $line->fresh()->status);
        $this->assertSame(46.0, (float) $line->fresh()->remaining_balance);
        $this->assertSoftDeleted('salary_advances', ['id' => $newId]);
    }

    public function test_a_month_that_took_an_instalment_from_a_since_stopped_advance_cannot_be_reopened_until_the_stop_is_undone(): void
    {
        $line = $this->advance('sim', 48, 2);
        $this->approve(3);
        $this->postJson("/api/salary-advances/{$line->id}/stop", ['reason' => 'أُوقف الخط'])->assertOk();

        $this->postJson('/api/payroll/consolidated/2026/3/unapprove')->assertStatus(422)
            ->assertJsonFragment(['message' => "سلفة سائق أول (#{$line->id}) خُصم منها قسط في شهر 3/2026 ثم أُوقفت. تراجع عن ذلك من شاشة السلف أولاً، ثم فكّ الاعتماد."]);

        $this->postJson("/api/salary-advances/{$line->id}/reopen")->assertOk();
        $this->postJson('/api/payroll/consolidated/2026/3/unapprove')->assertOk();

        $line->refresh();
        $this->assertSame('active', $line->status);
        $this->assertSame(48.0, (float) $line->remaining_balance, 'back exactly where it started');
        $this->assertSame(0, (int) $line->paid_installments);
    }

    public function test_only_an_active_advance_with_something_left_is_stopped_or_moved(): void
    {
        $line = $this->advance('sim', 48, 2);
        $this->postJson("/api/salary-advances/{$line->id}/stop", ['reason' => 'x'])->assertOk();

        $this->postJson("/api/salary-advances/{$line->id}/stop", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/salary-advances/{$line->id}/transfer", ['employee_id' => $this->other->id, 'monthly_installment' => 2])->assertStatus(422);

        $phone = $this->advance('phone', 60, 15);
        $this->postJson("/api/salary-advances/{$phone->id}/transfer", ['employee_id' => $this->driver->id, 'monthly_installment' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');
        $this->postJson("/api/salary-advances/{$phone->id}/stop", [])->assertStatus(422)->assertJsonValidationErrors('reason');
    }
}
