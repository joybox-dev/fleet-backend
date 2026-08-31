<?php

namespace App\Services;

use App\Models\ConsolidatedPayrollDeduction;
use App\Models\CustodyItem;
use App\Models\DriverExpense;
use App\Models\EmployeeLeave;
use App\Models\MaintenanceRecord;
use App\Models\SalaryAdvance;
use App\Models\Violation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves every company-level deduction owed by a set of employees for one payroll month.
 *
 * These are charges against the person, not against a contract, so they are settled once on
 * the consolidated sheet rather than on each contract a driver worked. Anything already
 * recorded in the deduction ledger — or flagged on its own table by the legacy payroll run —
 * is excluded, so a charge can only ever be collected once.
 */
class CompanyDeductionService
{
    /**
     * Payroll and a month-by-month history ask different questions of the same rows.
     *
     * Payroll asks what is collectable when this month is closed, and a charge that was never
     * collected is still collectable — so maintenance, custody and expenses stay open-ended above
     * their month, and the ledger is what stops them being charged twice.
     *
     * A history asks which month a charge arose in, and has to answer with exactly one month:
     * its rows get summed, so an open-ended charge is counted once per open month. A single
     * 20.000 KWD expense dated 09/07 was reported in July, August and September at once, and the
     * employee's financial-account tab totalled it to 40.000 and climbing.
     *
     * Same rules, same exclusions, same ledger check either way; the only difference is a lower
     * bound on the three cumulative debts.
     *
     * @param  array<int>  $employeeIds
     * @param  bool  $originatedInMonthOnly  History mode. Never set by payroll.
     * @return array<int, array{items: array<int, array{source_type: string, source_id: ?int, amount: float, label: string}>, total: float}>
     */
    public static function pendingFor(array $employeeIds, string $startDate, string $endDate, int $year, int $month, bool $originatedInMonthOnly = false): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $settled = self::settledSourceKeys();
        $result = [];

        $add = function (int $employeeId, string $type, ?int $sourceId, float $amount, string $label) use (&$result, $settled) {
            if ($amount <= 0) {
                return;
            }
            if ($sourceId !== null && isset($settled["{$type}:{$sourceId}"])) {
                return;
            }
            $result[$employeeId] ??= ['items' => [], 'total' => 0.0];
            $result[$employeeId]['items'][] = [
                'source_type' => $type,
                'source_id' => $sourceId,
                'amount' => round($amount, 3),
                'label' => $label,
            ];
            $result[$employeeId]['total'] = round($result[$employeeId]['total'] + $amount, 3);
        };

        // Traffic fines — the driver's share only; a company-liable fine stores 0.
        Violation::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('violation_date', [$startDate, $endDate])
            ->where('is_deducted', false)
            ->where('driver_deduction', '>', 0)
            ->get()
            ->each(fn ($v) => $add(
                (int) $v->employee_id,
                ConsolidatedPayrollDeduction::SOURCE_VIOLATION,
                (int) $v->id,
                (float) $v->driver_deduction,
                'مخالفة مرورية'.($v->reference_number ? " ({$v->reference_number})" : '')
            ));

        // Driver-liable maintenance. Cumulative up to the month end, because a repair approved
        // in an earlier month may never have been collected — the ledger is what stops it being
        // charged again once it has.
        MaintenanceRecord::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('liable_employee_id', $employeeIds)
            ->where('status', 'approved')
            ->where('driver_deduction', '>', 0)
            ->when(
                $originatedInMonthOnly,
                fn ($q) => $q->whereBetween('maintenance_date', [$startDate, $endDate]),
                fn ($q) => $q->whereDate('maintenance_date', '<=', $endDate)
            )
            ->get()
            ->each(fn ($m) => $add(
                (int) $m->liable_employee_id,
                ConsolidatedPayrollDeduction::SOURCE_MAINTENANCE,
                (int) $m->id,
                (float) $m->driver_deduction,
                'صيانة بمسؤولية السائق'.($m->maintenance_type ? " ({$m->maintenance_type})" : '')
            ));

        // Custody returned damaged or lost.
        CustodyItem::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'returned')
            ->whereIn('return_condition', ['damaged', 'lost'])
            ->where('deduction_amount', '>', 0)
            ->when(
                $originatedInMonthOnly,
                fn ($q) => $q->whereBetween('returned_date', [$startDate, $endDate]),
                fn ($q) => $q->whereDate('returned_date', '<=', $endDate)
            )
            ->get()
            ->each(fn ($c) => $add(
                (int) $c->employee_id,
                ConsolidatedPayrollDeduction::SOURCE_CUSTODY,
                (int) $c->id,
                (float) $c->deduction_amount,
                'عهدة '.($c->return_condition === 'lost' ? 'مفقودة' : 'تالفة').($c->item_description ? " ({$c->item_description})" : '')
            ));

        // Expenses the driver bears.
        DriverExpense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $employeeIds)
            ->where('driver_amount', '>', 0)
            ->where('is_deducted', false)
            ->when(
                $originatedInMonthOnly,
                fn ($q) => $q->whereBetween('expense_date', [$startDate, $endDate]),
                fn ($q) => $q->whereDate('expense_date', '<=', $endDate)
            )
            ->get()
            ->each(fn ($e) => $add(
                (int) $e->employee_id,
                ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE,
                (int) $e->id,
                (float) $e->driver_amount,
                'مصروف على السائق'.($e->expense_type ? " ({$e->expense_type})" : '')
            ));

        // Approved unpaid leave overlapping this month. Naturally month-scoped, so it cannot
        // repeat across months the way a cumulative charge can.
        EmployeeLeave::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->where('is_paid', false)
            ->where('total_deduction', '>', 0)
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($q2) use ($startDate, $endDate) {
                        $q2->where('start_date', '<=', $startDate)->where('end_date', '>=', $endDate);
                    });
            })
            ->get()
            ->each(fn ($l) => $add(
                (int) $l->employee_id,
                ConsolidatedPayrollDeduction::SOURCE_LEAVE,
                (int) $l->id,
                (float) $l->total_deduction,
                'إجازة بدون راتب'.($l->days_count ? " ({$l->days_count} يوم)" : '')
            ));

        // Salary advance instalments due this month.
        SalaryAdvance::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'active')
            ->whereDate('advance_date', '<=', $endDate)
            ->get()
            ->each(function ($advance) use ($add, $year, $month, $settled) {
                if (isset($settled['advance:'.$advance->id.":{$year}-{$month}"])) {
                    return;
                }
                $due = self::advanceInstallmentForMonth($advance, $year, $month);
                $due = min($due, (float) $advance->remaining_balance);
                if ($due <= 0) {
                    return;
                }
                $index = self::advanceInstallmentIndex($advance, $year, $month);
                $add(
                    (int) $advance->employee_id,
                    ConsolidatedPayrollDeduction::SOURCE_ADVANCE,
                    (int) $advance->id,
                    $due,
                    "قسط سلفة {$index} من ".(int) ($advance->total_installments ?: 1)
                );
            });

        return $result;
    }

    /**
     * Source keys already recorded in the ledger. Advances are keyed per month because the
     * same advance is legitimately collected again in the following month.
     *
     * @return array<string, true>
     */
    private static function settledSourceKeys(): array
    {
        $keys = [];
        ConsolidatedPayrollDeduction::withoutGlobalScopes()
            ->with('run:id,year,month')
            ->get(['id', 'consolidated_run_id', 'source_type', 'source_id'])
            ->each(function ($row) use (&$keys) {
                if ($row->source_id === null) {
                    return;
                }
                if ($row->source_type === ConsolidatedPayrollDeduction::SOURCE_ADVANCE) {
                    $run = $row->run;
                    if ($run) {
                        $keys["advance:{$row->source_id}:{$run->year}-{$run->month}"] = true;
                    }

                    return;
                }
                $keys["{$row->source_type}:{$row->source_id}"] = true;
            });

        return $keys;
    }

    /** 1-based index of the instalment falling in the requested month. */
    public static function advanceInstallmentIndex(SalaryAdvance $advance, int $year, int $month): int
    {
        $start = Carbon::parse($advance->advance_date);

        return (($year * 12) + $month) - (($start->year * 12) + $start->month) + 1;
    }

    /**
     * How much of an advance falls due in a given month, derived from its own schedule so the
     * projection is identical whether or not the month has been approved yet.
     */
    public static function advanceInstallmentForMonth(SalaryAdvance $advance, int $year, int $month): float
    {
        $amount = (float) $advance->amount;
        $installment = (float) $advance->monthly_installment;

        if ($amount <= 0 || $installment <= 0 || ! $advance->advance_date) {
            return 0.0;
        }

        $index = self::advanceInstallmentIndex($advance, $year, $month);
        if ($index < 1) {
            return 0.0;
        }

        $totalInstallments = (int) ($advance->total_installments ?: ceil($amount / $installment));
        if ($totalInstallments > 0 && $index > $totalInstallments) {
            return 0.0;
        }

        $due = min($installment, $amount - (($index - 1) * $installment));

        return $due > 0 ? round($due, 3) : 0.0;
    }

    /** Group a driver's pending items by source type, for display. */
    public static function groupByType(array $items): Collection
    {
        return collect($items)->groupBy('source_type')->map(fn ($group) => [
            'total' => round($group->sum('amount'), 3),
            'items' => $group->values()->all(),
        ]);
    }
}
