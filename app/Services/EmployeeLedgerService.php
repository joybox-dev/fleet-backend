<?php

namespace App\Services;

use App\Models\ConsolidatedPayrollDeduction;
use App\Models\ConsolidatedPayrollRun;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\ContractPayrollAdjustment;
use App\Models\ContractPayrollRun;
use App\Models\CustodyItem;
use App\Models\DailyLog;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\MaintenanceRecord;
use App\Models\PayrollDisbursement;
use App\Models\SalaryAdvance;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\Violation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * One employee's month-by-month record: what they earned, what came off, and what was left.
 *
 * Deduction records live in six separate tables, so answering "what does this driver owe" meant
 * opening six screens and filtering each by name. Worse, the numbers on the employee balance
 * screen were computed with rules of their own — the whole fine instead of the driver's share,
 * the whole advance instead of the instalment — so they disagreed with what payroll actually
 * charged.
 *
 * A month that has been approved is read from its frozen snapshot: that is what was genuinely
 * paid, and re-deriving it later can only drift. A month still open is projected live, and says
 * so.
 */
class EmployeeLedgerService
{
    /** Source keys as they appear on a consolidated snapshot, in display order. */
    private const SOURCES = [
        'violations' => 'مخالفات مرورية',
        'maintenance' => 'صيانة بمسؤولية السائق',
        'custody' => 'عهدة تالفة أو مفقودة',
        'driver_expenses' => 'مصاريف على السائق',
        'advances' => 'أقساط السلف',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function history(Employee $employee, ?string $fromYm = null, ?string $toYm = null): array
    {
        $months = self::monthSpan($employee, $fromYm, $toYm);

        $approvedRuns = ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('status', 'approved')
            ->get()
            ->keyBy(fn ($r) => $r->year.'-'.$r->month);

        // What was actually charged, per month, straight from the ledger — the record of money
        // taken rather than a projection of money owed.
        $charged = ConsolidatedPayrollDeduction::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->with('run:id,year,month')
            ->get()
            ->groupBy(fn ($d) => $d->run ? $d->run->year.'-'.$d->run->month : 'unknown');

        // What was actually paid out, per month it was paid against. A month is owed until one
        // of these says otherwise.
        $paid = PayrollDisbursement::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->with(['run:id,year,month', 'createdBy:id,name'])
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();
        $paidByMonth = $paid->groupBy(fn ($p) => $p->run ? $p->run->year.'-'.$p->run->month : 'unknown');

        $rows = [];
        foreach ($months as [$year, $month]) {
            $key = $year.'-'.$month;
            $run = $approvedRuns->get($key);
            $snapshotRow = $run ? self::driverFromSnapshot($run, $employee->id) : null;

            $row = $snapshotRow
                ? self::fromSnapshot($year, $month, $snapshotRow, $charged->get($key), self::cashCollected(
                    $employee->id,
                    sprintf('%04d-%02d-01', $year, $month),
                    Carbon::create($year, $month, 1)->endOfMonth()->toDateString()
                ))
                : self::projected($employee, $year, $month);

            $paidRows = $paidByMonth->get($key, collect());
            $row['disbursements'] = $paidRows->map(fn (PayrollDisbursement $p) => $p->toRow())->values()->all();
            $row['disbursed_bank'] = round((float) $paidRows->sum('bank_amount'), 3);
            $row['disbursed_cash'] = round((float) $paidRows->sum('cash_amount'), 3);
            $row['disbursed_total'] = round($row['disbursed_bank'] + $row['disbursed_cash'], 3);

            $rows[] = $row;
        }

        usort($rows, fn ($a, $b) => [$b['year'], $b['month']] <=> [$a['year'], $a['month']]);

        // The approval sequence, which is what the balance actually follows. Falling back to the
        // calendar would make an out-of-turn approval rewrite a month that is already frozen.
        $approvalOrder = $approvedRuns->mapWithKeys(fn ($r) => [$r->year.'-'.$r->month => (int) $r->id])->all();

        [$rows, $settledBalance, $settledThrough] = self::withCarryForward($rows, $approvalOrder);

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_number' => $employee->employee_number,
            ],
            'sources' => self::SOURCES,
            'months' => $rows,
            'totals' => self::sumRows($rows),
            // What actually stands between the driver and the company today: every approved month
            // applied in the order it was approved, less every payment recorded against one. An
            // open month is a review copy and has taken nothing off him.
            'settled_balance' => $settledBalance,
            'settled_through' => $settledThrough,
            'unapproved_months' => collect($rows)->where('carries_forward', false)
                ->where('has_activity', true)->pluck('label')->values()->all(),
            'movements' => self::movements($employee, $approvedRuns, $paid, $fromYm, $toYm),
        ];
    }

    /**
     * The account as a sequence of dated movements rather than a stack of months: an approved
     * month enters on the day it was approved, a payment on the day it was made — whichever month
     * it settles. The owner's own requirement: a payment shows on the day it happened, not folded
     * into the month it belongs to, so the statement reads like a bank's.
     *
     * The running figure here and the month chain land on the same balance; they only differ in
     * the order the same entries are read.
     *
     * @param  Collection<string, ConsolidatedPayrollRun>  $approvedRuns
     * @param  Collection<int, PayrollDisbursement>  $paid
     * @return array<int, array<string, mixed>>
     */
    private static function movements(Employee $employee, $approvedRuns, $paid, ?string $fromYm, ?string $toYm): array
    {
        $entries = [];

        foreach ($approvedRuns as $run) {
            $driver = self::driverFromSnapshot($run, $employee->id);
            if (! $driver) {
                continue;
            }
            $label = sprintf('%02d/%d', $run->month, $run->year);
            $entries[] = [
                'date' => $run->approved_at?->toDateString(),
                'at' => $run->approved_at?->toDateTimeString(),
                'order' => 0,
                'kind' => 'month',
                'label' => "اعتماد كشف {$label} — صافي الشهر",
                'month_label' => $label,
                'amount' => round((float) ($driver['final_net_payout'] ?? 0), 3),
                'run_id' => (int) $run->id,
            ];
        }

        foreach ($paid as $p) {
            $label = $p->run ? sprintf('%02d/%d', $p->run->month, $p->run->year) : '—';
            $parts = [];
            if ((float) $p->bank_amount > 0) {
                $parts[] = 'تحويل بنكي '.number_format((float) $p->bank_amount, 3);
            }
            if ((float) $p->cash_amount > 0) {
                $parts[] = 'نقداً '.number_format((float) $p->cash_amount, 3);
            }
            $entries[] = [
                'date' => $p->paid_at?->toDateString(),
                'at' => $p->created_at?->toDateTimeString(),
                'order' => 1,
                'kind' => 'disbursement',
                'label' => "صرف راتب {$label} — ".implode(' + ', $parts),
                'month_label' => $label,
                'amount' => -$p->total(),
                'disbursement' => $p->toRow(),
            ];
        }

        usort($entries, fn ($a, $b) => [$a['date'], $a['order'], $a['at']] <=> [$b['date'], $b['order'], $b['at']]);

        $balance = 0.0;
        foreach ($entries as $i => $entry) {
            $balance = round($balance + $entry['amount'], 3);
            $entries[$i]['balance_after'] = $balance;
        }

        // The balance after each line is built over the whole account, then the window is cut —
        // a statement for one month still has to show where it started from.
        return array_values(array_filter($entries, function ($entry) use ($fromYm, $toYm) {
            $ym = substr((string) $entry['date'], 0, 7);

            return ! (($fromYm && $ym < $fromYm) || ($toYm && $ym > $toYm));
        }));
    }

    /**
     * The statement's spine: what each month opened with, and whether it hands anything on.
     *
     * A month does not carry because it has passed. It carries when its consolidated sheet is
     * approved — that is the act that applies the deductions and closes them to revision. Until
     * then a month's figures are a review copy: real enough to read and to correct, not settled
     * enough to charge the month after it. A fine entered by mistake into an open month must not
     * be able to follow the driver forward before anybody has confirmed it.
     *
     * So the balance advances across approved months only, and it advances in the order they were
     * APPROVED rather than the order they fall in the calendar. The owner closes months out of
     * turn and skips them freely: approving August and then July does not reopen August — July's
     * shortfall goes to whatever month is approved next. A calendar chain would rewrite a frozen
     * month every time an older one was closed.
     *
     * A month that is not approved shows the balance that actually stands today, and says plainly
     * that it adds nothing to it. That figure is never hidden: a carried balance the driver cannot
     * see on his own account is the failure this screen exists to prevent.
     *
     * What a month hands on is its net less what was paid against it. An approved month nobody
     * has paid stays owed to the driver in full; one that ended as a debt stays against him. The
     * rule itself lives in PayrollBalanceService so the consolidated sheet reads the same figure.
     *
     * @param  array<int, array<string, mixed>>  $rows  newest month first
     * @param  array<string, int>  $approvalOrder  "year-month" => the run's approval sequence
     * @return array{0: array<int, array<string, mixed>>, 1: float, 2: ?string}
     */
    private static function withCarryForward(array $rows, array $approvalOrder): array
    {
        $approvedKeys = [];
        foreach ($rows as $i => $row) {
            $key = $row['year'].'-'.$row['month'];
            if (($row['status'] ?? '') === 'approved' && isset($approvalOrder[$key])) {
                $approvedKeys[$i] = $approvalOrder[$key];
            }
        }
        asort($approvedKeys);

        $balance = 0.0;
        $from = null;

        foreach (array_keys($approvedKeys) as $i) {
            $rows[$i]['carried_in'] = round($balance, 3);
            $rows[$i]['carried_in_from'] = $from;
            $balance = round($balance + PayrollBalanceService::contribution(
                (float) $rows[$i]['net_payout'],
                (float) ($rows[$i]['disbursed_total'] ?? 0)
            ), 3);
            $rows[$i]['closing_balance'] = $balance;
            $rows[$i]['carries_forward'] = true;
            // Only an approved month can be where the balance turned: an open month has taken
            // nothing off him yet, so it cannot be the month he started owing.
            $rows[$i]['went_negative_here'] = $rows[$i]['carried_in'] >= 0 && $balance < 0;
            $from = $rows[$i]['label'];
        }

        foreach (array_keys($rows) as $i) {
            if (isset($approvedKeys[$i])) {
                continue;
            }
            $rows[$i]['carried_in'] = round($balance, 3);
            $rows[$i]['carried_in_from'] = $from;
            $rows[$i]['closing_balance'] = round($balance + (float) $rows[$i]['net_payout'], 3);
            $rows[$i]['carries_forward'] = false;
            $rows[$i]['went_negative_here'] = false;
        }

        return [$rows, $balance, $from];
    }

    /**
     * Every month the employee has anything on record — logged work, a fine, a leave, an advance
     * instalment, or an approved payroll month that named them.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    /**
     * Every month from the driver's first record to this one, with no gaps.
     *
     * A statement is read down a continuous column, so a month with nothing in it still has to
     * appear — it is where an unapproved balance sits waiting, and skipping it makes the balance
     * look as though it jumped. It runs to the current month rather than to the last month with
     * work in it, because the question the screen answers is where the driver stands NOW.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function monthSpan(Employee $employee, ?string $fromYm, ?string $toYm): array
    {
        $active = self::monthsWithActivity($employee);
        if (empty($active)) {
            return [];
        }

        usort($active, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $first = Carbon::create($active[0][0], $active[0][1], 1);
        $lastActive = $active[count($active) - 1];
        $last = Carbon::create($lastActive[0], $lastActive[1], 1)->max(Carbon::now()->startOfMonth());

        $rows = [];
        for ($cursor = $first->copy(); $cursor <= $last; $cursor->addMonthNoOverflow()) {
            $key = sprintf('%04d-%02d', $cursor->year, $cursor->month);
            if (($fromYm && $key < $fromYm) || ($toYm && $key > $toYm)) {
                continue;
            }
            $rows[] = [$cursor->year, $cursor->month];
        }

        return $rows;
    }

    /**
     * @return array<int, array{0: int, 1: int}>
     */
    private static function monthsWithActivity(Employee $employee): array
    {
        $keys = [];

        $add = function ($date) use (&$keys) {
            if (! $date) {
                return;
            }
            $c = $date instanceof Carbon ? $date : Carbon::parse((string) $date);
            $keys[$c->year.'-'.$c->month] = [$c->year, $c->month];
        };

        DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('employee_id', $employee->id)
            ->selectRaw('MIN(log_date) as first_log, MAX(log_date) as last_log')
            ->get()
            ->each(function ($r) use ($add) {
                $add($r->first_log);
                $add($r->last_log);
            });

        // Fill the span between the first and last log so an idle month is still listed. The dates
        // are reduced to months in PHP rather than by DATE_FORMAT, which is MySQL-only and left
        // this whole screen impossible to cover with the SQLite test suite.
        $logDates = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('employee_id', $employee->id)
            ->distinct()
            ->pluck('log_date');
        foreach ($logDates as $d) {
            $add($d);
        }

        foreach ([
            [Violation::class, 'employee_id', 'violation_date'],
            [CustodyItem::class, 'employee_id', 'returned_date'],
            [DriverExpense::class, 'employee_id', 'expense_date'],
            [SalaryAdvance::class, 'employee_id', 'advance_date'],
            [MaintenanceRecord::class, 'liable_employee_id', 'maintenance_date'],
        ] as [$model, $column, $dateColumn]) {
            $model::withoutGlobalScopes()->whereNull('deleted_at')
                ->where($column, $employee->id)
                ->pluck($dateColumn)
                ->each($add);
        }

        ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('status', 'approved')
            ->get(['year', 'month', 'snapshot_data'])
            ->each(function ($run) use (&$keys, $employee) {
                if (self::driverFromSnapshot($run, $employee->id)) {
                    $keys[$run->year.'-'.$run->month] = [$run->year, $run->month];
                }
            });

        return array_values($keys);
    }

    /** Cash collected by the driver in a month — reported beside the pay, never mixed into it. */
    private static function cashCollected(int $employeeId, string $start, string $end): float
    {
        return round((float) DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('employee_id', $employeeId)
            ->whereBetween('log_date', [$start, $end])
            ->sum('cash_collected'), 3);
    }

    /** @return array<string, mixed>|null */
    private static function driverFromSnapshot(ConsolidatedPayrollRun $run, int $employeeId): ?array
    {
        $snapshot = $run->snapshot_data;
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }
        foreach ($snapshot['drivers'] ?? [] as $driver) {
            if ((int) ($driver['employee_id'] ?? 0) === $employeeId) {
                return $driver;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $driver
     * @return array<string, mixed>
     */
    private static function fromSnapshot(int $year, int $month, array $driver, $chargedRows, float $cash = 0.0): array
    {
        $deductions = [];
        foreach (self::SOURCES as $key => $label) {
            $deductions[$key] = round((float) ($driver[$key.'_deduction'] ?? 0), 3);
        }

        return [
            'year' => $year,
            'month' => $month,
            'label' => sprintf('%02d/%d', $month, $year),
            'status' => 'approved',
            'source' => 'snapshot',
            'orders_count' => (int) ($driver['orders_count'] ?? 0),
            'work_days' => (int) ($driver['actual_work_days'] ?? 0),
            'cash_collected' => $cash,
            'contracts' => array_map(fn ($c) => [
                'contract_id' => $c['contract_id'] ?? null,
                'contract_name' => $c['contract_name'] ?? '',
                'payment_method_label' => $c['payment_method_label'] ?? ($c['payment_method'] ?? ''),
                'orders_count' => (int) ($c['orders_count'] ?? 0),
                'gross' => round((float) ($c['gross'] ?? 0), 3),
                'net' => round((float) ($c['net'] ?? 0), 3),
            ], $driver['contracts_worked'] ?? []),
            'gross_earnings' => round((float) ($driver['gross_contract_earnings'] ?? 0), 3),
            'manual_adjustments' => round((float) ($driver['manual_adjustments_total'] ?? 0), 3),
            // The settlements themselves, not just their total. An approved month listed its
            // contracts and its charges but not the bonus or write-off between them, so the lines
            // on the statement did not reach the month's own closing figure. The contract sheet
            // froze each one as a line of its own working; they are read back from there.
            'manual_adjustment_items' => self::adjustmentLinesFromSnapshot($driver),
            'deductions' => $deductions,
            'deductions_total' => round((float) ($driver['deductions_total'] ?? array_sum($deductions)), 3),
            'net_payout' => round((float) ($driver['final_net_payout'] ?? 0), 3),
            // Each charge as it was actually recorded, so a figure can be traced to its source row.
            'deduction_items' => collect($chargedRows ?? [])->map(fn ($d) => [
                'source_type' => $d->source_type,
                'source_id' => $d->source_id,
                'amount' => round((float) $d->amount, 3),
                'label' => $d->label,
            ])->values()->all(),
            'has_activity' => true,
        ];
    }

    /**
     * The manual settlements frozen inside an approved month, one per line, in the shape the open
     * month uses. The contract sheet appends each settlement to the driver's working as a line
     * labelled «زيادة / مكافأة يدوية (reason)» or «خصم يدوي (reason)», already signed, and the
     * consolidated snapshot keeps that working per contract.
     *
     * @param  array<string, mixed>  $driver  a driver row of a consolidated snapshot
     * @return array<int, array{amount: float, reason: string, contract_name: string}>
     */
    private static function adjustmentLinesFromSnapshot(array $driver): array
    {
        $items = [];

        foreach ($driver['contracts_worked'] ?? [] as $contract) {
            foreach ($contract['calculation_details'] ?? [] as $line) {
                $label = (string) ($line['label'] ?? '');
                if (! str_contains($label, 'يدوي')) {
                    continue;
                }
                // "خصم يدوي (السبب)" → "السبب"; the label is kept whole when it has no bracket.
                $reason = preg_match('/\((.*)\)\s*$/u', $label, $m) ? $m[1] : $label;
                $items[] = [
                    'amount' => round((float) ($line['amount'] ?? 0), 3),
                    'reason' => $reason,
                    'contract_name' => (string) ($contract['contract_name'] ?? ''),
                ];
            }
        }

        return $items;
    }

    /**
     * A month nobody has approved yet: earnings from whatever contract months are approved,
     * deductions as they currently stand. Marked so it is never mistaken for a settled figure.
     *
     * @return array<string, mixed>
     */
    private static function projected(Employee $employee, int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = Carbon::parse($start)->endOfMonth()->toDateString();

        $contracts = [];
        $gross = 0.0;

        // Which contract months are already frozen — those are read, the rest are computed.
        $approvedContractRuns = ContractPayrollRun::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('year', $year)->where('month', $month)
            ->where('status', 'approved')
            ->get()
            ->keyBy('contract_id');

        $assignments = ContractAssignment::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('start_date', '<=', $end)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start))
            ->with(['contract', 'overrides'])
            ->get();

        foreach ($assignments as $assignment) {
            $contract = $assignment->contract;
            if (! $contract) {
                continue;
            }

            $frozen = $approvedContractRuns->get($contract->id);
            if ($frozen) {
                $snapshot = is_string($frozen->snapshot_data) ? json_decode($frozen->snapshot_data, true) : $frozen->snapshot_data;
                $inSnapshot = false;

                foreach ($snapshot['drivers'] ?? [] as $d) {
                    if ((int) ($d['employee_id'] ?? 0) !== $employee->id) {
                        continue;
                    }
                    $inSnapshot = true;
                    $g = round((float) ($d['gross_contract_earnings'] ?? 0), 3);
                    $gross += $g;
                    $contracts[] = [
                        'contract_id' => $contract->id,
                        'contract_name' => $contract->name,
                        'payment_method_label' => $d['payment_method_label'] ?? '',
                        'orders_count' => (int) ($d['orders_count'] ?? 0),
                        'work_days' => (int) ($d['actual_work_days'] ?? 0),
                        'gross' => $g,
                        'net' => round((float) ($d['net_payout'] ?? $g), 3),
                        'frozen' => true,
                        // The working as it was frozen. A month whose sheet is closed still has to
                        // explain its own figure, otherwise the oldest months — the ones nobody
                        // remembers — are the only ones the reader cannot check.
                        //
                        // The contract sheet appends the month's manual settlements to this list.
                        // The statement gives them a line of their own, so they are dropped here
                        // rather than shown twice under two different headings.
                        'calculation' => array_values(array_map(fn ($line) => [
                            'label' => $line['label'] ?? '',
                            'formula' => $line['formula'] ?? '',
                            'amount' => round((float) ($line['amount'] ?? 0), 3),
                            'is_unpriced' => (bool) ($line['is_unpriced'] ?? false),
                        ], array_filter(
                            $d['calculation_details'] ?? [],
                            fn ($line) => ! str_contains((string) ($line['label'] ?? ''), 'يدوي')
                        ))),
                    ];
                }

                // He worked this contract, but the month was frozen before he was on it — a driver
                // added after approval, or moved onto the contract later. His pay is not in the
                // snapshot and re-deriving it would contradict what was actually approved, so the
                // row says so rather than showing him earning nothing while his charges still count.
                if (! $inSnapshot) {
                    $contracts[] = [
                        'contract_id' => $contract->id,
                        'contract_name' => $contract->name,
                        'payment_method_label' => '',
                        'orders_count' => 0,
                        'gross' => 0.0,
                        'net' => 0.0,
                        'frozen' => true,
                        'missing_from_snapshot' => true,
                    ];
                }

                continue;
            }

            $result = self::projectContractMonth($employee, $contract, $assignment, $start, $end);
            if ($result === null) {
                continue;
            }
            $gross += $result['gross'];
            $contracts[] = $result;
        }

        // A month row on this screen is a period, not a bill: it carries the charges that arose
        // in it, so the column can be summed. What payroll would collect if this month were
        // closed is a different number and belongs to the payroll screens.
        $pending = CompanyDeductionService::pendingFor([$employee->id], $start, $end, $year, $month, true);
        $items = $pending[$employee->id]['items'] ?? [];

        $deductions = array_fill_keys(array_keys(self::SOURCES), 0.0);
        foreach ($items as $item) {
            $key = self::sourceKey($item['source_type']);
            if ($key !== null) {
                $deductions[$key] = round($deductions[$key] + (float) $item['amount'], 3);
            }
        }

        $logs = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('employee_id', $employee->id)
            ->whereBetween('log_date', [$start, $end])
            // log_date is selected because the month is counted over dates, not rows.
            ->get(['log_date', 'orders_count', 'driver_status', 'cash_collected']);

        // Settlements agreed on a contract for this month — a bonus added, an amount written off.
        // The statement was leaving them out of an open month altogether, so its net disagreed
        // with the contract sheet by exactly the settlement, on every driver who had one. They are
        // stored signed by type, not by sign, so the direction is applied here.
        $adjustmentRows = ContractPayrollAdjustment::where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('year', $year)->where('month', $month)
            ->with('contract:id,name')
            ->get();

        $adjustments = 0.0;
        $adjustmentItems = [];
        foreach ($adjustmentRows as $row) {
            $signed = round(((string) $row->type === 'addition' ? 1 : -1) * (float) $row->amount, 3);
            $adjustments = round($adjustments + $signed, 3);
            $adjustmentItems[] = [
                'amount' => $signed,
                'reason' => $row->reason,
                'contract_name' => $row->contract?->name ?? '',
            ];
        }

        return [
            'year' => $year,
            'month' => $month,
            'label' => sprintf('%02d/%d', $month, $year),
            'status' => 'open',
            'source' => 'projection',
            'orders_count' => (int) $logs->sum('orders_count'),
            'cash_collected' => self::cashCollected($employee->id, $start, $end),
            // Counted over the DATES, not the rows: a driver on five contracts has a row per
            // contract per day, and counting rows made his August 143 days long.
            'work_days' => $logs->filter(fn ($l) => $l->driver_status === 'working'
                || $l->driver_status === 'paid_leave'
                || (int) $l->orders_count > 0)
                ->pluck('log_date')
                ->map(fn ($d) => substr((string) $d, 0, 10))
                ->unique()
                ->count(),
            'contracts' => $contracts,
            'gross_earnings' => round($gross, 3),
            // Surfaced at month level so the row can say why the earnings read low.
            'missing_from_snapshot' => (bool) array_filter($contracts, fn ($c) => $c['missing_from_snapshot'] ?? false),
            'manual_adjustments' => $adjustments,
            'manual_adjustment_items' => $adjustmentItems,
            'deductions' => $deductions,
            'deductions_total' => round(array_sum($deductions), 3),
            // What the month leaves him if it were approved as it stands. This used to report the
            // earnings untouched, on the reasoning that nothing is taken until approval — but a
            // column headed الصافي that equals الأرباح answers no question, and the totals row then
            // claimed a net of 92.500 beside deductions of 138.000 for the same driver.
            'net_payout' => round($gross + $adjustments - array_sum($deductions), 3),
            // Every charge as its own line, so the month can be read rather than trusted. A
            // total nobody can take apart is a number the owner has to believe.
            'deduction_items' => array_map(fn ($item) => [
                'source_type' => $item['source_type'],
                'source_id' => $item['source_id'] ?? null,
                'amount' => round((float) $item['amount'], 3),
                'label' => $item['label'] ?? '',
            ], $items),
            'has_activity' => $logs->isNotEmpty() || $items !== [] || $adjustmentRows->isNotEmpty(),
        ];
    }

    /**
     * One contract's month for one driver, under the same rules the contract sheet applies:
     * only days inside the assignment window count, the vehicle type comes from every vehicle
     * driven that month (and is refused when there is more than one), and an override prices
     * only the days its own window covers.
     *
     * @return array<string, mixed>|null
     */
    private static function projectContractMonth(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        string $start,
        string $end
    ): ?array {
        $effStart = max($start, substr((string) $assignment->start_date, 0, 10));
        $effEnd = $assignment->end_date ? min($end, substr((string) $assignment->end_date, 0, 10)) : $end;
        if ($effStart > $effEnd) {
            return null;
        }

        $logs = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('employee_id', $employee->id)
            ->where('contract_id', $contract->id)
            ->whereBetween('log_date', [$effStart, $effEnd])
            ->get();

        if ($logs->isEmpty()) {
            return null;
        }

        $vehicleTypeById = Vehicle::withoutGlobalScopes()
            ->whereIn('id', $logs->pluck('vehicle_id')->filter()->unique()->all())
            ->pluck('vehicle_type_id', 'id');
        $vehicleTypeNames = VehicleType::withoutGlobalScopes()
            ->whereIn('id', $vehicleTypeById->values()->filter()->unique()->values())
            ->get()
            ->mapWithKeys(fn ($t) => [(int) $t->id => ($t->name_ar ?: $t->name)]);

        // Priced by the very routine the contract sheet uses — by override window and by vehicle
        // type driven, with the contract's working-day cap spent across the stretches — so an open
        // month here cannot disagree with the sheet it will one day be frozen from. This used to
        // carry its own copy of that split, minus the cap.
        $priced = ContractSheetService::priceDriverMonth(
            $employee,
            $contract,
            $assignment,
            $logs,
            $assignment->overrides ?: collect(),
            $vehicleTypeById,
            $vehicleTypeNames,
            $effStart
        );
        $calc = $priced['calc'];
        $gross = (float) ($calc['gross_contract_earnings'] ?? 0);
        $orders = (int) ($calc['orders_count'] ?? 0);

        // Why the salary is the number it is: the engine already explains itself line by line —
        // the base for the days worked, a target missed, a bonus earned, orders left unpriced.
        // The statement shows that working rather than a total the reader has to take on trust.
        $lines = [];
        foreach ($calc['calculation_details'] ?? [] as $line) {
            $lines[] = [
                'label' => $line['label'] ?? '',
                'formula' => $line['formula'] ?? '',
                'amount' => round((float) ($line['amount'] ?? 0), 3),
                'is_unpriced' => (bool) ($line['is_unpriced'] ?? false),
            ];
        }

        $vtIds = $priced['vehicle_type_ids'];
        $method = $priced['active_override']?->override_type
            ?? (count($vtIds) === 1 ? ($contract->driver_pricing_rules[$vtIds[0]]['payment_method'] ?? null) : null);
        $label = $method ? ContractPayrollService::paymentMethodLabel($method) : '';

        return [
            'contract_id' => $contract->id,
            'contract_name' => $contract->name,
            'payment_method_label' => $label,
            'orders_count' => $orders,
            // The days this contract pays for, not every row it holds — an untouched
            // unpaid-leave day is not a day worked on it.
            'work_days' => $logs->filter(fn ($l) => $l->driver_status === 'working'
                || $l->driver_status === 'paid_leave'
                || (int) $l->orders_count > 0)->count(),
            'gross' => round($gross, 3),
            'net' => round($gross, 3),
            'frozen' => false,
            'calculation' => $lines,
        ];
    }

    private static function sourceKey(string $sourceType): ?string
    {
        return match ($sourceType) {
            ConsolidatedPayrollDeduction::SOURCE_VIOLATION => 'violations',
            ConsolidatedPayrollDeduction::SOURCE_MAINTENANCE => 'maintenance',
            ConsolidatedPayrollDeduction::SOURCE_CUSTODY => 'custody',
            ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE => 'driver_expenses',
            ConsolidatedPayrollDeduction::SOURCE_ADVANCE => 'advances',
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function sumRows(array $rows): array
    {
        $deductions = array_fill_keys(array_keys(self::SOURCES), 0.0);
        $gross = 0.0;
        $net = 0.0;
        $orders = 0;
        $days = 0;
        $cash = 0.0;
        $disbursed = 0.0;

        foreach ($rows as $row) {
            $cash += $row['cash_collected'] ?? 0;
            $disbursed += $row['disbursed_total'] ?? 0;
            $gross += $row['gross_earnings'];
            $net += $row['net_payout'];
            $orders += $row['orders_count'];
            $days += $row['work_days'];
            foreach ($deductions as $key => $_) {
                $deductions[$key] = round($deductions[$key] + ($row['deductions'][$key] ?? 0), 3);
            }
        }

        return [
            // Months he actually has a record in — the statement also lists the empty ones between
            // them, and counting those would overstate how long he has been on the books.
            'months' => count(array_filter($rows, fn ($r) => $r['has_activity'] ?? true)),
            'orders_count' => $orders,
            'work_days' => $days,
            'gross_earnings' => round($gross, 3),
            'cash_collected' => round($cash, 3),
            'deductions' => $deductions,
            'deductions_total' => round(array_sum($deductions), 3),
            'net_payout' => round($net, 3),
            'disbursed_total' => round($disbursed, 3),
        ];
    }
}
