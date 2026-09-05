<?php

namespace Tests\Feature;

use App\Models\SalaryAdvance;
use App\Services\CompanyDeductionService;
use Tests\TestCase;

/**
 * An instalment belongs to the act of closing a month, not to a square on the calendar.
 *
 * It used to be counted off the months elapsed since the advance was taken, which assumed every
 * month in between had been closed, in order. Months are closed in whatever order suits and some
 * are skipped, so that assumption cost real money: a skipped month had its instalment written off,
 * and once the count ran past the last scheduled instalment the advance stopped charging with a
 * live balance still on it — uncollectable, stuck 'active', and blocking that driver's deletion.
 *
 * Counting off the balance instead makes the order irrelevant.
 */
class AdvanceArrearsTest extends TestCase
{
    /** An unsaved advance is enough — the calculation reads its own columns and nothing else. */
    private function advance(float $amount, float $installment, string $date, ?float $remaining = null): SalaryAdvance
    {
        return new SalaryAdvance([
            'amount' => $amount,
            'monthly_installment' => $installment,
            'remaining_balance' => $remaining ?? $amount,
            'advance_date' => $date,
        ]);
    }

    private function due(SalaryAdvance $advance, int $year, int $month): float
    {
        return CompanyDeductionService::advanceInstallmentForMonth($advance, $year, $month);
    }

    /** Close a month against the advance, the way approval does. */
    private function collect(SalaryAdvance $advance, int $year, int $month): float
    {
        $due = $this->due($advance, $year, $month);
        $advance->remaining_balance = round((float) $advance->remaining_balance - $due, 3);

        return $due;
    }

    public function test_an_undisturbed_schedule_is_unchanged(): void
    {
        $advance = $this->advance(100, 25, '2026-01-01');

        $collected = 0.0;
        foreach ([1, 2, 3, 4] as $month) {
            $due = $this->collect($advance, 2026, $month);
            $this->assertSame(25.0, $due, "month {$month} should take a flat instalment");
            $collected += $due;
        }

        $this->assertSame(100.0, $collected);
        $this->assertSame(0.0, (float) $advance->remaining_balance);
    }

    public function test_a_skipped_month_is_not_forgiven_the_schedule_simply_runs_on(): void
    {
        $advance = $this->advance(100, 25, '2026-01-01');

        $this->assertSame(25.0, $this->collect($advance, 2026, 1));

        // February is never closed. Nothing is taken and nothing is lost.
        $this->assertSame(75.0, (float) $advance->remaining_balance);

        $this->assertSame(25.0, $this->collect($advance, 2026, 3));
        $this->assertSame(25.0, $this->collect($advance, 2026, 4));

        // The old rule stopped here — the fourth instalment fell past the schedule and 25.000 was
        // left on the advance for good. It now runs into May and finishes.
        $this->assertSame(25.0, $this->collect($advance, 2026, 5));
        $this->assertSame(0.0, (float) $advance->remaining_balance);
    }

    public function test_the_order_the_months_are_closed_in_does_not_change_the_total(): void
    {
        $advance = $this->advance(100, 25, '2026-01-01');

        // April first, then February, then January, then March.
        $collected = 0.0;
        foreach ([4, 2, 1, 3] as $month) {
            $collected += $this->collect($advance, 2026, $month);
        }

        $this->assertSame(100.0, $collected);
        $this->assertSame(0.0, (float) $advance->remaining_balance);
    }

    public function test_the_balance_is_never_over_collected(): void
    {
        // Three months of a four-month schedule already paid: only the last 25 is left.
        $advance = $this->advance(100, 25, '2026-01-01', 25.0);

        $this->assertSame(25.0, $this->collect($advance, 2026, 4));
        $this->assertSame(0.0, $this->due($advance, 2026, 5));
    }

    public function test_a_balance_outliving_its_schedule_is_still_collectable(): void
    {
        // The old rule returned 0.000 here for ever: the count had passed the last instalment, so
        // 40.000 sat on the advance permanently uncollectable.
        $advance = $this->advance(100, 25, '2026-01-01', 40.0);

        $this->assertSame(25.0, $this->collect($advance, 2026, 9));
        $this->assertSame(15.0, $this->collect($advance, 2026, 10));
        $this->assertSame(0.0, (float) $advance->remaining_balance);
    }

    public function test_nothing_is_due_before_the_advance_starts(): void
    {
        $advance = $this->advance(100, 25, '2026-03-01');

        $this->assertSame(0.0, $this->due($advance, 2026, 2));
        $this->assertSame(0.0, $this->due($advance, 2025, 12));
        $this->assertSame(25.0, $this->due($advance, 2026, 3));
    }

    public function test_a_single_instalment_advance_takes_the_whole_amount_at_once(): void
    {
        // The shape a settlement advance is created with.
        $advance = $this->advance(100, 100, '2026-04-01');

        $this->assertSame(0.0, $this->due($advance, 2026, 3));
        $this->assertSame(100.0, $this->collect($advance, 2026, 4));
        $this->assertSame(0.0, $this->due($advance, 2026, 5));
    }

    public function test_a_single_instalment_advance_survives_a_skipped_month(): void
    {
        $advance = $this->advance(100, 100, '2026-04-01');

        // April is never closed. May must still collect it in full.
        $this->assertSame(100.0, $this->collect($advance, 2026, 5));
    }

    public function test_the_start_date_check_spans_a_year_boundary(): void
    {
        $advance = $this->advance(60, 20, '2027-01-01');

        $this->assertSame(0.0, $this->due($advance, 2026, 12));
        $this->assertSame(20.0, $this->due($advance, 2027, 1));
    }

    /**
     * Nothing repays an advance until a month is approved, so on the statement the balance never
     * moves. Reading the instalment off that balance therefore put a full instalment in every open
     * month for ever — a 300.000 advance reading as 900.000 of debt across nine untouched months,
     * on the very screen an accountant opens to ask what a driver already owes.
     */
    public function test_the_statement_stops_at_the_end_of_the_schedule(): void
    {
        $advance = $this->advance(300, 100, '2026-07-01');
        $statement = fn (int $month) => CompanyDeductionService::advanceInstallmentForMonth($advance, 2026, $month, false);

        foreach ([7, 8, 9] as $month) {
            $this->assertSame(100.0, $statement($month), "month {$month} carries its own instalment");
        }

        // Nothing after the third, however many months stay open.
        foreach ([10, 11, 12] as $month) {
            $this->assertSame(0.0, $statement($month), "month {$month} is past the schedule");
        }
    }

    public function test_the_statement_never_exceeds_what_is_left(): void
    {
        // Two instalments already collected: only 100.000 of the 300.000 remains.
        $advance = $this->advance(300, 100, '2026-07-01', 100.0);

        $this->assertSame(100.0, CompanyDeductionService::advanceInstallmentForMonth($advance, 2026, 9, false));
    }

    public function test_collection_still_ignores_the_schedule_bound(): void
    {
        // The payroll side must keep catching up: a balance outliving its schedule is still owed.
        $advance = $this->advance(300, 100, '2026-07-01', 100.0);

        $this->assertSame(100.0, $this->due($advance, 2026, 12));
    }

    public function test_a_fully_paid_advance_charges_nothing(): void
    {
        $advance = $this->advance(100, 25, '2026-01-01', 0.0);

        $this->assertSame(0.0, $this->due($advance, 2026, 2));
    }
}
