<?php

namespace App\Services;

use App\Models\ConsolidatedPayrollDeduction;
use App\Models\CustodyItem;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\MaintenanceRecord;
use App\Models\SalaryAdvance;
use App\Models\Violation;

/**
 * Every deduction on every employee, and whether it has actually been taken.
 *
 * The six sources live in six tables and each screen shows only its own, so answering "who owes
 * what" meant six screens and a calculator. Two questions matter and they are different: what has
 * already come off a payslip (the ledger, written when a consolidated month is approved) and what
 * is still owed (a live read of the source tables).
 *
 * The pending side deliberately has no month bound. CompanyDeductionService answers "what is due
 * in this month" for payroll; a report answers "what is outstanding at all", which is why the two
 * do not share a query.
 */
class DeductionsReportService
{
    public const SOURCES = [
        ConsolidatedPayrollDeduction::SOURCE_VIOLATION => 'مخالفات مرورية',
        ConsolidatedPayrollDeduction::SOURCE_MAINTENANCE => 'صيانة بمسؤولية السائق',
        ConsolidatedPayrollDeduction::SOURCE_CUSTODY => 'عهدة تالفة أو مفقودة',
        ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE => 'مصاريف على السائق',
        ConsolidatedPayrollDeduction::SOURCE_LEAVE => 'إجازة بدون راتب',
        ConsolidatedPayrollDeduction::SOURCE_ADVANCE => 'أقساط السلف',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function build(int $companyId): array
    {
        $applied = self::appliedItems($companyId);
        $pending = self::pendingItems($companyId, $applied['keys']);

        $byEmployee = [];
        foreach ([...$applied['items'], ...$pending] as $item) {
            $id = $item['employee_id'];
            $byEmployee[$id] ??= ['items' => [], 'pending_total' => 0.0, 'applied_total' => 0.0];
            $byEmployee[$id]['items'][] = $item;
            $key = $item['status'] === 'applied' ? 'applied_total' : 'pending_total';
            $byEmployee[$id][$key] = round($byEmployee[$id][$key] + $item['amount'], 3);
        }

        if (empty($byEmployee)) {
            return ['sources' => self::SOURCES, 'employees' => [], 'totals' => self::emptyTotals()];
        }

        $employees = Employee::withoutGlobalScopes()->whereNull('deleted_at')
            ->whereIn('id', array_keys($byEmployee))
            ->get(['id', 'name', 'employee_number', 'status'])
            ->keyBy('id');

        $rows = [];
        foreach ($byEmployee as $id => $data) {
            $emp = $employees->get($id);
            if (! $emp) {
                continue;
            }

            // Newest first: the thing most likely to be queried is the thing most recently added.
            usort($data['items'], fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

            $bySource = [];
            foreach (self::SOURCES as $key => $_) {
                $bySource[$key] = [
                    'pending' => round(array_sum(array_map(
                        fn ($i) => $i['source_type'] === $key && $i['status'] === 'pending' ? $i['amount'] : 0,
                        $data['items']
                    )), 3),
                    'applied' => round(array_sum(array_map(
                        fn ($i) => $i['source_type'] === $key && $i['status'] === 'applied' ? $i['amount'] : 0,
                        $data['items']
                    )), 3),
                ];
            }

            $rows[] = [
                'employee_id' => (int) $emp->id,
                'employee_name' => $emp->name,
                'employee_number' => $emp->employee_number,
                'employee_status' => $emp->status,
                'pending_total' => $data['pending_total'],
                'applied_total' => $data['applied_total'],
                'items_count' => count($data['items']),
                'by_source' => $bySource,
                'items' => $data['items'],
            ];
        }

        // Whoever owes the most, first — that is the row an accountant is looking for.
        usort($rows, fn ($a, $b) => $b['pending_total'] <=> $a['pending_total']);

        $totals = self::emptyTotals();
        foreach ($rows as $r) {
            $totals['pending'] = round($totals['pending'] + $r['pending_total'], 3);
            $totals['applied'] = round($totals['applied'] + $r['applied_total'], 3);
            foreach (self::SOURCES as $key => $_) {
                $totals['by_source'][$key]['pending'] = round($totals['by_source'][$key]['pending'] + $r['by_source'][$key]['pending'], 3);
                $totals['by_source'][$key]['applied'] = round($totals['by_source'][$key]['applied'] + $r['by_source'][$key]['applied'], 3);
            }
        }
        $totals['employees'] = count($rows);
        $totals['employees_with_pending'] = count(array_filter($rows, fn ($r) => $r['pending_total'] > 0));

        return ['sources' => self::SOURCES, 'employees' => $rows, 'totals' => $totals];
    }

    /**
     * What has actually been charged, read from the ledger rather than inferred — the ledger is
     * the record of money taken, and it names the month it was taken in.
     *
     * @return array{items: array<int, array<string, mixed>>, keys: array<string, true>}
     */
    private static function appliedItems(int $companyId): array
    {
        $items = [];
        $keys = [];

        ConsolidatedPayrollDeduction::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with('run:id,year,month,approved_at')
            ->get()
            ->each(function ($d) use (&$items, &$keys) {
                if ($d->source_id !== null) {
                    $keys["{$d->source_type}:{$d->source_id}"] = true;
                }
                $items[] = [
                    'employee_id' => (int) $d->employee_id,
                    'source_type' => $d->source_type,
                    'source_id' => $d->source_id ? (int) $d->source_id : null,
                    'label' => $d->label,
                    'amount' => round((float) $d->amount, 3),
                    'date' => $d->run ? sprintf('%04d-%02d', $d->run->year, $d->run->month) : null,
                    'status' => 'applied',
                    'applied_in' => $d->run ? sprintf('%02d/%d', $d->run->month, $d->run->year) : null,
                ];
            });

        return ['items' => $items, 'keys' => $keys];
    }

    /**
     * What is still owed. A row already in the ledger, or already flagged on its own table by the
     * legacy payroll run, is excluded — a charge can only ever be collected once.
     *
     * @param  array<string, true>  $settled
     * @return array<int, array<string, mixed>>
     */
    private static function pendingItems(int $companyId, array $settled): array
    {
        $items = [];

        $add = function ($employeeId, $type, $sourceId, $amount, $label, $date) use (&$items, $settled) {
            $amount = round((float) $amount, 3);
            if ($amount <= 0 || ! $employeeId) {
                return;
            }
            if ($sourceId !== null && isset($settled["{$type}:{$sourceId}"])) {
                return;
            }
            $items[] = [
                'employee_id' => (int) $employeeId,
                'source_type' => $type,
                'source_id' => $sourceId ? (int) $sourceId : null,
                'label' => $label,
                'amount' => $amount,
                'date' => $date ? substr((string) $date, 0, 10) : null,
                'status' => 'pending',
                'applied_in' => null,
            ];
        };

        Violation::withoutGlobalScopes()->whereNull('deleted_at')->where('company_id', $companyId)
            ->where('is_deducted', false)->where('driver_deduction', '>', 0)
            ->get()
            ->each(fn ($v) => $add($v->employee_id, ConsolidatedPayrollDeduction::SOURCE_VIOLATION, $v->id,
                $v->driver_deduction,
                'مخالفة'.($v->reference_number ? " ({$v->reference_number})" : '').($v->violation_type ? " — {$v->violation_type}" : ''),
                $v->violation_date));

        MaintenanceRecord::withoutGlobalScopes()->whereNull('deleted_at')->where('company_id', $companyId)
            ->where('status', 'approved')->where('driver_deduction', '>', 0)
            ->whereNotNull('liable_employee_id')
            ->get()
            ->each(fn ($m) => $add($m->liable_employee_id, ConsolidatedPayrollDeduction::SOURCE_MAINTENANCE, $m->id,
                $m->driver_deduction,
                'صيانة'.($m->maintenance_type ? " ({$m->maintenance_type})" : ''),
                $m->maintenance_date));

        CustodyItem::withoutGlobalScopes()->whereNull('deleted_at')->where('company_id', $companyId)
            ->where('status', 'returned')->whereIn('return_condition', ['damaged', 'lost'])
            ->where('deduction_amount', '>', 0)
            ->get()
            ->each(fn ($c) => $add($c->employee_id, ConsolidatedPayrollDeduction::SOURCE_CUSTODY, $c->id,
                $c->deduction_amount,
                'عهدة '.($c->return_condition === 'lost' ? 'مفقودة' : 'تالفة').($c->item_description ? " ({$c->item_description})" : ''),
                $c->returned_date));

        DriverExpense::withoutGlobalScopes()->whereNull('deleted_at')->where('company_id', $companyId)
            ->where('is_deducted', false)->where('driver_amount', '>', 0)
            ->get()
            ->each(fn ($e) => $add($e->employee_id, ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE, $e->id,
                $e->driver_amount,
                'مصروف'.($e->expense_type ? " ({$e->expense_type})" : ''),
                $e->expense_date));

        EmployeeLeave::withoutGlobalScopes()->whereNull('deleted_at')->where('company_id', $companyId)
            ->where('status', 'approved')->where('is_paid', false)
            ->where('total_deduction', '>', 0)
            ->get()
            ->each(fn ($l) => $add($l->employee_id, ConsolidatedPayrollDeduction::SOURCE_LEAVE, $l->id,
                $l->total_deduction,
                'إجازة بدون راتب'.($l->days_count ? " ({$l->days_count} يوم)" : ''),
                $l->start_date));

        // An advance is outstanding for whatever is left on it, not for one month's instalment —
        // this report answers "what does this driver still owe", not "what comes off this month".
        SalaryAdvance::withoutGlobalScopes()->whereNull('deleted_at')->where('company_id', $companyId)
            ->where('status', 'active')->where('remaining_balance', '>', 0)
            ->get()
            ->each(fn ($a) => $add($a->employee_id, ConsolidatedPayrollDeduction::SOURCE_ADVANCE, null,
                $a->remaining_balance,
                'رصيد سلفة — قسط '.$a->monthly_installment.' د.ك شهرياً ('.(int) $a->paid_installments.' من '.(int) ($a->total_installments ?: 1).' مدفوع)',
                $a->advance_date));

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyTotals(): array
    {
        $bySource = [];
        foreach (self::SOURCES as $key => $_) {
            $bySource[$key] = ['pending' => 0.0, 'applied' => 0.0];
        }

        return ['pending' => 0.0, 'applied' => 0.0, 'employees' => 0, 'employees_with_pending' => 0, 'by_source' => $bySource];
    }
}
