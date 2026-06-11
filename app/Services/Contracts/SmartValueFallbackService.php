<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\ContractMonthlyParameter;
use App\Models\DriverContractOverride;
use Carbon\Carbon;

class SmartValueFallbackService
{
    /**
     * Resolve a contract parameter value using smart fallback logic:
     * 1. Check active DriverContractOverride for employee/contract on date.
     * 2. Check ContractMonthlyParameter for contract on date (month/year).
     * 3. Check Contract default.
     */
    public static function resolve(int $employeeId, int $contractId, string $date, string $parameterName)
    {
        $carbonDate = Carbon::parse($date);
        $year = $carbonDate->year;
        $month = $carbonDate->month;

        // 1. Find active assignment
        $assignment = ContractAssignment::where('employee_id', $employeeId)
            ->where('contract_id', $contractId)
            ->whereDate('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')
                  ->orWhereDate('end_date', '>=', $date);
            })
            ->first();

        if (!$assignment) {
            $employee = \App\Models\Employee::find($employeeId);
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
            $override = DriverContractOverride::where('contract_assignment_id', $assignment->id)
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
        $monthlyParam = ContractMonthlyParameter::where('contract_id', $contractId)
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
        $contract = Contract::find($contractId);
        if ($contract) {
            $contractField = match ($parameterName) {
                'order_commission' => 'default_order_commission',
                'hourly_rate' => 'default_hourly_rate',
                'fixed_salary' => 'default_fixed_salary',
                'monthly_target' => 'default_monthly_target',
                'daily_target' => 'default_daily_target',
                'valid_days' => 'default_required_valid_days',
                'absence_divisor' => 'default_absence_divisor',
                default => null
            };

            if ($contractField && $contract->$contractField !== null) {
                return $contract->$contractField;
            }

            // Database column backups (legacy columns)
            if ($parameterName === 'order_commission' && isset($contract->rate_per_order) && (float)$contract->rate_per_order > 0) {
                return $contract->rate_per_order;
            }
            if ($parameterName === 'fixed_salary' && isset($contract->fixed_monthly) && (float)$contract->fixed_monthly > 0) {
                return $contract->fixed_monthly;
            }
        }

        // Final defaults
        return match ($parameterName) {
            'absence_divisor' => 26,
            default => null
        };
    }
}
