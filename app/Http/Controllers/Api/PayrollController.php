<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdvanceDeduction;
use App\Models\ConsolidatedPayrollDeduction;
use App\Models\ConsolidatedPayrollRun;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\ContractMandatoryDay;
use App\Models\ContractMonthlyParameter;
use App\Models\ContractPayrollAdjustment;
use App\Models\ContractPayrollRun;
use App\Models\CurrencyExchangeRate;
use App\Models\DailyLog;
use App\Models\DriverContractOverride;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleType;
use App\Models\Violation;
use App\Services\CompanyDeductionService;
use App\Services\ContractPayrollService;
use App\Services\Contracts\SmartValueFallbackService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PayrollController extends Controller
{
    public static function recalculateEmployeeCommissions($employeeId, $yearOrContract, $monthOrYear = null, $preFetchedLogsOrMonth = null, $extraLogs = null)
    {
        if ($employeeId instanceof Employee) {
            $employee = $employeeId;
            $employeeId = $employee->id;
        } else {
            $employee = Employee::find($employeeId);
        }

        if (! $employee) {
            return collect();
        }

        if ($yearOrContract instanceof Contract || (is_object($yearOrContract) && isset($yearOrContract->id))) {
            // Called with ($employee, $contract, $year, $month, $logs)
            $year = (int) $monthOrYear;
            $month = (int) $preFetchedLogsOrMonth;
            $preFetchedLogs = $extraLogs;
        } else {
            // Called with ($employee, $year, $month, $logs)
            $year = (int) $yearOrContract;
            $month = (int) $monthOrYear;
            $preFetchedLogs = $preFetchedLogsOrMonth;
        }

        $startDate = "{$year}-".str_pad($month, 2, '0', STR_PAD_LEFT).'-01';
        $endDate = Carbon::parse($startDate)->endOfMonth()->toDateString();

        $logs = $preFetchedLogs ?? DailyLog::where('employee_id', $employeeId)
            ->whereBetween('log_date', [$startDate, $endDate])
            ->orderBy('log_date')
            ->orderBy('id')
            ->get(['id', 'employee_id', 'vehicle_id', 'orders_count', 'driver_commission', 'income_amount', 'log_date', 'contract_id', 'zone', 'shift_valid', 'is_valid', 'online_hours', 'created_by']);

        $assignments = ContractAssignment::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('start_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $startDate);
            })
            ->get();

        $contractIds = $logs->pluck('contract_id')->concat($assignments->pluck('contract_id'))->filter()->unique();
        $contracts = Contract::withoutGlobalScopes()->whereIn('id', $contractIds)->get()->keyBy('id');

        $vehicleIds = $logs->pluck('vehicle_id')->filter()->unique();
        $vehicles = Vehicle::withoutGlobalScopes()->whereIn('id', $vehicleIds)->get()->keyBy('id');

        $target = (int) ($employee->target_orders_monthly ?? 0);
        $baseRate = (float) (($target > 0 && $employee->base_commission_rate !== null) ? $employee->base_commission_rate : ($employee->rate_per_order ?? 0.000));
        $premiumRate = (float) ($employee->premium_commission_rate ?? 0.000);

        $runningOrders = 0;

        foreach ($logs as $log) {
            $cOrders = (int) $log->orders_count;
            $logCommission = 0;
            $logDate = $log->log_date instanceof Carbon ? $log->log_date->toDateString() : substr((string) $log->log_date, 0, 10);

            // Check if log has contract or driver has active assignment on log_date
            $contractId = $log->contract_id;
            if (! $contractId) {
                $activeAssign = $assignments->first(function ($a) use ($logDate) {
                    $st = $a->start_date instanceof Carbon ? $a->start_date->toDateString() : substr((string) $a->start_date, 0, 10);
                    $et = $a->end_date ? ($a->end_date instanceof Carbon ? $a->end_date->toDateString() : substr((string) $a->end_date, 0, 10)) : null;

                    return $st <= $logDate && (! $et || $et >= $logDate);
                });
                if ($activeAssign) {
                    $contractId = $activeAssign->contract_id;
                }
            }

            $rate = null;
            if ($contractId) {
                $contractObj = $contracts->get($contractId);
                if (! $contractObj) {
                    $contractObj = Contract::withoutGlobalScopes()->find($contractId);
                }

                $driverPaymentMethod = $contractObj ? $contractObj->driver_payment_method : null;
                $pricingRules = $contractObj ? (is_string($contractObj->driver_pricing_rules) ? json_decode($contractObj->driver_pricing_rules, true) : $contractObj->driver_pricing_rules) : [];

                $vehicleTypeId = null;
                if ($log->vehicle_id && isset($vehicles[$log->vehicle_id])) {
                    $vehicleTypeId = $vehicles[$log->vehicle_id]->vehicle_type_id;
                }
                if (! $vehicleTypeId && $employee->vehicle_type_id) {
                    $vehicleTypeId = $employee->vehicle_type_id;
                }

                if (is_array($pricingRules)) {
                    if ($vehicleTypeId !== null && isset($pricingRules[$vehicleTypeId])) {
                        $vtRules = $pricingRules[$vehicleTypeId];
                        if (isset($vtRules['payment_method'])) {
                            $driverPaymentMethod = $vtRules['payment_method'];
                        }
                        $pricingRules = $vtRules;
                    } else {
                        $firstKey = array_key_first($pricingRules);
                        if ($firstKey !== null && isset($pricingRules[$firstKey]) && is_array($pricingRules[$firstKey])) {
                            if (isset($pricingRules[$firstKey]['payment_method'])) {
                                $driverPaymentMethod = $pricingRules[$firstKey]['payment_method'];
                            }
                            $pricingRules = $pricingRules[$firstKey];
                        }
                    }
                }

                if (! $driverPaymentMethod) {
                    $driverPaymentMethod = $contractObj ? $contractObj->payment_type : 'per_order';
                }

                if ($driverPaymentMethod === 'zones' || $driverPaymentMethod === 'zones_tiers') {
                    $zoneRules = is_array($pricingRules) && isset($pricingRules['zones']) ? $pricingRules['zones'] : (is_array($pricingRules) && isset($pricingRules['zones_tiers']) ? $pricingRules['zones_tiers'] : $pricingRules);
                    $logComm = 0.0;
                    $notesData = $log->notes ? json_decode($log->notes, true) : null;
                    $zoneOrdersMap = (is_array($notesData) && isset($notesData['zone_orders']) && is_array($notesData['zone_orders']))
                        ? $notesData['zone_orders']
                        : [];

                    if (! empty($zoneOrdersMap)) {
                        foreach ($zoneOrdersMap as $zIdOrName => $zCount) {
                            $zCount = (int) $zCount;
                            if ($zCount <= 0) {
                                continue;
                            }
                            $zRate = 0.0;
                            if (is_array($zoneRules)) {
                                foreach ($zoneRules as $rule) {
                                    if (is_array($rule) && (
                                        (isset($rule['id']) && (string) $rule['id'] === (string) $zIdOrName) ||
                                        (isset($rule['name']) && $rule['name'] === $zIdOrName) ||
                                        (isset($rule['zone']) && $rule['zone'] === $zIdOrName)
                                    )) {
                                        $zRate = (float) ($rule['price'] ?? $rule['rate'] ?? 0.0);
                                        break;
                                    }
                                }
                            }
                            $logComm += $zCount * $zRate;
                        }
                    } else {
                        $zoneName = $log->zone;
                        $zRate = 0.0;
                        if (is_array($zoneRules)) {
                            foreach ($zoneRules as $rule) {
                                if (is_array($rule) && (
                                    (isset($rule['id']) && (string) $rule['id'] === (string) $zoneName) ||
                                    (isset($rule['name']) && $rule['name'] === $zoneName) ||
                                    (isset($rule['zone']) && $rule['zone'] === $zoneName)
                                )) {
                                    $zRate = (float) ($rule['price'] ?? $rule['rate'] ?? 0.0);
                                    break;
                                }
                            }
                        }
                        $logComm = $cOrders * $zRate;
                    }
                    $rate = ($cOrders > 0) ? ($logComm / $cOrders) : 0.0;
                } else {
                    $activeAssignForContract = $assignments->first(function ($a) use ($contractId, $logDate) {
                        $st = $a->start_date instanceof Carbon ? $a->start_date->toDateString() : substr((string) $a->start_date, 0, 10);
                        $et = $a->end_date ? ($a->end_date instanceof Carbon ? $a->end_date->toDateString() : substr((string) $a->end_date, 0, 10)) : null;

                        return $a->contract_id == $contractId && $st <= $logDate && (! $et || $et >= $logDate);
                    });

                    if ($activeAssignForContract) {
                        $rate = SmartValueFallbackService::resolve($employeeId, $contractId, $logDate, 'order_commission');
                    }
                }
            }

            if ($rate !== null) {
                // Convert currency if contract currency is different from KWD
                $contract = Contract::find($contractId);
                if ($contract && $contract->currency && $contract->currency !== 'KWD') {
                    $rateModel = CurrencyExchangeRate::where('company_id', $employee->company_id)
                        ->where('from_currency', $contract->currency)
                        ->where('to_currency', 'KWD')
                        ->where('year', $year)
                        ->where('month', $month)
                        ->first();
                    if ($rateModel) {
                        $rate = (float) $rate * (float) $rateModel->exchange_rate;
                    }
                }

                $logCommission = $cOrders * (float) $rate;
            } else {
                if ($target > 0) {
                    $start = $runningOrders + 1;
                    $end = $runningOrders + $cOrders;

                    if ($end <= $target) {
                        $logCommission = $cOrders * $baseRate;
                    } elseif ($start > $target) {
                        $logCommission = $cOrders * $premiumRate;
                    } else {
                        $baseOrders = $target - $start + 1;
                        $premiumOrders = $end - $target;
                        $logCommission = ($baseOrders * $baseRate) + ($premiumOrders * $premiumRate);
                    }
                } else {
                    $logCommission = $cOrders * $baseRate;
                }
            }

            $newCommission = round($logCommission, 3);
            if ((float) $log->driver_commission !== (float) $newCommission) {
                $log->driver_commission = $newCommission;

                \DB::table('daily_logs')
                    ->where('id', $log->id)
                    ->update(['driver_commission' => $newCommission]);
            }

            $runningOrders += $cOrders;
        }

        return $logs;
    }

    public static function calculateDriverSlipData(
        Employee $employee,
        int $year,
        int $month,
        string $startDate,
        string $endDate,
        $allDailyLogs,
        $violationSums,
        $maintenanceSums,
        $custodySums,
        $leaveData,
        $allAdvances,
        $allAssignments,
        $existingSlip = null,
        $driverExpenseSums = null
    ): array {
        $employeeId = $employee->id;
        $empLogs = $allDailyLogs->get($employeeId, collect());

        // 1. Recalculate daily log commissions
        $empLogs = self::recalculateEmployeeCommissions($employee, $year, $month, $empLogs);

        $daysInMonth = Carbon::parse($startDate)->daysInMonth;

        // Fetch contract assignments for this employee
        $empContractAssignments = null;
        if ($allAssignments instanceof Collection) {
            $first = $allAssignments->first();
            if ($first instanceof ContractAssignment) {
                $empContractAssignments = $allAssignments->where('employee_id', $employeeId);
            } else {
                $empContractAssignments = $allAssignments->get($employeeId);
            }
        }

        if (! $empContractAssignments || ($empContractAssignments instanceof Collection && $empContractAssignments->isEmpty())) {
            $empContractAssignments = ContractAssignment::withoutGlobalScopes()
                ->where('employee_id', $employeeId)
                ->where('start_date', '<=', $endDate)
                ->where(function ($q) use ($startDate) {
                    $q->whereNull('end_date')
                        ->orWhere('end_date', '>=', $startDate);
                })
                ->with('contract')
                ->get();
        }

        if (! ($empContractAssignments instanceof Collection)) {
            $empContractAssignments = collect($empContractAssignments ? [$empContractAssignments] : []);
        }

        $singleLogContractId = null;
        if ($empContractAssignments->isEmpty()) {
            $uniqueLogContracts = $empLogs->pluck('contract_id')->filter()->unique();
            if ($uniqueLogContracts->count() === 1) {
                $singleLogContractId = $uniqueLogContracts->first();
            }
        }

        // Fetch vehicle assignments active in this month
        $empVehicleAssignments = VehicleAssignment::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('assigned_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('unassigned_date')
                    ->orWhere('unassigned_date', '>=', $startDate);
            })
            ->with('vehicle')
            ->get();

        // Pre-fetch vehicles in bulk to eliminate N+1 queries inside 31-day loop
        $logVehicleIds = $empLogs->pluck('vehicle_id')->filter();
        $assignVehicleIds = $empVehicleAssignments->map(function ($va) {
            return is_array($va) ? ($va['vehicle_id'] ?? null) : ($va->vehicle_id ?? null);
        })->filter();
        $allVehIds = $logVehicleIds->concat($assignVehicleIds)->unique();
        $vehiclesMap = $allVehIds->isNotEmpty()
            ? Vehicle::withoutGlobalScopes()->whereIn('id', $allVehIds)->get()->keyBy('id')
            : collect();

        // Map each day of the month to its active contract and vehicle type
        $dayMap = [];
        $hasAnyContractAssignment = false;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $d);

            if ($employee->date_of_joining) {
                $joiningDateStr = $employee->date_of_joining instanceof Carbon
                    ? $employee->date_of_joining->toDateString()
                    : substr($employee->date_of_joining, 0, 10);
                if ($date < $joiningDateStr) {
                    continue;
                }
            }

            // Find contract assignment active on this day
            $activeContractAssign = $empContractAssignments->first(function ($a) use ($date) {
                $rawStart = is_array($a) ? ($a['start_date'] ?? $a['assigned_date'] ?? null) : ($a->start_date ?? $a->assigned_date ?? null);
                $rawEnd = is_array($a) ? ($a['end_date'] ?? $a['unassigned_date'] ?? null) : ($a->end_date ?? $a->unassigned_date ?? null);
                if (! $rawStart) {
                    return false;
                }

                $sDate = $rawStart instanceof Carbon ? $rawStart->toDateString() : substr((string) $rawStart, 0, 10);
                $eDate = $rawEnd ? ($rawEnd instanceof Carbon ? $rawEnd->toDateString() : substr((string) $rawEnd, 0, 10)) : null;

                return $sDate <= $date
                    && ($eDate === null || $eDate >= $date);
            });

            // Find vehicle assignment active on this day
            $activeVehicleAssign = $empVehicleAssignments->first(function ($va) use ($date) {
                $rawStart = is_array($va) ? ($va['assigned_date'] ?? $va['start_date'] ?? null) : ($va->assigned_date ?? $va->start_date ?? null);
                $rawEnd = is_array($va) ? ($va['unassigned_date'] ?? $va['end_date'] ?? null) : ($va->unassigned_date ?? $va->end_date ?? null);
                if (! $rawStart) {
                    return false;
                }

                $sDate = $rawStart instanceof Carbon ? $rawStart->toDateString() : substr((string) $rawStart, 0, 10);
                $eDate = $rawEnd ? ($rawEnd instanceof Carbon ? $rawEnd->toDateString() : substr((string) $rawEnd, 0, 10)) : null;

                return $sDate <= $date
                    && ($eDate === null || $eDate >= $date);
            });

            $dayLog = $empLogs->firstWhere('log_date', $date);

            $contractIdVal = ($dayLog && $dayLog->contract_id)
                ? $dayLog->contract_id
                : ($activeContractAssign ? $activeContractAssign->contract_id : null);

            // Find vehicle type id (in-memory lookup)
            $vehicleTypeIdVal = null;
            if ($dayLog && $dayLog->vehicle_id && isset($vehiclesMap[$dayLog->vehicle_id])) {
                $vehicleTypeIdVal = $vehiclesMap[$dayLog->vehicle_id]->vehicle_type_id;
            }
            if (! $vehicleTypeIdVal && $activeVehicleAssign) {
                $vId = is_array($activeVehicleAssign) ? ($activeVehicleAssign['vehicle_id'] ?? null) : ($activeVehicleAssign->vehicle_id ?? null);
                if ($vId && isset($vehiclesMap[$vId])) {
                    $vehicleTypeIdVal = $vehiclesMap[$vId]->vehicle_type_id;
                }
            }
            if (! $vehicleTypeIdVal && $employee->vehicle_type_id) {
                $vehicleTypeIdVal = $employee->vehicle_type_id;
            }

            $dayMap[$date] = [
                'contract_id' => $contractIdVal,
                'contract_assignment' => $activeContractAssign,
                'vehicle_type_id' => $vehicleTypeIdVal,
            ];
        }

        $hasAnyContractAssignment = $empContractAssignments->isNotEmpty();

        // Group consecutive days into segments
        $segments = [];
        $currentSegment = null;

        foreach ($dayMap as $date => $info) {
            if ($currentSegment === null) {
                $currentSegment = [
                    'contract_id' => $info['contract_id'],
                    'contract_assignment' => $info['contract_assignment'],
                    'vehicle_type_id' => $info['vehicle_type_id'],
                    'start_date' => $date,
                    'end_date' => $date,
                    'days' => 1,
                ];
            } else {
                if ($currentSegment['contract_id'] === $info['contract_id']
                    && $currentSegment['vehicle_type_id'] === $info['vehicle_type_id']) {
                    $currentSegment['end_date'] = $date;
                    $currentSegment['days']++;
                } else {
                    $segments[] = $currentSegment;
                    $currentSegment = [
                        'contract_id' => $info['contract_id'],
                        'contract_assignment' => $info['contract_assignment'],
                        'vehicle_type_id' => $info['vehicle_type_id'],
                        'start_date' => $date,
                        'end_date' => $date,
                        'days' => 1,
                    ];
                }
            }
        }
        if ($currentSegment !== null) {
            $segments[] = $currentSegment;
        }

        if (empty($dayMap)) {
            $segments = [];
        } elseif (! $hasAnyContractAssignment) {
            $segments = [[
                'contract_id' => null,
                'contract_assignment' => null,
                'vehicle_type_id' => null,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $daysInMonth,
            ]];
        }

        // Get primary assignment info for overall slip info
        $primarySegment = null;
        foreach ($segments as $seg) {
            if ($seg['contract_id'] !== null && $primarySegment === null) {
                $primarySegment = $seg;
            }
        }
        if ($primarySegment === null || $primarySegment['contract_id'] === null) {
            $firstAssign = $empContractAssignments->first();
            $fallbackId = is_object($firstAssign) ? ($firstAssign->contract_id ?? null) : null;
            if (! $fallbackId) {
                $fallbackId = $empLogs->pluck('contract_id')->filter()->first();
            }
            if (is_object($fallbackId)) {
                $fallbackId = $fallbackId->id ?? null;
            }
            $fallbackContractId = $fallbackId ? (int) $fallbackId : null;

            if ($primarySegment === null) {
                $primarySegment = $segments[0] ?? [
                    'contract_id' => $fallbackContractId,
                    'contract_assignment' => null,
                    'vehicle_type_id' => null,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'days' => $daysInMonth,
                ];
            } else {
                $primarySegment['contract_id'] = $fallbackContractId;
            }
        }

        $contractId = $primarySegment['contract_id'] ?? null;
        $contractAssignment = $primarySegment['contract_assignment'] ?? null;
        $contract = null;
        if (is_object($contractAssignment) && isset($contractAssignment->contract)) {
            $contract = $contractAssignment->contract;
        }
        if (! $contract && $contractId) {
            $contract = Contract::withoutGlobalScopes()->find($contractId);
        }
        $paymentType = $employee->pay_type;
        if ($contract) {
            $paymentType = $contract->payment_type;
        }

        $exchangeRate = 1.0;
        if ($contract && $contract->currency && $contract->currency !== 'KWD') {
            $rateModel = CurrencyExchangeRate::where('company_id', $employee->company_id)
                ->where('from_currency', $contract->currency)
                ->where('to_currency', 'KWD')
                ->where('year', $year)
                ->where('month', $month)
                ->first();
            if ($rateModel) {
                $exchangeRate = (float) $rateModel->exchange_rate;
            }
        }

        $totalOrders = 0;
        $ordersBonus = 0.0;
        $baseActual = 0.0;
        $absenceDeduction = 0.0;
        $totalMonthlyTarget = 0;
        $totalRequiredValidDays = 0;

        foreach ($segments as $segment) {
            $segContractId = $segment['contract_id'];
            $segRatio = $segment['days'] / $daysInMonth;
            $segLogs = $empLogs->whereBetween('log_date', [$segment['start_date'], $segment['end_date']]);
            $segOrders = $segLogs->sum('orders_count');
            $totalOrders += $segOrders;

            if ($segContractId === null) {
                // Legacy segment
                $segCommissions = 0;
                $hasRecalculated = false;
                foreach ($segLogs as $l) {
                    if ($l->contract_id && ! $hasRecalculated) {
                        $lContract = Contract::find($l->contract_id);
                        if ($lContract) {
                            $recalc = self::recalculateEmployeeCommissions($employee, $year, $month, $segLogs);
                            $segCommissions = is_array($recalc) ? ($recalc['orders_bonus'] ?? 0) : (float) $recalc->sum('driver_commission');
                            $hasRecalculated = true;
                        }
                    }
                }
                if (! $hasRecalculated) {
                    $segCommissions = $segLogs->sum('driver_commission');
                }

                if ($employee->pay_type === 'per_order') {
                    $baseActual += $segCommissions;
                } else {
                    $baseActual += ((float) $employee->actual_salary) * $segRatio;
                    if ($employee->pay_type === 'hybrid') {
                        $ordersBonus += $segCommissions;
                    }
                }

                continue;
            }

            $segContract = Contract::withoutGlobalScopes()->find($segContractId);
            if (! $segContract) {
                continue;
            }

            $segExchangeRate = 1.0;
            if ($segContract->currency && $segContract->currency !== 'KWD') {
                $rateModel = CurrencyExchangeRate::where('company_id', $employee->company_id)
                    ->where('from_currency', $segContract->currency)
                    ->where('to_currency', 'KWD')
                    ->where('year', $year)
                    ->where('month', $month)
                    ->first();
                if ($rateModel) {
                    $segExchangeRate = (float) $rateModel->exchange_rate;
                }
            }

            $activeOverride = null;
            $assignObj = $segment['contract_assignment'] ?? null;
            $assignId = is_array($assignObj) ? ($assignObj['id'] ?? null) : ($assignObj->id ?? null);
            if ($assignId) {
                $activeOverride = DriverContractOverride::where('contract_assignment_id', $assignId)
                    ->whereDate('effective_from', '<=', $segment['end_date'])
                    ->where(function ($q) use ($segment) {
                        $q->whereNull('effective_to')
                            ->orWhereDate('effective_to', '>=', $segment['start_date']);
                    })
                    ->first();
            }

            $vehicleTypeId = $segment['vehicle_type_id'] ?? null;
            $segPaymentType = $segContract->payment_type;
            $driverPaymentMethod = $segContract->driver_payment_method;

            if ($activeOverride && $activeOverride->override_type !== null) {
                $driverPaymentMethod = $activeOverride->override_type;
            } elseif ($vehicleTypeId !== null && is_array($segContract->driver_pricing_rules) && isset($segContract->driver_pricing_rules[$vehicleTypeId]['payment_method'])) {
                $driverPaymentMethod = $segContract->driver_pricing_rules[$vehicleTypeId]['payment_method'];
            } elseif (! $driverPaymentMethod && is_array($segContract->driver_pricing_rules)) {
                $firstKey = array_key_first($segContract->driver_pricing_rules);
                if ($firstKey !== null && isset($segContract->driver_pricing_rules[$firstKey]['payment_method'])) {
                    $driverPaymentMethod = $segContract->driver_pricing_rules[$firstKey]['payment_method'];
                }
            }

            if (! $driverPaymentMethod) {
                $driverPaymentMethod = $segPaymentType ?? 'per_order';
            }

            // Daily log commissions are already calculated in step 1
            $segLogsRecalculated = $segLogs;

            // Resolve fixed salary and absence divisor
            $fixedSalary = SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'fixed_salary');
            if ($fixedSalary === null) {
                $fixedSalary = (float) $employee->actual_salary;
            } else {
                $fixedSalary = (float) $fixedSalary * $segExchangeRate;
            }
            $proratedFixedSalary = $fixedSalary * $segRatio;

            $divisor = (int) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'absence_divisor') ?? 26);

            $requiredWorkDays = (int) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'required_work_days') ?? 26);
            $proratedRequiredWorkDays = (int) round($requiredWorkDays * $segRatio);

            $requiredValidDays = (int) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'valid_days') ?? 26);
            $proratedRequiredValidDays = (int) round($requiredValidDays * $segRatio);

            $monthlyTarget = (int) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'monthly_target') ?? 0);
            $proratedMonthlyTarget = (int) round($monthlyTarget * $segRatio);

            $totalMonthlyTarget += $proratedMonthlyTarget;
            $totalRequiredValidDays += $proratedRequiredValidDays;

            // Determine if there is a flat commission override for this driver
            $flatCommissionRate = SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'order_commission');
            if ($flatCommissionRate !== null) {
                $flatCommissionRate = (float) $flatCommissionRate * $segExchangeRate;
            }

            $segOrdersBonus = 0.0;
            $segBaseActual = 0.0;
            $segAbsenceDeduction = 0.0;

            if ($flatCommissionRate !== null && in_array($driverPaymentMethod, ['fixed', 'hybrid', 'per_order'])) {
                // Flat rate override
                $segOrdersBonus = $segOrders * $flatCommissionRate;
                if ($driverPaymentMethod === 'fixed') {
                    $workedDays = $segLogs->count();
                    $absentDays = max(0, $proratedRequiredWorkDays - $workedDays);
                    $dailyRate = $divisor > 0 ? ($fixedSalary / $divisor) : 0.0;
                    $segAbsenceDeduction = $absentDays * $dailyRate;
                    $baseSalary = max(0.0, $proratedFixedSalary - $segAbsenceDeduction);

                    // Deficit/Surplus Calculation
                    $deficitDeduction = 0.0;
                    $surplusBonusAmt = 0.0;
                    if ($proratedMonthlyTarget > 0) {
                        $deficitRate = $flatCommissionRate;
                        if ($segOrders < $proratedMonthlyTarget) {
                            $deficitDeduction = ($proratedMonthlyTarget - $segOrders) * $deficitRate;
                        } else {
                            $surplusBonus = (float) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'custom_monthly_bonus') ?? 0.0);
                            $surplusRate = (float) ($employee->premium_commission_rate ?? $segContract->premium_commission_rate ?? $deficitRate);
                            if ($surplusBonus > 0) {
                                $surplusBonusAmt = $surplusBonus;
                            } else {
                                $surplusBonusAmt = ($segOrders - $proratedMonthlyTarget) * $surplusRate;
                            }
                        }
                    }
                    $segBaseActual = $baseSalary - $deficitDeduction + $surplusBonusAmt;
                    $segOrdersBonus = 0.0;
                } elseif ($driverPaymentMethod === 'hybrid') {
                    $workedDays = $segLogs->count();
                    $absentDays = max(0, $proratedRequiredWorkDays - $workedDays);
                    $dailyRate = $divisor > 0 ? ($fixedSalary / $divisor) : 0.0;
                    $segAbsenceDeduction = $absentDays * $dailyRate;
                    $segBaseActual = max(0.0, $proratedFixedSalary - $segAbsenceDeduction);
                } elseif ($driverPaymentMethod === 'per_order') {
                    $segBaseActual = 0.0;
                    // segOrdersBonus is already set to $segOrders * $flatCommissionRate
                }
            } else {
                if ($driverPaymentMethod === 'fixed') {
                    $workedDays = $segLogs->count();
                    $absentDays = max(0, $proratedRequiredWorkDays - $workedDays);
                    $dailyRate = $divisor > 0 ? ($fixedSalary / $divisor) : 0.0;
                    $segAbsenceDeduction = $absentDays * $dailyRate;
                    $baseSalary = max(0.0, $proratedFixedSalary - $segAbsenceDeduction);

                    // Deficit/Surplus Calculation
                    $deficitDeduction = 0.0;
                    $surplusBonusAmt = 0.0;
                    if ($proratedMonthlyTarget > 0) {
                        $deficitRate = (float) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'order_commission') ?? 0.0);
                        if ($segOrders < $proratedMonthlyTarget) {
                            $deficitDeduction = ($proratedMonthlyTarget - $segOrders) * $deficitRate;
                        } else {
                            $surplusBonus = (float) (SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'custom_monthly_bonus') ?? 0.0);
                            $surplusRate = (float) ($employee->premium_commission_rate ?? $segContract->premium_commission_rate ?? $deficitRate);
                            if ($surplusBonus > 0) {
                                $surplusBonusAmt = $surplusBonus;
                            } else {
                                $surplusBonusAmt = ($segOrders - $proratedMonthlyTarget) * $surplusRate;
                            }
                        }
                    }
                    $segBaseActual = $baseSalary - $deficitDeduction + $surplusBonusAmt;
                } elseif ($driverPaymentMethod === 'per_order') {
                    $segOrdersBonus = $segLogsRecalculated->sum('driver_commission');
                    $segBaseActual = 0.0;
                } elseif ($driverPaymentMethod === 'hourly') {
                    $hourlyRate = SmartValueFallbackService::resolve($employeeId, $segContractId, $segment['end_date'], 'hourly_rate');
                    if ($hourlyRate === null) {
                        $hourlyRate = 0.0;
                    } else {
                        $hourlyRate = (float) $hourlyRate * $segExchangeRate;
                    }
                    $totalHours = (float) $segLogs->sum('online_hours');
                    $segBaseActual = $totalHours * $hourlyRate;
                } elseif ($driverPaymentMethod === 'hybrid') {
                    $workedDays = $segLogs->count();
                    $absentDays = max(0, $proratedRequiredWorkDays - $workedDays);
                    $dailyRate = $divisor > 0 ? ($fixedSalary / $divisor) : 0.0;
                    $segAbsenceDeduction = $absentDays * $dailyRate;
                    $segBaseActual = max(0.0, $proratedFixedSalary - $segAbsenceDeduction);
                    $segOrdersBonus = $segLogsRecalculated->sum('driver_commission');
                } elseif ($driverPaymentMethod === 'zones') {
                    $pricingRules = null;
                    if ($activeOverride && isset($activeOverride->custom_pricing_rules)) {
                        $pricingRules = $activeOverride->custom_pricing_rules;
                    } else {
                        $pricingRules = is_string($segContract->driver_pricing_rules)
                            ? json_decode($segContract->driver_pricing_rules, true)
                            : $segContract->driver_pricing_rules;
                        if (is_array($pricingRules)) {
                            if ($vehicleTypeId !== null && isset($pricingRules[$vehicleTypeId])) {
                                $pricingRules = $pricingRules[$vehicleTypeId];
                            } else {
                                $firstKey = array_key_first($pricingRules);
                                if ($firstKey !== null && isset($pricingRules[$firstKey]) && is_array($pricingRules[$firstKey]) && (isset($pricingRules[$firstKey]['payment_method']) || isset($pricingRules[$firstKey]['vehicle_type_id']))) {
                                    $pricingRules = $pricingRules[$firstKey];
                                }
                            }
                        }
                    }
                    if (is_array($pricingRules) && isset($pricingRules['zones'])) {
                        $pricingRules = $pricingRules['zones'];
                    }

                    $payout = 0.0;
                    foreach ($segLogs as $l) {
                        $cOrders = (int) $l->orders_count;
                        if ($cOrders <= 0) {
                            continue;
                        }

                        $notesData = $l->notes ? json_decode($l->notes, true) : null;
                        $zoneOrdersMap = (is_array($notesData) && isset($notesData['zone_orders']) && is_array($notesData['zone_orders']))
                            ? $notesData['zone_orders']
                            : [];

                        if (! empty($zoneOrdersMap)) {
                            foreach ($zoneOrdersMap as $zIdOrName => $zCount) {
                                $zCount = (int) $zCount;
                                if ($zCount <= 0) {
                                    continue;
                                }
                                $zRate = 0.0;
                                if (is_array($pricingRules)) {
                                    foreach ($pricingRules as $rule) {
                                        if (is_array($rule) && (
                                            (isset($rule['id']) && (string) $rule['id'] === (string) $zIdOrName) ||
                                            (isset($rule['name']) && $rule['name'] === $zIdOrName) ||
                                            (isset($rule['zone']) && $rule['zone'] === $zIdOrName)
                                        )) {
                                            $zRate = (float) ($rule['price'] ?? $rule['rate'] ?? 0.0);
                                            break;
                                        }
                                    }
                                }
                                $payout += $zCount * $zRate * $segExchangeRate;
                            }
                        } else {
                            $zoneName = $l->zone;
                            $zRate = 0.0;
                            if (is_array($pricingRules)) {
                                foreach ($pricingRules as $rule) {
                                    if (is_array($rule) && (
                                        (isset($rule['id']) && (string) $rule['id'] === (string) $zoneName) ||
                                        (isset($rule['name']) && $rule['name'] === $zoneName) ||
                                        (isset($rule['zone']) && $rule['zone'] === $zoneName)
                                    )) {
                                        $zRate = (float) ($rule['price'] ?? $rule['rate'] ?? 0.0);
                                        break;
                                    }
                                }
                            }
                            $payout += $cOrders * $zRate * $segExchangeRate;
                        }
                    }

                    $segOrdersBonus = round($payout, 3);
                    $segBaseActual = 0.0;
                } elseif ($driverPaymentMethod === 'zones_tiers') {
                    $pricingRules = null;
                    if ($activeOverride && isset($activeOverride->custom_pricing_rules)) {
                        $pricingRules = $activeOverride->custom_pricing_rules;
                    } else {
                        $pricingRules = is_string($segContract->driver_pricing_rules)
                            ? json_decode($segContract->driver_pricing_rules, true)
                            : $segContract->driver_pricing_rules;
                        if (is_array($pricingRules)) {
                            if ($vehicleTypeId !== null && isset($pricingRules[$vehicleTypeId])) {
                                $pricingRules = $pricingRules[$vehicleTypeId];
                            } else {
                                $firstKey = array_key_first($pricingRules);
                                if ($firstKey !== null && isset($pricingRules[$firstKey]) && is_array($pricingRules[$firstKey]) && (isset($pricingRules[$firstKey]['payment_method']) || isset($pricingRules[$firstKey]['vehicle_type_id']))) {
                                    $pricingRules = $pricingRules[$firstKey];
                                }
                            }
                        }
                    }
                    if (is_array($pricingRules) && isset($pricingRules['zones_tiers'])) {
                        $pricingRules = $pricingRules['zones_tiers'];
                    }

                    $payout = 0.0;
                    $groupedLogs = $segLogs->groupBy('zone');
                    foreach ($groupedLogs as $zoneName => $zoneLogs) {
                        $zoneOrders = $zoneLogs->sum('orders_count');
                        $zoneTiers = null;
                        if (is_array($pricingRules)) {
                            if (isset($pricingRules[$zoneName]) && is_array($pricingRules[$zoneName])) {
                                $zoneTiers = $pricingRules[$zoneName];
                            } else {
                                foreach ($pricingRules as $rule) {
                                    if (is_array($rule) && (isset($rule['zone']) || isset($rule['name'])) && ($rule['zone'] ?? $rule['name']) == $zoneName) {
                                        $zoneTiers = $rule['tiers'] ?? null;
                                        break;
                                    }
                                }
                            }
                        }

                        $selectedPrice = 0.0;
                        if (is_array($zoneTiers)) {
                            foreach ($zoneTiers as $tier) {
                                $min = (int) round(($tier['min_orders'] ?? $tier['min'] ?? 0) * $segRatio);
                                $price = (float) ($tier['price'] ?? $tier['rate'] ?? $tier['bonus'] ?? 0.0);
                                if ($zoneOrders >= $min) {
                                    $selectedPrice = $price;
                                }
                            }
                        }

                        if ($selectedPrice === 0.0) {
                            $selectedPrice = (float) ($segContract->default_order_commission ?? 0.0);
                        }
                        $payout += $zoneOrders * $selectedPrice * $segExchangeRate;
                    }
                    $segOrdersBonus = $payout;
                    $segBaseActual = 0.0;
                } elseif ($driverPaymentMethod === 'tiers') {
                    $pricingRules = null;
                    if ($activeOverride && isset($activeOverride->custom_pricing_rules)) {
                        $pricingRules = $activeOverride->custom_pricing_rules;
                    } else {
                        $pricingRules = is_string($segContract->driver_pricing_rules)
                            ? json_decode($segContract->driver_pricing_rules, true)
                            : $segContract->driver_pricing_rules;
                        if (is_array($pricingRules)) {
                            if ($vehicleTypeId !== null && isset($pricingRules[$vehicleTypeId])) {
                                $pricingRules = $pricingRules[$vehicleTypeId];
                            } else {
                                $firstKey = array_key_first($pricingRules);
                                if ($firstKey !== null && isset($pricingRules[$firstKey]) && is_array($pricingRules[$firstKey]) && (isset($pricingRules[$firstKey]['payment_method']) || isset($pricingRules[$firstKey]['vehicle_type_id']))) {
                                    $pricingRules = $pricingRules[$firstKey];
                                }
                            }
                        }
                    }
                    if (is_array($pricingRules) && isset($pricingRules['tiers'])) {
                        $pricingRules = $pricingRules['tiers'];
                    }

                    $selectedPrice = 0.0;
                    if (is_array($pricingRules)) {
                        foreach ($pricingRules as $tier) {
                            $min = (int) round(($tier['min_orders'] ?? $tier['min'] ?? 0) * $segRatio);
                            $price = (float) ($tier['price'] ?? $tier['rate'] ?? $tier['bonus'] ?? 0.0);
                            if ($segOrders >= $min) {
                                $selectedPrice = $price;
                            }
                        }
                    }
                    if ($selectedPrice === 0.0) {
                        $selectedPrice = (float) ($segContract->default_order_commission ?? 0.0);
                    }
                    $segOrdersBonus = $segOrders * $selectedPrice * $segExchangeRate;
                    $segBaseActual = 0.0;
                }
            }

            $baseActual += $segBaseActual;
            $ordersBonus += $segOrdersBonus;
            $absenceDeduction += $segAbsenceDeduction;
        }

        // 4. Calculate Auto-Validity (Final Monthly Status)
        $status = 'Valid';
        if ($contractId && $contract) {
            $workedValidDays = $empLogs->where('shift_valid', 1)->count();

            $meetsOrdersTarget = ($totalOrders >= $totalMonthlyTarget);

            if ($contract->is_validity_enabled) {
                $daysInMonthVal = Carbon::parse($startDate)->daysInMonth;
                $validAttendanceDays = $empLogs->where('is_valid', true)->count();
                $attendanceRate = $daysInMonthVal > 4 ? ($validAttendanceDays / ($daysInMonthVal - 4)) : 1.0;
                $attendanceRate = min(1.0, $attendanceRate);
                $meetsValidDays = ($attendanceRate >= 0.90);
            } else {
                $meetsValidDays = ($workedValidDays >= $totalRequiredValidDays);
            }

            // Check mandatory periods
            $meetsMandatoryPeriods = true;
            $monthlyParam = ContractMonthlyParameter::where('contract_id', $contractId)
                ->where('year', $year)
                ->where('month', $month)
                ->first();

            if ($monthlyParam) {
                $mandatoryPeriods = ContractMandatoryDay::where('contract_monthly_parameter_id', $monthlyParam->id)->get();
                foreach ($mandatoryPeriods as $period) {
                    $periodStart = Carbon::parse($period->start_date)->toDateString();
                    $periodEnd = Carbon::parse($period->end_date)->toDateString();
                    $periodLogsCount = $empLogs->whereBetween('log_date', [$periodStart, $periodEnd])
                        ->where('shift_valid', 1)
                        ->count();
                    if ($periodLogsCount < $period->min_required_days) {
                        $meetsMandatoryPeriods = false;
                        break;
                    }
                }
            }

            if (! $meetsOrdersTarget || ! $meetsValidDays || ! $meetsMandatoryPeriods) {
                $status = 'Invalid';
            }
        }

        if ($existingSlip && $existingSlip->final_monthly_status === 'protected') {
            $status = 'Protected';
        }

        // 5. Calculate Capacity and Experience Incentives
        $totalCapacityIncentive = 0.0;
        $totalExperienceIncentive = 0.0;

        $attendanceRate = 0.0;
        $isValidDA = false;
        $shouldCalculateIncentives = false;

        if ($contractId && $contract) {
            if ($contract->is_validity_enabled) {
                $daysInMonth = Carbon::parse($startDate)->daysInMonth;
                $validAttendanceDays = $empLogs->where('is_valid', true)->count();
                $attendanceRate = $daysInMonth > 4 ? ($validAttendanceDays / ($daysInMonth - 4)) : 1.0;
                $attendanceRate = min(1.0, $attendanceRate);
                $isValidDA = ($attendanceRate >= 0.90);
                $shouldCalculateIncentives = $isValidDA;
            } else {
                $shouldCalculateIncentives = ($status !== 'Invalid');
            }
        }

        if ($shouldCalculateIncentives) {
            $monthlyParam = ContractMonthlyParameter::where('contract_id', $contractId)
                ->where('year', $year)
                ->where('month', $month)
                ->first();

            if ($monthlyParam) {
                // Capacity Incentive
                if ($monthlyParam->capacity_incentive_rules) {
                    $rules = is_string($monthlyParam->capacity_incentive_rules)
                        ? json_decode($monthlyParam->capacity_incentive_rules, true)
                        : $monthlyParam->capacity_incentive_rules;
                    if (is_array($rules)) {
                        foreach ($rules as $tier) {
                            $min = $tier['min_orders'] ?? 0;
                            $max = $tier['max_orders'] ?? INF;
                            $bonus = (float) ($tier['bonus'] ?? 0);
                            if ($totalOrders >= $min && $totalOrders <= $max) {
                                $totalCapacityIncentive = $bonus * $exchangeRate;
                            }
                        }
                    }
                }

                // Experience Incentive
                if ($monthlyParam->experience_incentive_rules) {
                    $rules = is_string($monthlyParam->experience_incentive_rules)
                        ? json_decode($monthlyParam->experience_incentive_rules, true)
                        : $monthlyParam->experience_incentive_rules;
                    $monthsTenure = Carbon::parse($employee->date_of_joining)->diffInMonths(Carbon::parse($startDate));
                    if (is_array($rules)) {
                        foreach ($rules as $tier) {
                            $minMonths = $tier['min_months'] ?? 0;
                            $bonus = (float) ($tier['bonus'] ?? 0);
                            $bonusPerOrder = (float) ($tier['bonus_per_order'] ?? 0);
                            if ($monthsTenure >= $minMonths) {
                                $factor = ($contract && $contract->is_validity_enabled) ? $attendanceRate : 1.0;
                                $totalExperienceIncentive = ($bonus + ($bonusPerOrder * $totalOrders)) * $exchangeRate * $factor;
                            }
                        }
                    }
                }
            }
        }

        // 6. Calculate Contract Bonuses
        $totalContractBonuses = 0.0;
        if ($contractId) {
            $bonuses = \DB::table('contract_bonuses')
                ->where('contract_id', $contractId)
                ->get();
            foreach ($bonuses as $b) {
                if ($b->is_valid_drivers_only && $status === 'Invalid') {
                    continue;
                }
                $totalContractBonuses += (float) $b->amount * $exchangeRate;
            }
        }

        // 7. Deductions and Allowances
        $violationsDeduction = (float) ($violationSums[$employeeId] ?? 0);
        $maintenanceDeduction = (float) ($maintenanceSums[$employeeId] ?? 0);
        $custodyDeduction = (float) ($custodySums[$employeeId] ?? 0);
        $driverExpenseDeduction = (float) ($driverExpenseSums[$employeeId] ?? 0);

        $leaveInfo = $leaveData[$employeeId] ?? null;
        $leaveDeduction = (float) ($leaveInfo?->total_deduction ?? 0);
        $unpaidLeaveDays = (int) ($leaveInfo?->total_days ?? 0);

        $advanceDeduction = 0.0;
        $activeAdvances = $allAdvances->get($employeeId, collect());
        foreach ($activeAdvances as $advance) {
            $installment = min((float) $advance->monthly_installment, (float) $advance->remaining_balance);
            if ($installment <= 0) {
                continue;
            }
            $advanceDeduction += $installment;
        }

        $fuelAllowance = 0.0;
        $empAssignments = $allAssignments[$employeeId] ?? collect();
        foreach ($empAssignments as $a) {
            $fuelAllowance += (float) ($a->monthly_fuel_allowance ?? 0);
        }

        return [
            'base_actual' => $baseActual,
            'orders_bonus' => $ordersBonus,
            'fuel_allowance' => $fuelAllowance,
            'total_orders' => $totalOrders,
            'violations_deduction' => $violationsDeduction,
            'maintenance_deduction' => $maintenanceDeduction,
            'custody_deduction' => $custodyDeduction,
            'driver_expense_deduction' => $driverExpenseDeduction,
            'advance_deduction' => $advanceDeduction,
            'leave_deduction' => $leaveDeduction,
            'unpaid_leave_days' => $unpaidLeaveDays,
            'final_monthly_status' => $status,
            'total_capacity_incentive' => $totalCapacityIncentive,
            'total_experience_incentive' => $totalExperienceIncentive,
            'total_contract_bonuses' => $totalContractBonuses,
            'base_actual_salary' => $baseActual,
            'total_absence_deduction' => $absenceDeduction,
        ];
    }

    /**
     * Price a month one override-segment at a time and add the segments together.
     *
     * A driver whose override starts mid-month is paid by the contract rule up to that date and
     * by the override from it on. Previously one override that merely touched the month repriced
     * all of it, including days it did not cover.
     *
     * @param  array<string, array{override: ?DriverContractOverride, logs: Collection}>  $segments
     * @return array<string, mixed>
     */
    private static function sumContractPayrollSegments(
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
                $segVtId
            );

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

    /**
     * GET /api/payroll/contract-sheet/{contract}
     * Contract-Centric Payroll Sheet API
     */
    public function contractSheet(Request $request, $contractId): JsonResponse
    {
        if (! $request->user()->can('contract_payroll.view') && ! $request->user()->can('payroll.view') && ! $request->user()->can('contracts.view')) {
            return response()->json(['message' => 'غير مصرح لك باستعراض كشف رواتب العقود.'], 403);
        }

        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $daysInMonth = Carbon::parse($startDate)->daysInMonth;
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
        $companyId = app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1);

        $contract = Contract::withoutGlobalScopes()->find($contractId);
        if (! $contract) {
            return response()->json(['message' => 'العقد غير موجود.'], 404);
        }

        // Get contract assignments active during this month
        $assignments = ContractAssignment::withoutGlobalScopes()
            ->where('contract_id', $contractId)
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
            ->where('contract_id', $contractId)
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
                    'contract_id' => $contractId,
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
        // consolidatedSheet(), so a driver working under several contracts in the same month
        // is never charged the same instalment more than once.
        // whereNull('deleted_at') is explicit because withoutGlobalScopes() strips the SoftDeletes
        // scope along with the company one: a fine entered against the wrong driver and then
        // deleted was still being taken off him here, and frozen into the month if it was approved.
        //
        // A fine belongs to the contract it was raised against. This query used to ignore
        // charge_contract_id entirely, so a driver working two contracts in one month had every
        // fine taken off him on BOTH sheets — a 20.000 fine explicitly charged to one contract was
        // still deducted on the other, and the two sheets together took double what he owed.
        //
        // An older fine may carry no contract at all. Those are placed on the driver's only
        // contract for the month; a driver who worked several is owed the charge once, against the
        // person, which is what the consolidated sheet settles.
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
            if ($effStartCarbon->gt($effEndCarbon)) {
                $assignedDays = 0;
            } else {
                $assignedDays = $effStartCarbon->diffInDays($effEndCarbon) + 1;
            }
            $segRatio = $daysInMonth > 0 ? min(1.0, max(0.0, $assignedDays / $daysInMonth)) : 1.0;

            // Driver's logs for this contract, bounded by the assignment window.
            // $effStart/$effEnd were computed above and then never used, so a log dated outside
            // the driver's own assignment was still paid — which is how a sheet could read
            // "13 days worked out of 12 assigned". bulkStore already refuses to save those days.
            $contractLogs = $allLogs->get($empId, collect())->where('contract_id', $contractId);
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

            // Which override, if any, covers a given day. effective_from/effective_to used to be
            // treated as a switch: any overlap with the month applied the override to all 31 days,
            // so an override configured for a single day repriced the whole month.
            $overridesForAssignment = $assignment->overrides ?: collect();
            $overrideForDate = function (string $date) use ($overridesForAssignment) {
                return $overridesForAssignment->first(function ($ov) use ($date) {
                    $ovStart = $ov->effective_from ? substr((string) $ov->effective_from, 0, 10) : null;
                    $ovEnd = $ov->effective_to ? substr((string) $ov->effective_to, 0, 10) : null;

                    return (! $ovStart || $ovStart <= $date) && (! $ovEnd || $ovEnd >= $date);
                });
            };

            // Split the month into stretches of days that share an override AND a vehicle type.
            // Vehicle types driven this month, read from the logs rather than from whatever
            // vehicle is assigned right now — closing an assignment must not reprice a past month.
            $vtIds = $empLogs->pluck('vehicle_id')->filter()
                ->map(fn ($vid) => $vehicleTypeById[$vid] ?? null)
                ->filter()->unique()->values();
            $vehicleTypeIsMixed = $vtIds->count() > 1;
            $vtId = $vtIds->count() === 1 ? (int) $vtIds->first() : null;

            // Pricing rules are per vehicle type, so a driver who spent part of the month on a
            // small car and the rest on a large one has two prices, not one — and picking either
            // for the whole month is a guess. It used to resolve to no type at all and pay the
            // month 0.000 with every order unpriced, even though the contract had a rule for both.
            // Each stretch now carries its own type and is priced by its own rule, which also
            // means a tier is chosen by the orders run on that vehicle, not by the month's total.
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

            // Route to ContractPayrollService, once per stretch of days that share an override.
            // A month with no override, or one override covering all of it, is a single segment
            // and behaves exactly as before.
            $calcResult = self::sumContractPayrollSegments(
                $employee,
                $contract,
                $assignment,
                $segments,
                $vtId,
                $vehicleTypeNames
            );

            // An override replaces the payment method outright, so the sheet shows a number the
            // contract's own pricing never produced. Running the same month once more without the
            // override gives the reader something to check it against.
            $contractDefaultGross = null;
            if ($activeOverride) {
                try {
                    // Split by vehicle type here too, or the comparison figure is the 0.000 the
                    // priced month no longer produces, and the override looks like free money.
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

                    $defaultCalc = self::sumContractPayrollSegments(
                        $employee,
                        $contract,
                        $assignment,
                        $defaultSegments ?: [['override' => null, 'vt_id' => $vtId, 'logs' => collect()]],
                        $vtId,
                        $vehicleTypeNames
                    );

                    $contractDefaultGross = round((float) ($defaultCalc['gross_contract_earnings'] ?? 0), 3);
                } catch (\Throwable $e) {
                    \Log::warning('Contract-default projection failed for employee '.$empId.': '.$e->getMessage());
                }
            }

            // Deductions calculation for contract level (Traffic Violations ONLY).
            // `driver_deduction` mirrors `driver_share`, so a company-liable fine is 0 by design.
            //
            // A fine already marked `is_deducted` was collected elsewhere — the legacy payroll
            // run, or a previously approved consolidated month. Both payroll paths are in use,
            // so charging it again here would take the same fine off the driver twice.
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

            $driversResult[] = [
                'employee_id' => $empId,
                'employee_name' => $employee->name,
                'employee_number' => $employee->employee_number,
                'payment_method' => $driverPaymentMethod,
                'payment_method_label' => self::getPaymentMethodLabel($driverPaymentMethod),
                'has_override' => (bool) $activeOverride,
                // An inactive assignment is reported, not dropped: this driver logged real work,
                // and silently removing a month of earned pay is worse than an unexpected row.
                'assignment_status' => $assignment->status,
                'out_of_window_logs' => $outOfWindow->count(),
                'out_of_window_orders' => $outOfWindowOrders,
                'out_of_window_dates' => $outOfWindowDates,
                // Mixed is now a fact about the month, not a reason to pay nothing: each type is
                // priced by its own rule. Unresolved is the real problem — a day whose vehicle
                // type the contract has no rule for, which earns nothing and must say so.
                'vehicle_type_is_mixed' => $vehicleTypeIsMixed,
                'vehicle_type_ids' => $vtIds->all(),
                'unresolved_vehicle_type' => (bool) ($calcResult['unresolved_vehicle_type'] ?? false),
                'assigned_days' => $assignedDays,
                'actual_work_days' => $actualWorkDays,
                'days_ratio' => round($segRatio, 4),
                'orders_count' => $calcResult['orders_count'] ?? $empLogs->sum('orders_count'),
                'base_salary' => $calcResult['base_salary'] ?? 0.0,
                'orders_bonus' => $calcResult['orders_bonus'] ?? 0.0,
                'deficit_deduction' => $calcResult['deficit_deduction'] ?? 0.0,
                'surplus_bonus' => $calcResult['surplus_bonus'] ?? 0.0,
                'absence_deduction' => $calcResult['absence_deduction'] ?? 0.0,
                'gross_contract_earnings' => $grossEarnings,
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
                'contract_default_gross' => $contractDefaultGross,
                'override_delta' => $contractDefaultGross === null
                    ? null
                    : round($grossEarnings - $contractDefaultGross, 3),
                'calculation_details' => $calcDetails,
            ];

            $totalOrdersSum += ($calcResult['orders_count'] ?? $empLogs->sum('orders_count'));
            $totalEarningsSum += $grossEarnings;
            $totalDeductionsSum += $totalContractDeductions;
            $totalAdjustmentsSum += $netAdjustment;
            $totalNetSum += $netPayout;
        }

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

                return response()->json($frozen);
            }
        }

        return response()->json([
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
        ]);
    }

    /**
     * Work this month that no pricing rule covers — the one thing that must stop a sheet being
     * approved.
     *
     * It happens when a contract's driver payment method is changed part-way through a month: the
     * rules that arrive describe the new arrangement, and whatever the month already holds that
     * they do not cover is left with no applicable price. The driver is then paid nothing for those
     * orders, and approving freezes that as what he was owed. Somebody has to go and complete the
     * pricing first; this is what makes them.
     *
     * The test is ORDERS, not the flag. `unresolved_vehicle_type` is also true for a driver who
     * simply never worked — no vehicle, so no type — and there is nothing wrong with his month.
     * Gating on the flag would have blocked every contract carrying an idle driver, which is most
     * of them. An unpriced line that carries no orders costs nobody anything.
     *
     * @param  array<int, array<string, mixed>>  $drivers
     * @return array<int, array<string, mixed>>
     */
    private static function approvalBlockers(array $drivers): array
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
     * POST /api/payroll/contract-sheet/{contract}/approve
     * Approve and freeze contract payroll sheet for a month.
     */
    public function approveContractSheet(Request $request, $contractId): JsonResponse
    {
        // Approving freezes a month's pay. `contract_payroll.edit` deliberately does NOT grant
        // it — editing a sheet and signing it off are different authorities.
        if (! $request->user()->can('contract_payroll.approve') && ! $request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك باعتماد كشف رواتب العقد.'], 403);
        }

        $contract = Contract::findOrFail($contractId);
        $companyId = app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1);
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));
        $notes = $request->input('notes');

        // Run live calculation of contract sheet to create fresh snapshot
        $sheetRes = $this->contractSheet($request, $contract->id);
        $data = json_decode($sheetRes->getContent(), true);

        $summary = $data['summary'] ?? [];
        $drivers = $data['drivers'] ?? [];

        // Work with no price on it cannot be signed off. Approving freezes what each driver was
        // owed, and a driver whose orders no rule covers is frozen at nothing for them — a figure
        // that can only be corrected by reopening the month. The pricing gets completed first.
        // A month already approved is served from its frozen snapshot, and re-approving it only
        // rewrites the same figures. It is not re-judged here: this gate is about what is being
        // frozen now, not about re-opening what somebody already signed.
        $blockers = empty($data['is_approved']) ? self::approvalBlockers($drivers) : [];

        if (! empty($blockers)) {
            $totalUnpriced = array_sum(array_column($blockers, 'unpriced_orders'));

            return response()->json([
                'message' => 'لا يمكن اعتماد الكشف: '.$totalUnpriced.' طلب لدى '.count($blockers)
                    .' سائق لا تنطبق عليها أي قاعدة تسعير في هذا العقد. أكمل التسعير أولاً — الاعتماد يجمّد أجر هؤلاء عند صفر لهذه الطلبات.',
                'approval_blockers' => $blockers,
            ], 422);
        }

        $run = ContractPayrollRun::updateOrCreate(
            [
                'company_id' => $companyId,
                'contract_id' => $contract->id,
                'year' => $year,
                'month' => $month,
            ],
            [
                'status' => 'approved',
                'total_drivers' => count($drivers),
                'total_orders' => (int) ($summary['total_orders'] ?? 0),
                'total_gross_earnings' => (float) ($summary['total_gross_earnings'] ?? 0.0),
                'total_violations_deductions' => (float) ($summary['total_violations_deductions'] ?? $summary['total_global_deductions'] ?? 0.0),
                'total_net_payout' => (float) ($summary['total_net_payout'] ?? 0.0),
                'snapshot_data' => $data,
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
                'notes' => $notes,
            ]
        );

        return response()->json([
            'message' => "تم اعتماد وتجميد كشف رواتب العقد ({$contract->name}) لشهر {$month}/{$year} بنجاح 🔒",
            'run' => $run->load('approvedBy:id,name'),
        ]);
    }

    /**
     * POST /api/payroll/contract-sheet/{contract}/unapprove
     * Unapprove (un-freeze) contract payroll sheet.
     */
    public function unapproveContractSheet(Request $request, $contractId): JsonResponse
    {
        // Unapproving deletes the frozen snapshot, so it needs at least the authority that
        // created it. This was previously ungated: anyone the route let through could destroy
        // an approved month's record, which defeated the point of a separate approve permission.
        if (! $request->user()->can('contract_payroll.approve') && ! $request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك بفك اعتماد كشف رواتب العقد.'], 403);
        }

        $contract = Contract::findOrFail($contractId);
        $companyId = app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1);
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));

        $run = ContractPayrollRun::where('company_id', $companyId)
            ->where('contract_id', $contract->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($run) {
            $run->delete();
        }

        return response()->json([
            'message' => "تم فك تجميد واعتماد كشف رواتب العقد ({$contract->name}) لشهر {$month}/{$year} بنجاح.",
        ]);
    }

    /**
     * GET /api/payroll/contract-sheet/{contract}/adjustments
     */
    public function getContractAdjustments(Request $request, $contractId): JsonResponse
    {
        $contract = Contract::findOrFail($contractId);
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));

        $adjustments = ContractPayrollAdjustment::withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->where('year', $year)
            ->where('month', $month)
            ->with(['employee:id,name,employee_number', 'createdBy:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($adjustments);
    }

    /**
     * POST /api/payroll/contract-sheet/{contract}/adjustments
     */
    public function storeContractAdjustment(Request $request, $contractId): JsonResponse
    {
        if (! $request->user()->can('contract_payroll.edit') && ! $request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك بإضافة تسويات رواتب.'], 403);
        }

        $contract = Contract::findOrFail($contractId);
        $companyId = app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1);

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'year' => 'required|integer|min:2020|max:2030',
            'month' => 'required|integer|min:1|max:12',
            'type' => 'required|in:addition,deduction',
            'amount' => 'required|numeric|min:0.001',
            'reason' => 'required|string|max:500',
        ]);

        // Check if contract is already approved/frozen for this month
        $approvedRun = ContractPayrollRun::where('company_id', $companyId)
            ->where('contract_id', $contract->id)
            ->where('year', $validated['year'])
            ->where('month', $validated['month'])
            ->where('status', 'approved')
            ->first();

        if ($approvedRun) {
            return response()->json(['message' => 'لا يمكن إضافة تسوية لأن كشف رواتب العقد معتمد ومجمد.'], 422);
        }

        $adjustment = ContractPayrollAdjustment::create([
            'company_id' => $companyId,
            'contract_id' => $contract->id,
            'employee_id' => $validated['employee_id'],
            'year' => $validated['year'],
            'month' => $validated['month'],
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'reason' => $validated['reason'],
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'تمت إضافة التسوية بنجاح.',
            'adjustment' => $adjustment->load(['employee:id,name,employee_number', 'createdBy:id,name']),
        ], 201);
    }

    /**
     * DELETE /api/payroll/contract-sheet/adjustments/{adjustment}
     */
    public function destroyContractAdjustment(Request $request, $adjustmentId): JsonResponse
    {
        if (! $request->user()->can('contract_payroll.edit') && ! $request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك بحذف تسويات رواتب.'], 403);
        }

        $adjustment = ContractPayrollAdjustment::findOrFail($adjustmentId);

        $approvedRun = ContractPayrollRun::where('contract_id', $adjustment->contract_id)
            ->where('year', $adjustment->year)
            ->where('month', $adjustment->month)
            ->where('status', 'approved')
            ->first();

        if ($approvedRun) {
            return response()->json(['message' => 'لا يمكن حذف التسوية لأن الكشف معتمد ومجمد.'], 422);
        }

        $adjustment->delete();

        return response()->json(['message' => 'تم حذف التسوية بنجاح.']);
    }

    /**
     * GET /api/payroll/consolidated/{year}/{month}
     * Consolidated Monthly Payroll Sheet based strictly on Approved Contract Payroll Runs.
     */
    public function consolidatedSheet(Request $request, $year, $month): JsonResponse
    {
        $year = (int) $year;
        $month = (int) $month;
        $companyId = app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1);

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
                        'actual_work_days' => $d['actual_work_days'] ?? 0,
                        'orders_count' => $d['orders_count'] ?? 0,
                        'gross_contract_earnings' => (float) ($d['gross_contract_earnings'] ?? 0.0),
                        'violations_deduction' => (float) ($d['violations_deduction'] ?? 0.0),
                        'manual_adjustments' => $adjTotal,
                        'contracts_worked' => [],
                    ];
                } else {
                    $consolidatedDrivers[$empId]['assigned_days'] = max($consolidatedDrivers[$empId]['assigned_days'], $d['assigned_days'] ?? 0);
                    $consolidatedDrivers[$empId]['actual_work_days'] += ($d['actual_work_days'] ?? 0);
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

        // Every charge that belongs to the person rather than to a contract: traffic fines,
        // driver-liable maintenance, damaged or lost custody, driver-borne expenses, unpaid
        // leave and salary-advance instalments. Resolved once per employee — so a driver on
        // several contracts is charged once — and never including anything the ledger shows
        // as already collected.
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

            return response()->json($frozen);
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

        return response()->json([
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
            }),
            'unapproved_contracts' => $unapprovedContracts,
            'drivers' => $driversList,
        ]);
    }

    /**
     * POST /api/payroll/consolidated/{year}/{month}/approve
     *
     * Freezes the company-wide sheet and commits the deductions it projected: traffic
     * fines are marked deducted and salary-advance instalments are recorded against the
     * run, paying the advance down. This is the only place either happens on the contract
     * payroll path, so an unapproved month never touches a driver's balance.
     */
    public function approveConsolidatedSheet(Request $request, $year, $month): JsonResponse
    {
        if (! $request->user()->can('contract_payroll.approve') && ! $request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك باعتماد كشف الرواتب المجمّع.'], 403);
        }

        $year = (int) $year;
        $month = (int) $month;
        $companyId = app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1);

        $existing = ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'approved')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => "كشف الرواتب المجمّع لشهر {$month}/{$year} معتمد بالفعل. افكّ الاعتماد أولاً لإعادة احتسابه.",
            ], 422);
        }

        // Months may be closed in any order. A month approved late is not stranded: the charges it
        // finds are whatever is still outstanding when it runs, and what it cannot collect stays on
        // the driver and is taken by the next month approved after it. Calendar order is not what
        // links the months together — approval order is.

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, Carbon::parse($startDate)->daysInMonth);

        // Re-run the projection so the frozen snapshot reflects the data as of approval.
        $data = json_decode($this->consolidatedSheet($request, $year, $month)->getContent(), true);
        $drivers = $data['drivers'] ?? [];

        if (empty($drivers)) {
            return response()->json([
                'message' => 'لا يوجد سائقون في الكشف المجمّع لهذا الشهر. اعتمد كشوف العقود أولاً.',
            ], 422);
        }

        $run = \DB::transaction(function () use ($request, $companyId, $year, $month, $startDate, $endDate, $drivers, $data) {
            $run = ConsolidatedPayrollRun::create([
                'company_id' => $companyId,
                'year' => $year,
                'month' => $month,
                'status' => 'approved',
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
                'notes' => $request->input('notes'),
            ]);

            $employeeIds = array_values(array_filter(array_column($drivers, 'employee_id')));

            // Re-resolve rather than trusting the projection passed in: between opening the
            // sheet and pressing approve, a fine may have been settled or an advance closed.
            $pending = CompanyDeductionService::pendingFor($employeeIds, $startDate, $endDate, $year, $month);

            $committedByEmployee = [];
            $chargedViolationIds = [];
            $chargedExpenseIds = [];

            foreach ($pending as $employeeId => $bucket) {
                $employee = Employee::withoutGlobalScopes()->find($employeeId);

                foreach ($bucket['items'] as $item) {
                    $amount = (float) $item['amount'];
                    $type = $item['source_type'];
                    $sourceId = $item['source_id'];

                    if ($type === ConsolidatedPayrollDeduction::SOURCE_ADVANCE) {
                        $advance = SalaryAdvance::withoutGlobalScopes()->find($sourceId);
                        if (! $advance) {
                            continue;
                        }
                        // The final instalment collects only the principal that is left.
                        $amount = min($amount, (float) $advance->remaining_balance);
                        if ($amount <= 0) {
                            continue;
                        }

                        AdvanceDeduction::create([
                            'salary_advance_id' => $advance->id,
                            'payroll_slip_id' => null,
                            'consolidated_run_id' => $run->id,
                            'amount' => $amount,
                            'deduction_date' => $endDate,
                            'company_id' => $advance->company_id,
                        ]);

                        $advance->paid_installments = (int) $advance->paid_installments + 1;
                        $advance->remaining_balance = max(0, (float) $advance->remaining_balance - $amount);
                        if ($advance->remaining_balance <= 0) {
                            $advance->status = 'completed';
                        }
                        $advance->saveQuietly();
                    }

                    if ($type === ConsolidatedPayrollDeduction::SOURCE_VIOLATION) {
                        $chargedViolationIds[] = $sourceId;
                    }
                    if ($type === ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE) {
                        $chargedExpenseIds[] = $sourceId;
                    }

                    ConsolidatedPayrollDeduction::create([
                        'company_id' => $employee?->company_id ?? $companyId,
                        'consolidated_run_id' => $run->id,
                        'employee_id' => $employeeId,
                        'source_type' => $type,
                        'source_id' => $sourceId,
                        'amount' => $amount,
                        'label' => $item['label'],
                    ]);

                    $committedByEmployee[$employeeId] = round(($committedByEmployee[$employeeId] ?? 0.0) + $amount, 3);
                }
            }

            // Keep the legacy flags in step so the old payroll path also sees these as settled.
            // Only what THIS run charged is flagged — anything already true was collected
            // elsewhere, and unapproving must not release someone else's deduction.
            if (! empty($chargedViolationIds)) {
                Violation::withoutGlobalScopes()->whereIn('id', $chargedViolationIds)->update(['is_deducted' => true]);
            }
            if (! empty($chargedExpenseIds)) {
                DriverExpense::withoutGlobalScopes()->whereIn('id', $chargedExpenseIds)->update(['is_deducted' => true]);
            }

            // Freeze the sheet as it stands AFTER the deductions were applied. Re-projecting
            // an approved month would forget what it collected the moment an advance closes.
            $ledger = ConsolidatedPayrollDeduction::withoutGlobalScopes()
                ->where('consolidated_run_id', $run->id)
                ->get()
                ->groupBy('employee_id');

            $totals = ['gross' => 0.0, 'adjustments' => 0.0, 'net' => 0.0, 'orders' => 0, 'deductions' => 0.0];
            $byType = [];

            $typeKeys = [
                'violations' => ConsolidatedPayrollDeduction::SOURCE_VIOLATION,
                'maintenance' => ConsolidatedPayrollDeduction::SOURCE_MAINTENANCE,
                'custody' => ConsolidatedPayrollDeduction::SOURCE_CUSTODY,
                'driver_expenses' => ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE,
                'advances' => ConsolidatedPayrollDeduction::SOURCE_ADVANCE,
            ];

            foreach ($drivers as $i => $d) {
                $empId = $d['employee_id'] ?? null;
                $rows = $ledger->get($empId, collect());
                $charged = round((float) $rows->sum('amount'), 3);

                $gross = round((float) ($d['gross_contract_earnings'] ?? 0.0), 3);
                $adjustments = round((float) ($d['manual_adjustments_total'] ?? 0.0), 3);
                $net = round($gross + $adjustments - $charged, 3);

                $drivers[$i]['deductions_applied'] = true;
                $drivers[$i]['final_net_payout'] = $net;
                $drivers[$i]['deductions_total'] = $charged;
                $drivers[$i]['pending_deductions_total'] = $charged;
                $drivers[$i]['deduction_items'] = $rows->map(fn ($r) => [
                    'source_type' => $r->source_type,
                    'source_id' => $r->source_id,
                    'amount' => (float) $r->amount,
                    'label' => $r->label,
                ])->values()->all();

                foreach ($typeKeys as $key => $type) {
                    $amount = round((float) $rows->where('source_type', $type)->sum('amount'), 3);
                    $drivers[$i]["{$key}_deduction"] = $amount;
                    $drivers[$i]["pending_{$key}_deduction"] = $amount;
                    $byType[$key] = round(($byType[$key] ?? 0.0) + $amount, 3);
                }

                $totals['gross'] += $gross;
                $totals['adjustments'] += $adjustments;
                $totals['deductions'] += $charged;
                $totals['orders'] += (int) ($d['orders_count'] ?? 0);
                $totals['net'] += $net;
            }

            $data['drivers'] = $drivers;
            $data['is_approved'] = true;
            $data['deductions_applied'] = true;
            $data['summary'] = array_merge($data['summary'] ?? [], [
                'total_final_net_payout' => round($totals['net'], 3),
                'total_deductions' => round($totals['deductions'], 3),
                'total_pending_deductions' => round($totals['deductions'], 3),
                'total_violations_deductions' => $byType['violations'] ?? 0.0,
                'total_advances_deductions' => $byType['advances'] ?? 0.0,
                'total_pending_violations_deductions' => $byType['violations'] ?? 0.0,
                'total_pending_advances_deductions' => $byType['advances'] ?? 0.0,
                'total_pending_maintenance_deductions' => $byType['maintenance'] ?? 0.0,
                'total_pending_custody_deductions' => $byType['custody'] ?? 0.0,
                'total_pending_driver_expenses_deductions' => $byType['driver_expenses'] ?? 0.0,
            ]);

            $run->update([
                'total_drivers' => count($drivers),
                'total_orders' => $totals['orders'],
                'total_gross_earnings' => round($totals['gross'], 3),
                'total_violations_deductions' => $byType['violations'] ?? 0.0,
                'total_advances_deductions' => $byType['advances'] ?? 0.0,
                'total_manual_adjustments' => round($totals['adjustments'], 3),
                'total_final_net_payout' => round($totals['net'], 3),
                'snapshot_data' => $data,
            ]);

            return $run;
        });

        return response()->json([
            'message' => "تم اعتماد كشف الرواتب المجمّع لشهر {$month}/{$year} وتطبيق خصومات المخالفات والسلف 🔒",
            'run' => $run->load('approvedBy:id,name'),
        ]);
    }

    /**
     * POST /api/payroll/consolidated/{year}/{month}/unapprove
     *
     * Reverses everything approve() committed: instalments are refunded to the advance,
     * fines are un-marked, and the month falls back to a projection.
     */
    public function unapproveConsolidatedSheet(Request $request, $year, $month): JsonResponse
    {
        if (! $request->user()->can('contract_payroll.approve') && ! $request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك بفك اعتماد كشف الرواتب المجمّع.'], 403);
        }

        $year = (int) $year;
        $month = (int) $month;
        $companyId = app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1);

        $run = ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if (! $run) {
            return response()->json(['message' => 'لا يوجد اعتماد لكشف هذا الشهر.'], 404);
        }

        // Only the month approved most recently may be reopened — most recently in time, not in the
        // calendar. Months carry a balance forward in the order they were closed, so each approval
        // reads the balance the one before it left. Reopening any but the last would restore a
        // balance that later approvals have already spent, and the ones after it would be sitting
        // on an opening figure that no longer exists. The run row is created at approval, so a
        // higher id is simply a later approval.
        $newer = ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->where('id', '>', $run->id)
            ->orderByDesc('id')
            ->first();

        if ($newer) {
            return response()->json([
                'message' => "لا يمكن فكّ اعتماد شهر {$month}/{$year} لأنّ شهر {$newer->month}/{$newer->year} اعتُمد بعده. "
                    .'ابدأ بفكّ اعتماد آخر شهر تمّ اعتماده.',
            ], 422);
        }

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, Carbon::parse($startDate)->daysInMonth);

        \DB::transaction(function () use ($run) {
            foreach ($run->advanceDeductions()->get() as $deduction) {
                $advance = SalaryAdvance::withoutGlobalScopes()->find($deduction->salary_advance_id);
                if ($advance) {
                    $advance->remaining_balance = (float) $advance->remaining_balance + (float) $deduction->amount;
                    $advance->paid_installments = max(0, (int) $advance->paid_installments - 1);
                    if ($advance->status === 'completed' && $advance->remaining_balance > 0) {
                        $advance->status = 'active';
                    }
                    $advance->saveQuietly();
                }
                $deduction->delete();
            }

            // Release exactly what the ledger says this run charged, and nothing else. Anything
            // the legacy payroll path collected has no ledger row here, so its flag survives and
            // cannot be made billable again by unapproving this month.
            $rows = ConsolidatedPayrollDeduction::withoutGlobalScopes()
                ->where('consolidated_run_id', $run->id)
                ->get();

            $violationIds = $rows->where('source_type', ConsolidatedPayrollDeduction::SOURCE_VIOLATION)
                ->pluck('source_id')->filter()->all();
            if (! empty($violationIds)) {
                Violation::withoutGlobalScopes()->whereIn('id', $violationIds)->update(['is_deducted' => false]);
            }

            $expenseIds = $rows->where('source_type', ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE)
                ->pluck('source_id')->filter()->all();
            if (! empty($expenseIds)) {
                DriverExpense::withoutGlobalScopes()->whereIn('id', $expenseIds)->update(['is_deducted' => false]);
            }

            // Maintenance, custody and leave carry no flag of their own — removing the ledger
            // rows is what makes them outstanding again. Deleted explicitly rather than relying
            // on the FK cascade, which is not guaranteed to be enforced on every connection.
            ConsolidatedPayrollDeduction::withoutGlobalScopes()
                ->where('consolidated_run_id', $run->id)
                ->delete();

            $run->delete();
        });

        return response()->json([
            'message' => "تم فك اعتماد كشف الرواتب المجمّع لشهر {$month}/{$year} وإرجاع خصومات المخالفات والسلف 🔓",
        ]);
    }

    public static function getPaymentMethodLabel($method)
    {
        return match ($method) {
            'fixed' => 'راتب ثابت (Fixed)',
            'per_order' => 'بالطلب (Per-Order)',
            'hybrid' => 'هجين (Fixed + Commission)',
            'zones' => 'فئات (Zones)',
            'zones_tiers' => 'شرائح الفئات (Zones + Tiers)',
            'tiers' => 'شرائح (Tiers)',
            default => $method
        };
    }
}
