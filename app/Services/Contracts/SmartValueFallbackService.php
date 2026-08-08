<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\ContractMonthlyParameter;
use App\Models\DriverContractOverride;
use Carbon\Carbon;

class SmartValueFallbackService
{
    protected static array $cache = [];

    /**
     * Resolve a contract parameter value using smart fallback logic:
     * 1. Check active DriverContractOverride for employee/contract on date.
     * 2. Check ContractMonthlyParameter for contract on date (month/year).
     * 3. Check Contract default.
     */
    public static function resolve(int $employeeId, int $contractId, string $date, string $parameterName)
    {
        $cacheKey = "{$employeeId}_{$contractId}_{$date}_{$parameterName}";
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $result = self::doResolve($employeeId, $contractId, $date, $parameterName);
        self::$cache[$cacheKey] = $result;

        return $result;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    protected static function doResolve(int $employeeId, int $contractId, string $date, string $parameterName)
    {
        $carbonDate = Carbon::parse($date);
        $year = $carbonDate->year;
        $month = $carbonDate->month;

        // 1. Find active assignment
        $assignment = ContractAssignment::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('contract_id', $contractId)
            ->whereDate('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')
                  ->orWhereDate('end_date', '>=', $date);
            })
            ->first();

        if (!$assignment) {
            $employee = \App\Models\Employee::withoutGlobalScopes()->find($employeeId);
            if ($employee) {
                if ($parameterName === 'order_commission') {
                    return $employee->rate_per_order;
                }
                if ($parameterName === 'fixed_salary') {
                    return $employee->actual_salary;
                }
                if ($parameterName === 'monthly_target') {
                    return $employee->target_orders_monthly;
                }
            }
        }

        if ($assignment) {
            // Check active override
            $override = DriverContractOverride::withoutGlobalScopes()
                ->where('contract_assignment_id', $assignment->id)
                ->whereDate('effective_from', '<=', $date)
                ->where(function ($q) use ($date) {
                    $q->whereNull('effective_to')
                      ->orWhereDate('effective_to', '>=', $date);
                })
                ->first();

            if ($override) {
                $overrideField = 'custom_' . $parameterName;
                if ($overrideField === 'custom_order_commission' && $override->custom_order_commission !== null) {
                    return $override->custom_order_commission;
                }
                if ($overrideField === 'custom_hourly_rate' && $override->custom_hourly_rate !== null) {
                    return $override->custom_hourly_rate;
                }
                if ($overrideField === 'custom_fixed_salary' && $override->custom_fixed_salary !== null) {
                    return $override->custom_fixed_salary;
                }
                if ($overrideField === 'custom_monthly_target' && $override->custom_monthly_target !== null) {
                    return $override->custom_monthly_target;
                }
                if ($overrideField === 'custom_monthly_bonus' && $override->custom_monthly_bonus !== null) {
                    return $override->custom_monthly_bonus;
                }
                if ($overrideField === 'custom_valid_days' && $override->custom_valid_days !== null) {
                    return $override->custom_valid_days;
                }
            }
        }

        // 2. Check Monthly Parameter
        $monthlyParam = ContractMonthlyParameter::withoutGlobalScopes()
            ->where('contract_id', $contractId)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($monthlyParam) {
            $monthlyField = match ($parameterName) {
                'monthly_target' => 'min_completed_orders',
                'valid_days' => 'min_valid_days',
                'capacity_incentive_rules' => 'capacity_incentive_rules',
                'experience_incentive_rules' => 'experience_incentive_rules',
                default => null
            };

            if ($monthlyField && $monthlyParam->$monthlyField !== null) {
                return $monthlyParam->$monthlyField;
            }
        }

        // 3. Fallback to Contract defaults
        $contract = Contract::withoutGlobalScopes()->find($contractId);
        if ($contract) {
            $contractField = match ($parameterName) {
                'order_commission' => 'default_order_commission',
                'monthly_target' => 'default_monthly_target',
                'daily_target' => 'default_daily_target',
                'valid_days' => 'default_required_valid_days',
                'required_work_days' => 'default_required_work_days',
                'absence_divisor' => 'default_absence_divisor',
                default => null
            };

            if ($contractField && $contract->$contractField !== null) {
                return $contract->$contractField;
            }

            // Fallback to contract driver_pricing_rules per vehicle type
            if (is_array($contract->driver_pricing_rules) && !empty($contract->driver_pricing_rules)) {
                $vtId = null;
                $emp = \App\Models\Employee::withoutGlobalScopes()->find($employeeId);
                if ($emp) {
                    $vtId = $emp->vehicle_type_id;
                }
                $vtPricing = ($vtId && isset($contract->driver_pricing_rules[$vtId]))
                    ? $contract->driver_pricing_rules[$vtId]
                    : (array_values($contract->driver_pricing_rules)[0] ?? []);

                if ($parameterName === 'fixed_salary' && isset($vtPricing['fixed_amount']) && $vtPricing['fixed_amount'] !== null && $vtPricing['fixed_amount'] !== '') {
                    return (float) $vtPricing['fixed_amount'];
                }
                if ($parameterName === 'monthly_target' && (isset($vtPricing['fixed_target']) || isset($vtPricing['zone_target_orders'])) && (($vtPricing['fixed_target'] ?? null) !== null || ($vtPricing['zone_target_orders'] ?? null) !== null)) {
                    return (int) ($vtPricing['fixed_target'] ?? $vtPricing['zone_target_orders']);
                }
                if ($parameterName === 'order_commission' && (isset($vtPricing['fixed_deficit_rate']) || isset($vtPricing['zone_deficit_rate'])) && (($vtPricing['fixed_deficit_rate'] ?? null) !== null || ($vtPricing['zone_deficit_rate'] ?? null) !== null)) {
                    return (float) ($vtPricing['fixed_deficit_rate'] ?? $vtPricing['zone_deficit_rate']);
                }
            }

            // Database column backups (employee default driver commission & contract default_order_commission)
            if ($parameterName === 'order_commission') {
                $employee = \App\Models\Employee::withoutGlobalScopes()->find($employeeId);
                if ($employee && isset($employee->rate_per_order) && (float)$employee->rate_per_order > 0) {
                    return $employee->rate_per_order;
                }
                if (isset($contract->default_order_commission) && (float)$contract->default_order_commission > 0) {
                    return $contract->default_order_commission;
                }
                if (isset($contract->rate_per_order) && (float)$contract->rate_per_order > 0) {
                    return $contract->rate_per_order;
                }
            }
        }

        // Final defaults
        return match ($parameterName) {
            'absence_divisor' => 26,
            'required_work_days' => 26,
            default => null
        };
    }
}
