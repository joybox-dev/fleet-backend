<?php

namespace App\Services;

use App\Models\ConsolidatedPayrollDeduction;
use App\Models\ConsolidatedPayrollRun;
use App\Models\ContractPayrollRun;
use App\Models\DailyLog;
use App\Models\Employee;
use Carbon\Carbon;

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
        'leaves' => 'إجازة بدون راتب',
        'advances' => 'أقساط السلف',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function history(Employee $employee, ?string $fromYm = null, ?string $toYm = null): array
    {
        $months = self::monthsWithActivity($employee, $fromYm, $toYm);

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

        $rows = [];
        foreach ($months as [$year, $month]) {
            $key = $year.'-'.$month;
            $run = $approvedRuns->get($key);
            $snapshotRow = $run ? self::driverFromSnapshot($run, $employee->id) : null;

            $rows[] = $snapshotRow
                ? self::fromSnapshot($year, $month, $snapshotRow, $charged->get($key), self::cashCollected(
                    $employee->id,
                    sprintf('%04d-%02d-01', $year, $month),
                    Carbon::create($year, $month, 1)->endOfMonth()->toDateString()
                ))
                : self::projected($employee, $year, $month);
        }

        usort($rows, fn ($a, $b) => [$b['year'], $b['month']] <=> [$a['year'], $a['month']]);

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_number' => $employee->employee_number,
            ],
            'sources' => self::SOURCES,
            'months' => $rows,
            'totals' => self::sumRows($rows),
        ];
    }

    /**
     * Every month the employee has anything on record — logged work, a fine, a leave, an advance
     * instalment, or an approved payroll month that named them.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function monthsWithActivity(Employee $employee, ?string $fromYm, ?string $toYm): array
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

        // Fill the span between the first and last log so an idle month is still listed.
        $logDates = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('employee_id', $employee->id)
            ->selectRaw("DISTINCT DATE_FORMAT(log_date, '%Y-%m-01') as ym")
            ->pluck('ym');
        foreach ($logDates as $d) {
            $add($d);
        }

        foreach ([
            [\App\Models\Violation::class, 'employee_id', 'violation_date'],
            [\App\Models\CustodyItem::class, 'employee_id', 'returned_date'],
            [\App\Models\DriverExpense::class, 'employee_id', 'expense_date'],
            [\App\Models\EmployeeLeave::class, 'employee_id', 'start_date'],
            [\App\Models\SalaryAdvance::class, 'employee_id', 'advance_date'],
            [\App\Models\MaintenanceRecord::class, 'liable_employee_id', 'maintenance_date'],
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

        $rows = array_values($keys);

        if ($fromYm || $toYm) {
            $rows = array_values(array_filter($rows, function ($ym) use ($fromYm, $toYm) {
                $key = sprintf('%04d-%02d', $ym[0], $ym[1]);

                return (! $fromYm || $key >= $fromYm) && (! $toYm || $key <= $toYm);
            }));
        }

        return $rows;
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
            'deductions' => $deductions,
            'deductions_total' => round((float) ($driver['deductions_total'] ?? array_sum($deductions)), 3),
            'net_payout' => round((float) ($driver['final_net_payout'] ?? 0), 3),
            // Each charge as it was actually recorded, so a figure can be traced to its source row.
            'charged_items' => collect($chargedRows ?? [])->map(fn ($d) => [
                'source_type' => $d->source_type,
                'source_id' => $d->source_id,
                'amount' => round((float) $d->amount, 3),
                'label' => $d->label,
            ])->values()->all(),
        ];
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

        $assignments = \App\Models\ContractAssignment::withoutGlobalScopes()
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
                foreach ($snapshot['drivers'] ?? [] as $d) {
                    if ((int) ($d['employee_id'] ?? 0) !== $employee->id) {
                        continue;
                    }
                    $g = round((float) ($d['gross_contract_earnings'] ?? 0), 3);
                    $gross += $g;
                    $contracts[] = [
                        'contract_id' => $contract->id,
                        'contract_name' => $contract->name,
                        'payment_method_label' => $d['payment_method_label'] ?? '',
                        'orders_count' => (int) ($d['orders_count'] ?? 0),
                        'gross' => $g,
                        'net' => round((float) ($d['net_payout'] ?? $g), 3),
                        'frozen' => true,
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

        $pending = CompanyDeductionService::pendingFor([$employee->id], $start, $end, $year, $month);
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
            ->get(['orders_count', 'driver_status', 'cash_collected']);

        return [
            'year' => $year,
            'month' => $month,
            'label' => sprintf('%02d/%d', $month, $year),
            'status' => 'open',
            'source' => 'projection',
            'orders_count' => (int) $logs->sum('orders_count'),
            'cash_collected' => self::cashCollected($employee->id, $start, $end),
            'work_days' => $logs->filter(fn ($l) => $l->driver_status === 'working'
                || $l->driver_status === 'paid_leave'
                || (int) $l->orders_count > 0)->count(),
            'contracts' => $contracts,
            'gross_earnings' => round($gross, 3),
            'manual_adjustments' => 0.0,
            'deductions' => $deductions,
            'deductions_total' => round(array_sum($deductions), 3),
            // Nothing is taken until the consolidated month is approved, so the net still carries
            // the full earnings and the deductions sit beside it as owed.
            'net_payout' => round($gross, 3),
            'charged_items' => [],
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
        \App\Models\Contract $contract,
        \App\Models\ContractAssignment $assignment,
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

        $vtIds = $logs->pluck('vehicle_id')->filter()->unique()
            ->map(fn ($vid) => optional(\App\Models\Vehicle::withoutGlobalScopes()->find($vid))->vehicle_type_id)
            ->filter()->unique()->values();
        $vtId = $vtIds->count() === 1 ? (int) $vtIds->first() : null;

        $overrides = $assignment->overrides ?: collect();
        $segments = [];
        foreach ($logs as $logRow) {
            $date = substr((string) $logRow->log_date, 0, 10);
            $override = $overrides->first(function ($ov) use ($date) {
                $from = $ov->effective_from ? substr((string) $ov->effective_from, 0, 10) : null;
                $to = $ov->effective_to ? substr((string) $ov->effective_to, 0, 10) : null;

                return (! $from || $from <= $date) && (! $to || $to >= $date);
            });
            $key = $override ? 'ov:'.$override->id : 'base';
            $segments[$key] ??= ['override' => $override, 'logs' => collect()];
            $segments[$key]['logs']->push($logRow);
        }

        $gross = 0.0;
        $orders = 0;
        $label = '';
        foreach ($segments as $segment) {
            $calc = ContractPayrollService::calculateDriverContractPayroll(
                $employee, $contract, $assignment, $segment['override'], $segment['logs'], $vtId
            );
            $gross += (float) ($calc['gross_contract_earnings'] ?? 0);
            $orders += (int) ($calc['orders_count'] ?? 0);
            if ($label === '') {
                $method = $segment['override']?->override_type
                    ?? ($contract->driver_pricing_rules[$vtId]['payment_method'] ?? null);
                $label = $method ? \App\Http\Controllers\Api\PayrollController::getPaymentMethodLabel($method) : '';
            }
        }

        return [
            'contract_id' => $contract->id,
            'contract_name' => $contract->name,
            'payment_method_label' => $label,
            'orders_count' => $orders,
            'gross' => round($gross, 3),
            'net' => round($gross, 3),
            'frozen' => false,
        ];
    }

    private static function sourceKey(string $sourceType): ?string
    {
        return match ($sourceType) {
            ConsolidatedPayrollDeduction::SOURCE_VIOLATION => 'violations',
            ConsolidatedPayrollDeduction::SOURCE_MAINTENANCE => 'maintenance',
            ConsolidatedPayrollDeduction::SOURCE_CUSTODY => 'custody',
            ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE => 'driver_expenses',
            ConsolidatedPayrollDeduction::SOURCE_LEAVE => 'leaves',
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

        foreach ($rows as $row) {
            $cash += $row['cash_collected'] ?? 0;
            $gross += $row['gross_earnings'];
            $net += $row['net_payout'];
            $orders += $row['orders_count'];
            $days += $row['work_days'];
            foreach ($deductions as $key => $_) {
                $deductions[$key] = round($deductions[$key] + ($row['deductions'][$key] ?? 0), 3);
            }
        }

        return [
            'months' => count($rows),
            'orders_count' => $orders,
            'work_days' => $days,
            'gross_earnings' => round($gross, 3),
            'cash_collected' => round($cash, 3),
            'deductions' => $deductions,
            'deductions_total' => round(array_sum($deductions), 3),
            'net_payout' => round($net, 3),
        ];
    }
}
