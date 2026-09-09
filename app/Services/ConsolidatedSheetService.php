<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ConsolidatedPayrollDeduction;
use App\Models\ConsolidatedPayrollRun;
use App\Models\Contract;
use App\Models\ContractPayrollRun;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\PayrollDeductionOverride;
use App\Models\PayrollDisbursement;
use Carbon\Carbon;

/**
 * The company-wide payroll sheet for one month, built strictly from the APPROVED contract sheets.
 *
 * Every charge that belongs to the person rather than to a contract — fines, driver-liable
 * maintenance, custody, driver-borne expenses, unpaid leave, advance instalments — is resolved once
 * per employee here, so a driver on several contracts is charged once. Until the month is approved
 * those charges are projected, never applied.
 */
class ConsolidatedSheetService
{
    /**
     * @return array<string, mixed>
     */
    public static function build(int $companyId, int $year, int $month): array
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $daysInMonth = Carbon::parse($startDate)->daysInMonth;
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        // Fetch all contracts for company
        $allContracts = Contract::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->get(['id', 'name', 'contract_number']);

        // Fetch approved contract runs for this month
        $approvedRuns = ContractPayrollRun::with(['contract:id,name,contract_number', 'approvedBy:id,name'])
            ->where('company_id', $companyId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'approved')
            ->get();

        $approvedContractIds = $approvedRuns->pluck('contract_id')->toArray();

        // Separate contracts into approved vs unapproved
        $unapprovedContracts = $allContracts->filter(function ($c) use ($approvedContractIds) {
            return ! in_array($c->id, $approvedContractIds);
        })->values();

        // A driver on five contracts has one month, not five. Adding each contract sheet's day
        // count together read 143 days for a 31-day August. The month is counted once, over the
        // dates themselves.
        $calendarDays = DailyLog::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereBetween('log_date', [$startDate, $endDate])
            ->where(function ($q) {
                $q->whereIn('driver_status', ['working', 'paid_leave'])
                    ->orWhere('orders_count', '>', 0)
                    ->orWhere('cash_collected', '>', 0);
            })
            ->selectRaw('employee_id, COUNT(DISTINCT log_date) AS days')
            ->groupBy('employee_id')
            ->pluck('days', 'employee_id');

        // Consolidated drivers mapping
        $consolidatedDrivers = [];

        foreach ($approvedRuns as $run) {
            $contractObj = $run->contract;
            $contractName = $contractObj?->name ?? "عقد #{$run->contract_id}";
            $snapshot = $run->snapshot_data;
            $drivers = $snapshot['drivers'] ?? [];

            foreach ($drivers as $d) {
                $empId = $d['employee_id'];
                if (! $empId) {
                    continue;
                }

                $adjTotal = (float) ($d['manual_adjustments']['total'] ?? 0.0);

                if (! isset($consolidatedDrivers[$empId])) {
                    $consolidatedDrivers[$empId] = [
                        'employee_id' => $empId,
                        'employee_name' => $d['employee_name'],
                        'employee_number' => $d['employee_number'],
                        'assigned_days' => $d['assigned_days'] ?? 0,
                        'actual_work_days' => (int) ($calendarDays[$empId] ?? ($d['actual_work_days'] ?? 0)),
                        'orders_count' => $d['orders_count'] ?? 0,
                        'gross_contract_earnings' => (float) ($d['gross_contract_earnings'] ?? 0.0),
                        'violations_deduction' => (float) ($d['violations_deduction'] ?? 0.0),
                        'manual_adjustments' => $adjTotal,
                        'contracts_worked' => [],
                    ];
                } else {
                    $consolidatedDrivers[$empId]['assigned_days'] = max($consolidatedDrivers[$empId]['assigned_days'], $d['assigned_days'] ?? 0);
                    // Deliberately not summed — see $calendarDays above.
                    $consolidatedDrivers[$empId]['orders_count'] += ($d['orders_count'] ?? 0);
                    $consolidatedDrivers[$empId]['gross_contract_earnings'] += (float) ($d['gross_contract_earnings'] ?? 0.0);
                    $consolidatedDrivers[$empId]['violations_deduction'] += (float) ($d['violations_deduction'] ?? 0.0);
                    $consolidatedDrivers[$empId]['manual_adjustments'] += $adjTotal;
                }

                $consolidatedDrivers[$empId]['contracts_worked'][] = [
                    'contract_id' => $run->contract_id,
                    'contract_name' => $contractName,
                    'payment_method' => $d['payment_method'] ?? 'fixed',
                    'payment_method_label' => $d['payment_method_label'] ?? 'ثابت',
                    'orders_count' => $d['orders_count'] ?? 0,
                    'gross' => (float) ($d['gross_contract_earnings'] ?? 0.0),
                    'violations' => (float) ($d['violations_deduction'] ?? 0.0),
                    'manual_adjustments' => $adjTotal,
                    'net' => (float) ($d['net_payout'] ?? 0.0),
                    'calculation_details' => $d['calculation_details'] ?? [],
                ];
            }
        }

        // Every charge that belongs to the person rather than to a contract, resolved once per
        // employee and never including anything the ledger shows as already collected.
        $allEmpIds = array_keys($consolidatedDrivers);
        $pendingDeductions = CompanyDeductionService::pendingFor($allEmpIds, $startDate, $endDate, $year, $month);

        // Company-level deductions only take money off a driver once the month has been
        // approved here. Until then they are reported as pending so an accountant can see
        // the effect before committing to it, and the payable net stays untouched.
        $consolidatedRun = ConsolidatedPayrollRun::withoutGlobalScopes()
            ->with('approvedBy:id,name')
            ->where('company_id', $companyId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'approved')
            ->first();

        $deductionsApplied = (bool) $consolidatedRun;

        // An approved month is frozen: serve exactly what was collected, never a fresh
        // projection. Re-deriving would silently drop an advance that has since closed.
        if ($consolidatedRun && is_array($consolidatedRun->snapshot_data)) {
            $frozen = $consolidatedRun->snapshot_data;
            $frozen['is_approved'] = true;
            $frozen['deductions_applied'] = true;
            $frozen['consolidated_run'] = [
                'id' => $consolidatedRun->id,
                'approved_at' => $consolidatedRun->approved_at,
                'approved_by_name' => $consolidatedRun->approvedBy?->name,
                'notes' => $consolidatedRun->notes,
            ];

            return self::withDisbursements($frozen, $companyId, $year, $month, $startDate, $endDate, $consolidatedRun);
        }

        $driversList = [];
        $totalOrdersSum = 0;
        $totalGrossSum = 0.0;
        $totalAdjustmentsSum = 0.0;
        $totalFinalNetSum = 0.0;
        $totalPendingSum = 0.0;
        $byType = [];

        foreach ($consolidatedDrivers as $empId => $d) {
            $pending = $pendingDeductions[$empId] ?? ['items' => [], 'total' => 0.0];
            $grouped = CompanyDeductionService::groupByType($pending['items']);

            $pendingTotal = round((float) $pending['total'], 3);
            $applied = $deductionsApplied ? $pendingTotal : 0.0;

            $gross = round($d['gross_contract_earnings'], 3);
            $adj = round($d['manual_adjustments'] ?? 0.0, 3);
            $finalNet = round($gross + $adj - $applied, 3);

            $amountOf = fn (string $type) => round((float) ($grouped[$type]['total'] ?? 0.0), 3);

            $d['deductions_applied'] = $deductionsApplied;
            $d['manual_adjustments_total'] = $adj;
            $d['final_net_payout'] = $finalNet;

            // Every source is reported both ways: what is owed, and what has actually been
            // taken. Before approval the second column is zero across the board.
            $d['pending_deductions_total'] = $pendingTotal;
            $d['deductions_total'] = $applied;
            $d['deduction_items'] = $pending['items'];
            // Charges the owner has decided to leave out of this month — shown as his decision.
            $d['deferred_items'] = $pending['deferred'] ?? [];

            foreach ([
                'violations' => ConsolidatedPayrollDeduction::SOURCE_VIOLATION,
                'maintenance' => ConsolidatedPayrollDeduction::SOURCE_MAINTENANCE,
                'custody' => ConsolidatedPayrollDeduction::SOURCE_CUSTODY,
                'driver_expenses' => ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE,
                'advances' => ConsolidatedPayrollDeduction::SOURCE_ADVANCE,
            ] as $key => $type) {
                $amount = $amountOf($type);
                $d["pending_{$key}_deduction"] = $amount;
                $d["{$key}_deduction"] = $deductionsApplied ? $amount : 0.0;
                $byType[$key] = round(($byType[$key] ?? 0.0) + $amount, 3);
            }

            $driversList[] = $d;

            $totalOrdersSum += $d['orders_count'];
            $totalGrossSum += $gross;
            $totalAdjustmentsSum += $adj;
            $totalFinalNetSum += $finalNet;
            $totalPendingSum += $pendingTotal;
        }

        return self::withDisbursements([
            'period' => [
                'year' => $year,
                'month' => $month,
                'days_in_month' => $daysInMonth,
            ],
            'is_approved' => $deductionsApplied,
            'deductions_applied' => $deductionsApplied,
            'consolidated_run' => $consolidatedRun ? [
                'id' => $consolidatedRun->id,
                'approved_at' => $consolidatedRun->approved_at,
                'approved_by_name' => $consolidatedRun->approvedBy?->name,
                'notes' => $consolidatedRun->notes,
            ] : null,
            'summary' => [
                'total_approved_contracts' => count($approvedRuns),
                'total_unapproved_contracts' => count($unapprovedContracts),
                'total_drivers' => count($driversList),
                'total_orders' => $totalOrdersSum,
                'total_gross_earnings' => round($totalGrossSum, 3),
                'total_manual_adjustments' => round($totalAdjustmentsSum, 3),
                'total_final_net_payout' => round($totalFinalNetSum, 3),
                'total_pending_deductions' => round($totalPendingSum, 3),
                'total_deductions' => $deductionsApplied ? round($totalPendingSum, 3) : 0.0,
                'total_violations_deductions' => $deductionsApplied ? ($byType['violations'] ?? 0.0) : 0.0,
                'total_advances_deductions' => $deductionsApplied ? ($byType['advances'] ?? 0.0) : 0.0,
                'total_pending_violations_deductions' => $byType['violations'] ?? 0.0,
                'total_pending_advances_deductions' => $byType['advances'] ?? 0.0,
                'total_pending_maintenance_deductions' => $byType['maintenance'] ?? 0.0,
                'total_pending_custody_deductions' => $byType['custody'] ?? 0.0,
                'total_pending_driver_expenses_deductions' => $byType['driver_expenses'] ?? 0.0,
            ],
            'approved_runs' => $approvedRuns->map(function ($r) {
                return [
                    'contract_id' => $r->contract_id,
                    'contract_name' => $r->contract?->name,
                    'approved_at' => $r->approved_at,
                    'approved_by_name' => $r->approvedBy?->name,
                    'total_drivers' => $r->total_drivers,
                    'total_net_payout' => $r->total_net_payout,
                ];
            })->values()->all(),
            'unapproved_contracts' => $unapprovedContracts->values()->all(),
            'drivers' => $driversList,
        ], $companyId, $year, $month, $startDate, $endDate, null);
    }

    /**
     * What each driver stood at before this month, what has been paid against it, and what is
     * left — laid over the month's figures rather than frozen with them. A payment recorded today
     * has to show on a month approved last week, and a debt absorbed by a later approval has to
     * stop showing on this one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /**
     * Charges deferred into this month (or earlier, and still uncollected) whose driver is not on
     * the sheet, resolved through the same rules that would charge them if he were.
     *
     * @param  array<int, int>  $onSheet  employee id => position, for the drivers with a row
     * @return array<int, array{employee_id: int, employee_name: string, source_type: string, label: string, amount: float, deferred_from: ?string}>
     */
    private static function deferredChargesOffSheet(int $companyId, int $year, int $month, array $onSheet, string $startDate, string $endDate): array
    {
        $here = PayrollDeductionOverride::index($year, $month);
        $landed = PayrollDeductionOverride::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('action', PayrollDeductionOverride::ACTION_DEFER)
            ->get()
            ->filter(fn ($o) => $o->deferIndex() !== null && $o->deferIndex() <= $here);
        if ($landed->isEmpty()) {
            return [];
        }

        // The employees those charges belong to, minus the ones already on the sheet.
        $employeeIds = [];
        foreach ($landed->groupBy('source_type') as $type => $rows) {
            $model = PayrollDeductionOverride::sourceModel($type);
            if (! $model) {
                continue;
            }
            $column = PayrollDeductionOverride::employeeColumn($type);
            foreach ($model::withoutGlobalScopes()->whereIn('id', $rows->pluck('source_id'))->pluck($column) as $employeeId) {
                if ($employeeId && ! isset($onSheet[(int) $employeeId])) {
                    $employeeIds[(int) $employeeId] = true;
                }
            }
        }
        if ($employeeIds === []) {
            return [];
        }

        $names = Employee::withoutGlobalScopes()->withTrashed()->whereIn('id', array_keys($employeeIds))->pluck('name', 'id');
        $pending = CompanyDeductionService::pendingFor(array_keys($employeeIds), $startDate, $endDate, $year, $month);

        $out = [];
        foreach ($pending as $employeeId => $bucket) {
            foreach ($bucket['items'] as $item) {
                if (empty($item['deferred_from'])) {
                    continue;
                }
                $out[] = [
                    'employee_id' => (int) $employeeId,
                    'employee_name' => $names[$employeeId] ?? "#{$employeeId}",
                    'source_type' => $item['source_type'],
                    'label' => $item['label'],
                    'amount' => round((float) $item['amount'], 3),
                    'deferred_from' => $item['deferred_from'],
                ];
            }
        }

        return $out;
    }

    private static function withDisbursements(array $data, int $companyId, int $year, int $month, string $startDate, string $endDate, ?ConsolidatedPayrollRun $run): array
    {
        $opening = PayrollBalanceService::openingBalances($companyId, $run?->id);

        $paidRows = $run
            ? PayrollDisbursement::withoutGlobalScopes()
                ->where('consolidated_run_id', $run->id)
                ->with('createdBy:id,name')
                ->orderBy('paid_at')
                ->orderBy('id')
                ->get()
                ->groupBy('employee_id')
            : collect();

        // The registered salary caps the bank side of any payment (PayrollDisbursement::bankAllowance);
        // the sheet carries it so the payment form splits bank/cash the way the rule will insist on.
        $bankSalaries = Employee::withoutGlobalScopes()->withTrashed()
            ->whereIn('id', array_map(fn ($d) => (int) ($d['employee_id'] ?? 0), $data['drivers'] ?? []))
            ->pluck('official_salary', 'id');

        $totals = [
            'opening' => 0.0, 'due' => 0.0, 'suggested' => 0.0, 'bank' => 0.0, 'cash' => 0.0, 'remaining' => 0.0,
            'paid' => 0, 'unpaid' => 0, 'nothing_due' => 0, 'owing' => 0,
        ];

        foreach ($data['drivers'] ?? [] as $i => $d) {
            $employeeId = (int) ($d['employee_id'] ?? 0);
            $prior = $opening[$employeeId] ?? ['balance' => 0.0, 'from' => null, 'months' => []];
            $rows = $paidRows->get($employeeId, collect());

            $net = round((float) ($d['final_net_payout'] ?? 0), 3);
            $bank = round((float) $rows->sum('bank_amount'), 3);
            $cash = round((float) $rows->sum('cash_amount'), 3);
            $paid = round($bank + $cash, 3);

            // The month's net with his standing balance netted off: a debt comes out of the pay,
            // an earlier month still unpaid is added to it. The suggestion is that, less what has
            // already been handed over, never below zero — a driver still in debt after his month
            // is suggested nothing and the shortfall rolls on. It is a suggestion: the payer may
            // record any amount, and what is left either way is his balance.
            $due = round($net + $prior['balance'], 3);
            $suggested = round(max(0.0, $due - $paid), 3);
            $remaining = round($due - $paid, 3);

            $status = match (true) {
                $run === null => 'not_approved',
                $rows->isNotEmpty() => 'paid',
                $suggested <= 0 => 'nothing_due',
                default => 'unpaid',
            };

            $data['drivers'][$i] = array_merge($d, [
                'opening_balance' => $prior['balance'],
                'opening_balance_from' => $prior['from'],
                // Only the months still holding something: a month paid to the fils explains nothing.
                'opening_balance_breakdown' => array_values(array_filter(
                    $prior['months'],
                    fn ($m) => abs($m['remaining']) >= 0.0005
                )),
                'amount_due' => $due,
                'suggested_disbursement' => $suggested,
                'disbursed_bank' => $bank,
                'disbursed_cash' => $cash,
                'disbursed_total' => $paid,
                'remaining_balance' => $remaining,
                'disbursement_status' => $status,
                'disbursements' => $rows->map(fn (PayrollDisbursement $p) => $p->toRow())->values()->all(),
                'bank_salary' => round((float) ($bankSalaries[$employeeId] ?? 0), 3),
                'bank_transferable' => round(max(0.0, (float) ($bankSalaries[$employeeId] ?? 0) - $bank), 3),
            ]);

            $totals['opening'] += $prior['balance'];
            $totals['due'] += $due;
            $totals['suggested'] += $suggested;
            $totals['bank'] += $bank;
            $totals['cash'] += $cash;
            $totals['remaining'] += $remaining;
            if ($status !== 'not_approved') {
                $totals[$status]++;
            }
            if ($prior['balance'] < 0) {
                $totals['owing']++;
            }
        }

        // A driver with a balance but no row this month — quit, on leave, or on a contract not yet
        // approved — must still be seen, or his balance disappears with him. Nothing is paid or
        // collected through this sheet for him; it is paid on the sheet of the month that owes it.
        $onSheet = array_flip(array_map(fn ($d) => (int) ($d['employee_id'] ?? 0), $data['drivers'] ?? []));
        $offSheet = array_filter(
            $opening,
            fn ($entry, $employeeId) => ! isset($onSheet[$employeeId]) && abs($entry['balance']) >= 0.0005,
            ARRAY_FILTER_USE_BOTH
        );
        $names = $offSheet === [] ? collect() : Employee::withoutGlobalScopes()->withTrashed()
            ->whereIn('id', array_keys($offSheet))
            ->pluck('name', 'id');
        $data['standing_balances_off_sheet'] = collect($offSheet)
            ->map(fn ($entry, $employeeId) => [
                'employee_id' => (int) $employeeId,
                'employee_name' => $names[$employeeId] ?? "#{$employeeId}",
                'balance' => $entry['balance'],
                'from' => $entry['from'],
            ])
            ->sortBy('balance')
            ->values()
            ->all();

        // A charge the owner sent to this month for a driver who has no row here — no work, no
        // approved contract yet — is still waiting. It cannot be taken from this sheet, but it
        // must be seen, or a deferral becomes a way to lose a charge.
        $data['deferred_charges_off_sheet'] = self::deferredChargesOffSheet($companyId, $year, $month, $onSheet, $startDate, $endDate);

        $company = Company::find($companyId);
        $data['company_name'] = $company?->name_ar ?: $company?->name;
        $data['summary'] = array_merge($data['summary'] ?? [], [
            'total_opening_balance' => round($totals['opening'], 3),
            'total_amount_due' => round($totals['due'], 3),
            'total_suggested_disbursement' => round($totals['suggested'], 3),
            'total_disbursed_bank' => round($totals['bank'], 3),
            'total_disbursed_cash' => round($totals['cash'], 3),
            'total_disbursed' => round($totals['bank'] + $totals['cash'], 3),
            'total_remaining_balance' => round($totals['remaining'], 3),
            'drivers_paid' => $totals['paid'],
            'drivers_unpaid' => $totals['unpaid'],
            'drivers_nothing_due' => $totals['nothing_due'],
            'drivers_owing' => $totals['owing'],
        ]);

        return $data;
    }
}
