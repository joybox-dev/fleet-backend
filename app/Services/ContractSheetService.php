<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\ContractPayrollAdjustment;
use App\Models\ContractPayrollRun;
use App\Models\DailyLog;
use App\Models\DriverContractOverride;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\Violation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * A contract's payroll sheet for one month: every driver on it, priced by the contract's rules.
 *
 * This is the one place a driver's cost to a contract is decided. The payroll screen serves it,
 * approval freezes it, and the profitability screens and the driver's statement all read from it —
 * as an array, not by calling the HTTP endpoint and parsing its JSON, which is what they used to do.
 */
class ContractSheetService
{
    /**
     * The sheet as it stands — or, once the month is approved, exactly what was approved.
     *
     * @return array<string, mixed>
     */
    public static function build(Contract $contract, int $year, int $month, int $companyId): array
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $daysInMonth = Carbon::parse($startDate)->daysInMonth;
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        $approvedRun = ContractPayrollRun::with('approvedBy:id,name')
            ->where('company_id', $companyId)
            ->where('contract_id', $contract->id)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'approved')
            ->first();

        // An approved month is what was approved. Re-deriving it can only drift: the fine that
        // was charged here gets flagged `is_deducted` when the consolidated month collects it,
        // and the next live pass would drop it from a sheet that is supposed to be closed.
        if ($approvedRun) {
            $frozen = $approvedRun->snapshot_data;
            if (is_string($frozen)) {
                $frozen = json_decode($frozen, true);
            }
            if (is_array($frozen) && ! empty($frozen['drivers'])) {
                $frozen['is_approved'] = true;
                $frozen['approved_run'] = $approvedRun;

                return $frozen;
            }
        }

        // Get contract assignments active during this month
        $assignments = ContractAssignment::withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->whereDate('start_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $startDate);
            })
            ->with(['employee' => function ($q) {
                $q->withoutGlobalScopes();
            }, 'overrides'])
            ->get();

        $employeeIds = $assignments->pluck('employee_id')->filter()->unique()->values();

        // Include any extra drivers with daily logs under this contract
        $extraLogDriverIds = DailyLog::withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->whereBetween('log_date', [$startDate, $endDate])
            ->pluck('employee_id')
            ->filter()
            ->unique()
            ->diff($employeeIds);

        if ($extraLogDriverIds->isNotEmpty()) {
            $extraEmployees = Employee::withoutGlobalScopes()->whereIn('id', $extraLogDriverIds)->get();
            foreach ($extraEmployees as $extraEmp) {
                $dummyAssign = new ContractAssignment([
                    'id' => null,
                    'contract_id' => $contract->id,
                    'employee_id' => $extraEmp->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => 'active',
                ]);
                $dummyAssign->setRelation('employee', $extraEmp);
                $dummyAssign->setRelation('overrides', collect());
                $assignments->push($dummyAssign);
                $employeeIds->push($extraEmp->id);
            }
        }

        // Fetch logs for all drivers in this contract.
        // withoutGlobalScopes() is here to cross the company scope, but it drops SoftDeletes with
        // it — so the sheet was paying for deleted logs that the dashboard (which uses the scoped
        // model) does not show. The delete filter is put back explicitly.
        $allLogs = DailyLog::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('log_date', [$startDate, $endDate])
            ->get()
            ->groupBy('employee_id');

        // Vehicle type is decided by the vehicles actually driven, so resolve them all once here
        // rather than hitting the table per driver inside the loop.
        $vehicleTypeById = Vehicle::withoutGlobalScopes()
            ->whereIn('id', $allLogs->flatten(1)->pluck('vehicle_id')->filter()->unique()->values())
            ->pluck('vehicle_type_id', 'id');

        // Named, because a month split across two vehicle types has to say which line is which.
        $vehicleTypeNames = VehicleType::withoutGlobalScopes()
            ->whereIn('id', $vehicleTypeById->values()->filter()->unique()->values())
            ->get()
            ->mapWithKeys(fn ($t) => [(int) $t->id => ($t->name_ar ?: $t->name)]);

        // Traffic violations are the only automatic deduction applied at contract level.
        // Salary advances are deliberately left out here and resolved once per employee in
        // the consolidated sheet, so a driver working under several contracts in the same month
        // is never charged the same instalment more than once.
        //
        // A fine belongs to the contract it was raised against. Ignoring charge_contract_id took
        // every fine off a driver on BOTH his contracts' sheets. An older fine that names no
        // contract is placed on the driver's only contract for the month; a driver who worked
        // several is owed the charge once, against the person, which the consolidated sheet settles.
        $contractsPerEmployee = ContractAssignment::withoutGlobalScopes()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('start_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $startDate);
            })
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->pluck('contract_id')->filter()->unique()->values()->all());

        $allViolations = Violation::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('violation_date', [$startDate, $endDate])
            ->get()
            ->filter(function ($v) use ($contract, $contractsPerEmployee) {
                if ($v->charge_contract_id !== null) {
                    return (int) $v->charge_contract_id === (int) $contract->id;
                }

                $worked = $contractsPerEmployee[$v->employee_id] ?? [];

                return count($worked) === 1 && (int) $worked[0] === (int) $contract->id;
            })
            ->groupBy('employee_id');

        // Fetch manual contract payroll adjustments
        $allAdjustments = ContractPayrollAdjustment::withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->where('year', $year)
            ->where('month', $month)
            ->with('createdBy:id,name')
            ->get()
            ->groupBy('employee_id');

        $driversResult = [];
        $totalOrdersSum = 0;
        $totalEarningsSum = 0.0;
        $totalDeductionsSum = 0.0;
        $totalAdjustmentsSum = 0.0;
        $totalNetSum = 0.0;

        foreach ($assignments as $assignment) {
            $employee = $assignment->employee;
            if (! $employee) {
                continue;
            }
            $empId = $employee->id;

            // Determine effective start and end dates for proration
            $assignStart = $assignment->start_date ? substr((string) $assignment->start_date, 0, 10) : $startDate;
            $assignEnd = $assignment->end_date ? substr((string) $assignment->end_date, 0, 10) : $endDate;

            $effStart = max($startDate, $assignStart);
            $effEnd = min($endDate, $assignEnd);

            $effStartCarbon = Carbon::parse($effStart);
            $effEndCarbon = Carbon::parse($effEnd);
            $assignedDays = $effStartCarbon->gt($effEndCarbon) ? 0 : $effStartCarbon->diffInDays($effEndCarbon) + 1;
            $segRatio = $daysInMonth > 0 ? min(1.0, max(0.0, $assignedDays / $daysInMonth)) : 1.0;

            // Driver's logs for this contract, bounded by the assignment window. A log dated outside
            // the driver's own assignment is not paid — bulkStore already refuses to save those days.
            $contractLogs = $allLogs->get($empId, collect())->where('contract_id', $contract->id);
            $inWindow = fn ($l) => substr((string) $l->log_date, 0, 10) >= $effStart
                && substr((string) $l->log_date, 0, 10) <= $effEnd;

            $empLogs = $contractLogs->filter($inWindow);

            // Days that fall outside the window are not paid, but they are named. Dropping a
            // driver's whole month to 0.000 with no reason on the row is how a real defect gets
            // mistaken for a rounding error.
            $outOfWindow = $contractLogs->reject($inWindow);
            $outOfWindowOrders = (int) $outOfWindow->sum('orders_count');
            $outOfWindowDates = $outOfWindow->filter(fn ($l) => (int) $l->orders_count > 0)
                ->map(fn ($l) => substr((string) $l->log_date, 0, 10))
                ->values()->all();

            $priced = self::priceDriverMonth(
                $employee,
                $contract,
                $assignment,
                $empLogs,
                $assignment->overrides ?: collect(),
                $vehicleTypeById,
                $vehicleTypeNames,
                $effStart
            );
            $calcResult = $priced['calc'];
            $activeOverride = $priced['active_override'];
            $vtIds = collect($priced['vehicle_type_ids']);
            $vtId = $vtIds->count() === 1 ? (int) $vtIds->first() : null;

            // Determine driver payment method
            $driverPaymentMethod = null;
            if ($activeOverride && $activeOverride->override_type) {
                $driverPaymentMethod = $activeOverride->override_type;
            } elseif ($vtId && is_array($contract->driver_pricing_rules) && isset($contract->driver_pricing_rules[$vtId]['payment_method'])) {
                $driverPaymentMethod = $contract->driver_pricing_rules[$vtId]['payment_method'];
            } elseif (is_array($contract->driver_pricing_rules)) {
                $firstKey = array_key_first($contract->driver_pricing_rules);
                if ($firstKey !== null && isset($contract->driver_pricing_rules[$firstKey]['payment_method'])) {
                    $driverPaymentMethod = $contract->driver_pricing_rules[$firstKey]['payment_method'];
                }
            }

            if (! $driverPaymentMethod) {
                $driverPaymentMethod = $contract->driver_payment_method ?: ($contract->payment_type ?: 'per_order');
            }

            // Deductions calculation for contract level (Traffic Violations ONLY).
            // `driver_deduction` mirrors `driver_share`, so a company-liable fine is 0 by design.
            // A fine already marked `is_deducted` was collected by a previously approved
            // consolidated month; charging it again here would take it off the driver twice.
            $empViolations = $allViolations->get($empId, collect());
            $outstandingViolations = $empViolations->filter(fn ($v) => ! $v->is_deducted);
            $violSum = (float) $outstandingViolations->sum('driver_deduction');
            $violAlreadyDeducted = (float) $empViolations->filter(fn ($v) => (bool) $v->is_deducted)->sum('driver_deduction');
            $totalContractDeductions = $violSum;

            // Manual adjustments calculation for this driver
            $empAdjustments = $allAdjustments->get($empId, collect());
            $additionsSum = (float) $empAdjustments->where('type', 'addition')->sum('amount');
            $deductionsSum = (float) $empAdjustments->where('type', 'deduction')->sum('amount');
            $netAdjustment = round($additionsSum - $deductionsSum, 3);

            $calcDetails = $calcResult['calculation_details'] ?? [];
            foreach ($empAdjustments as $adj) {
                $isAdd = $adj->type === 'addition';
                $amt = (float) $adj->amount;
                $signedAmt = $isAdd ? $amt : -$amt;
                $calcDetails[] = [
                    'label' => ($isAdd ? 'زيادة / مكافأة يدوية' : 'خصم يدوي')." ({$adj->reason})",
                    'amount' => $signedAmt,
                    'formula' => ($isAdd ? '+' : '-').number_format($amt, 3)." د.ك ({$adj->reason})",
                ];
            }

            $grossEarnings = (float) ($calcResult['gross_contract_earnings'] ?? 0.0);
            $netPayout = round($grossEarnings - $totalContractDeductions + $netAdjustment, 3);

            $actualWorkDays = $empLogs->filter(function ($log) {
                return ($log->orders_count > 0) || ($log->cash_collected > 0) || ($log->rejected_orders_count > 0) || ($log->driver_status === 'working');
            })->count();

            // Attendance alone never explained the salary: a driver paid for his leave read
            // "6 أيام" beside 50.000 د.ك, and 6 × the daily rate is 11.538. These are the two
            // figures that do explain it — the days that carry pay, and the days the contract
            // will pay for once its own monthly cap is applied.
            $paidDays = $empLogs->filter(function ($log) {
                return $log->driver_status === 'paid_leave'
                    || $log->driver_status === 'working'
                    || (int) $log->orders_count > 0
                    || (float) $log->cash_collected > 0
                    || (int) $log->rejected_orders_count > 0;
            })->count();
            $contractPayableDays = (int) ($contract->default_required_work_days ?: 0);
            $payableDays = $contractPayableDays > 0 ? min($paidDays, $contractPayableDays) : $paidDays;

            $driversResult[] = [
                'employee_id' => $empId,
                'employee_name' => $employee->name,
                'employee_number' => $employee->employee_number,
                'payment_method' => $driverPaymentMethod,
                'payment_method_label' => ContractPayrollService::paymentMethodLabel($driverPaymentMethod),
                'has_override' => (bool) $activeOverride,
                // An inactive assignment is reported, not dropped: this driver logged real work,
                // and silently removing a month of earned pay is worse than an unexpected row.
                'assignment_status' => $assignment->status,
                'assignment_start' => $assignStart,
                'assignment_end' => $assignment->end_date ? $assignEnd : null,
                'courier_id' => $assignment->courier_id,
                'out_of_window_logs' => $outOfWindow->count(),
                'out_of_window_orders' => $outOfWindowOrders,
                'out_of_window_dates' => $outOfWindowDates,
                // Mixed is a fact about the month, not a reason to pay nothing: each type is
                // priced by its own rule. Unresolved is the real problem — a day whose vehicle
                // type the contract has no rule for, which earns nothing and must say so.
                'vehicle_type_is_mixed' => $vtIds->count() > 1,
                'vehicle_type_ids' => $vtIds->all(),
                'unresolved_vehicle_type' => (bool) ($calcResult['unresolved_vehicle_type'] ?? false),
                'assigned_days' => $assignedDays,
                'actual_work_days' => $actualWorkDays,
                'paid_days' => $paidDays,
                'payable_days' => $payableDays,
                'contract_working_days' => $contractPayableDays,
                'days_ratio' => round($segRatio, 4),
                'orders_count' => $calcResult['orders_count'] ?? $empLogs->sum('orders_count'),
                'rejected_orders_count' => (int) $empLogs->sum('rejected_orders_count'),
                'cash_collected' => round((float) $empLogs->sum('cash_collected'), 3),
                'base_salary' => $calcResult['base_salary'] ?? 0.0,
                'orders_bonus' => $calcResult['orders_bonus'] ?? 0.0,
                'deficit_deduction' => $calcResult['deficit_deduction'] ?? 0.0,
                'surplus_bonus' => $calcResult['surplus_bonus'] ?? 0.0,
                'absence_deduction' => $calcResult['absence_deduction'] ?? 0.0,
                'gross_contract_earnings' => $grossEarnings,
                'unpriced_orders' => self::unpricedOrders($calcResult['calculation_details'] ?? []),
                'violations_deduction' => $violSum,
                'violations_already_deducted' => round($violAlreadyDeducted, 3),
                'manual_adjustments' => [
                    'total' => $netAdjustment,
                    'additions' => $additionsSum,
                    'deductions' => $deductionsSum,
                    'items' => $empAdjustments->map(fn ($a) => [
                        'id' => $a->id,
                        'type' => $a->type,
                        'amount' => (float) $a->amount,
                        'reason' => $a->reason,
                        'created_at' => $a->created_at?->toDateTimeString(),
                        'created_by_name' => $a->createdBy?->name,
                    ])->values()->toArray(),
                ],
                'global_deductions' => [
                    'advances' => 0.0,
                    'violations' => $violSum,
                    'total' => $totalContractDeductions,
                ],
                'net_payout' => $netPayout,
                'contract_default_gross' => $priced['contract_default_gross'],
                'override_delta' => $priced['contract_default_gross'] === null
                    ? null
                    : round($grossEarnings - $priced['contract_default_gross'], 3),
                'calculation_details' => $calcDetails,
            ];

            $totalOrdersSum += ($calcResult['orders_count'] ?? $empLogs->sum('orders_count'));
            $totalEarningsSum += $grossEarnings;
            $totalDeductionsSum += $totalContractDeductions;
            $totalAdjustmentsSum += $netAdjustment;
            $totalNetSum += $netPayout;
        }

        return [
            'contract' => [
                'id' => $contract->id,
                'name' => $contract->name,
                'contract_number' => $contract->contract_number,
                'currency' => $contract->currency ?: 'KWD',
                'payment_type' => $contract->payment_type,
                'driver_payment_method' => $contract->driver_payment_method,
            ],
            'period' => [
                'year' => $year,
                'month' => $month,
                'days_in_month' => $daysInMonth,
            ],
            'is_approved' => (bool) $approvedRun,
            'approved_run' => $approvedRun,
            'summary' => [
                'total_drivers' => count($driversResult),
                'total_orders' => $totalOrdersSum,
                'total_gross_earnings' => round($totalEarningsSum, 3),
                'total_violations_deductions' => round($totalDeductionsSum, 3),
                'total_manual_adjustments' => round($totalAdjustmentsSum, 3),
                'total_global_deductions' => round($totalDeductionsSum, 3),
                'total_net_payout' => round($totalNetSum, 3),
            ],
            // What stands in the way of approving this month, so the screen can say so before
            // anyone presses the button rather than after.
            'approval_blockers' => self::approvalBlockers($driversResult),
            'drivers' => $driversResult,
        ];
    }

    /**
     * One driver's month on one contract, priced a stretch at a time.
     *
     * A stretch is a run of days that share an override AND a vehicle type: pricing rules are per
     * vehicle type, so a driver who spent part of the month on a small car and the rest on a large
     * one has two prices, not one; and an override prices only the days its own window covers.
     * The contract's working-day cap is spent across the stretches in date order.
     *
     * The driver's statement prices its open months through this too, so the two cannot disagree.
     *
     * @param  Collection<int, DailyLog>  $empLogs  the driver's logs on this contract, inside the assignment window
     * @param  Collection<int, DriverContractOverride>  $overrides
     * @param  Collection<int, int|null>  $vehicleTypeById  vehicle id => vehicle type id
     * @param  Collection<int, string>|null  $vehicleTypeNames  vehicle type id => name
     * @return array{calc: array<string, mixed>, active_override: ?DriverContractOverride, vehicle_type_ids: array<int, int>, contract_default_gross: ?float}
     */
    public static function priceDriverMonth(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        Collection $empLogs,
        Collection $overrides,
        $vehicleTypeById,
        $vehicleTypeNames,
        string $effStart
    ): array {
        // Which override, if any, covers a given day. effective_from/effective_to used to be
        // treated as a switch: any overlap with the month applied the override to all 31 days.
        $overrideForDate = function (string $date) use ($overrides) {
            return $overrides->first(function ($ov) use ($date) {
                $ovStart = $ov->effective_from ? substr((string) $ov->effective_from, 0, 10) : null;
                $ovEnd = $ov->effective_to ? substr((string) $ov->effective_to, 0, 10) : null;

                return (! $ovStart || $ovStart <= $date) && (! $ovEnd || $ovEnd >= $date);
            });
        };

        // Vehicle types driven this month, read from the logs rather than from whatever vehicle is
        // assigned right now — closing an assignment must not reprice a past month.
        $vtIds = $empLogs->pluck('vehicle_id')->filter()
            ->map(fn ($vid) => $vehicleTypeById[$vid] ?? null)
            ->filter()->unique()->values();
        $vtId = $vtIds->count() === 1 ? (int) $vtIds->first() : null;

        $segments = [];
        foreach ($empLogs as $segLog) {
            $segOverride = $overrideForDate(substr((string) $segLog->log_date, 0, 10));
            $segVtId = $vehicleTypeById[$segLog->vehicle_id] ?? null;
            $segKey = ($segOverride ? 'ov:'.$segOverride->id : 'base').'|vt:'.($segVtId ?? 'none');
            if (! isset($segments[$segKey])) {
                $segments[$segKey] = [
                    'override' => $segOverride,
                    'vt_id' => $segVtId === null ? null : (int) $segVtId,
                    'logs' => collect(),
                ];
            }
            $segments[$segKey]['logs']->push($segLog);
        }
        if (empty($segments)) {
            $segments['base|vt:none'] = [
                'override' => $overrideForDate($effStart),
                'vt_id' => $vtId,
                'logs' => collect(),
            ];
        }

        // For labelling and the audit column: the override that priced the most of this month.
        $activeOverride = collect($segments)
            ->filter(fn ($seg) => $seg['override'] !== null)
            ->sortByDesc(fn ($seg) => $seg['logs']->count())
            ->first()['override'] ?? null;

        $calc = self::sumSegments($employee, $contract, $assignment, $segments, $vtId, $vehicleTypeNames);

        // An override replaces the payment method outright, so the sheet shows a number the
        // contract's own pricing never produced. Running the same month once more without the
        // override gives the reader something to check it against.
        $contractDefaultGross = null;
        if ($activeOverride) {
            try {
                $defaultSegments = [];
                foreach ($empLogs as $defLog) {
                    $defVtId = $vehicleTypeById[$defLog->vehicle_id] ?? null;
                    $defKey = 'vt:'.($defVtId ?? 'none');
                    if (! isset($defaultSegments[$defKey])) {
                        $defaultSegments[$defKey] = [
                            'override' => null,
                            'vt_id' => $defVtId === null ? null : (int) $defVtId,
                            'logs' => collect(),
                        ];
                    }
                    $defaultSegments[$defKey]['logs']->push($defLog);
                }

                $defaultCalc = self::sumSegments(
                    $employee,
                    $contract,
                    $assignment,
                    $defaultSegments ?: [['override' => null, 'vt_id' => $vtId, 'logs' => collect()]],
                    $vtId,
                    $vehicleTypeNames
                );

                $contractDefaultGross = round((float) ($defaultCalc['gross_contract_earnings'] ?? 0), 3);
            } catch (\Throwable $e) {
                \Log::warning('Contract-default projection failed for employee '.$employee->id.': '.$e->getMessage());
            }
        }

        return [
            'calc' => $calc,
            'active_override' => $activeOverride,
            'vehicle_type_ids' => $vtIds->map(fn ($v) => (int) $v)->all(),
            'contract_default_gross' => $contractDefaultGross,
        ];
    }

    /**
     * Work this month that no pricing rule covers — the one thing that must stop a sheet being
     * approved. A driver whose orders no rule covers is frozen at nothing for them; the pricing
     * gets completed first. The test is ORDERS, not the unresolved flag: that flag is also true
     * for a driver who simply never worked, and there is nothing wrong with his month.
     *
     * @param  array<int, array<string, mixed>>  $drivers
     * @return array<int, array<string, mixed>>
     */
    public static function approvalBlockers(array $drivers): array
    {
        $blockers = [];

        foreach ($drivers as $driver) {
            $unpriced = array_filter(
                $driver['calculation_details'] ?? [],
                fn ($line) => ! empty($line['is_unpriced']) && (int) ($line['orders'] ?? 0) > 0
            );

            if (empty($unpriced)) {
                continue;
            }

            $blockers[] = [
                'employee_id' => $driver['employee_id'] ?? null,
                'employee_name' => $driver['employee_name'] ?? '',
                'unpriced_orders' => array_sum(array_map(fn ($l) => (int) ($l['orders'] ?? 0), $unpriced)),
                'reasons' => array_values(array_unique(array_map(fn ($l) => (string) ($l['label'] ?? ''), $unpriced))),
            ];
        }

        return $blockers;
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     */
    private static function unpricedOrders(array $details): int
    {
        return (int) array_sum(array_map(
            fn ($line) => ! empty($line['is_unpriced']) ? (int) ($line['orders'] ?? 0) : 0,
            $details
        ));
    }

    /**
     * Price a month one segment at a time and add the segments together.
     *
     * @param  array<string, array{override: ?DriverContractOverride, vt_id?: ?int, logs: Collection}>  $segments
     * @return array<string, mixed>
     */
    private static function sumSegments(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        array $segments,
        ?int $vtId,
        $vehicleTypeNames = null
    ): array {
        $numeric = [
            'orders_count', 'base_salary', 'orders_bonus', 'required_target',
            'deficit_deduction', 'surplus_bonus', 'absence_deduction', 'gross_contract_earnings',
        ];

        $totals = array_fill_keys($numeric, 0);
        $details = [];
        $multi = count($segments) > 1;
        $unresolved = false;

        // The contract's working days cap a MONTH, and this splits one into stretches. Left to
        // itself each stretch applied the whole cap, so a month over two vehicle types could be
        // paid for more days than the contract pays for at all. The allowance is spent in date
        // order and what remains is handed to the next stretch.
        $dayBudget = (int) $contract->default_required_work_days;

        foreach ($segments as $segment) {
            // Each stretch is priced by the rule for the vehicle actually driven in it. Only the
            // caller that never split by type leaves vt_id unset, and it falls back as before.
            $segVtId = array_key_exists('vt_id', $segment) ? $segment['vt_id'] : $vtId;

            $result = ContractPayrollService::calculateDriverContractPayroll(
                $employee,
                $contract,
                $assignment,
                $segment['override'],
                $segment['logs'],
                $segVtId,
                $multi ? max(0, $dayBudget) : null
            );

            if ($multi) {
                $dayBudget -= ContractPayrollService::evaluateDriverAttendance($segment['logs'])['paid_days'];
            }

            foreach ($numeric as $field) {
                $totals[$field] += (float) ($result[$field] ?? 0);
            }

            $unresolved = $unresolved || ! empty($result['unresolved_vehicle_type']);

            // With more than one segment the reader needs to know which stretch a line belongs to,
            // otherwise the breakdown reads as one month priced two contradictory ways.
            $prefix = '';
            if ($multi) {
                $dates = $segment['logs']->map(fn ($l) => substr((string) $l->log_date, 0, 10))->sort()->values();
                $span = $dates->isEmpty()
                    ? ''
                    : ($dates->first() === $dates->last() ? $dates->first() : $dates->first().' → '.$dates->last());
                $label = $segment['override'] ? 'استثناء مخصص' : 'تسعير العقد';
                $typeName = $segVtId !== null && $vehicleTypeNames
                    ? ($vehicleTypeNames[$segVtId] ?? null)
                    : null;
                if ($typeName) {
                    $label .= ' · '.$typeName;
                }
                $prefix = $span === '' ? "[{$label}] " : "[{$label} {$span}] ";
            }

            foreach (($result['calculation_details'] ?? []) as $line) {
                if ($prefix !== '' && isset($line['label'])) {
                    $line['label'] = $prefix.$line['label'];
                }
                $details[] = $line;
            }
        }

        $totals['orders_count'] = (int) $totals['orders_count'];
        $totals['required_target'] = (int) $totals['required_target'];
        foreach (['base_salary', 'orders_bonus', 'deficit_deduction', 'surplus_bonus', 'absence_deduction', 'gross_contract_earnings'] as $money) {
            $totals[$money] = round($totals[$money], 3);
        }
        $totals['calculation_details'] = $details;
        $totals['segments'] = count($segments);
        $totals['unresolved_vehicle_type'] = $unresolved;

        return $totals;
    }
}
