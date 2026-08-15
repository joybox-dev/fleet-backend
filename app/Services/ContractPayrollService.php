<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\ContractDriverOverride;
use App\Models\Employee;
use Illuminate\Support\Collection;

class ContractPayrollService
{
    /**
     * Evaluate driver attendance logs for the month.
     * Counts valid working days, paid leaves, orders, cash, etc.
     */
    public static function evaluateDriverAttendance(Collection $empLogs): array
    {
        $paidDays = 0;
        $unpaidLeaveDays = 0;
        $totalOrders = 0;
        $rejectedOrders = 0;
        $cashCollected = 0.0;

        foreach ($empLogs as $log) {
            $cOrders = (int) $log->orders_count;
            $cCash = (float) $log->cash_collected;
            $cRejected = (int) $log->rejected_orders_count;
            $status = $log->driver_status;

            $totalOrders += $cOrders;
            $cashCollected += $cCash;
            $rejectedOrders += $cRejected;

            if ($status === 'paid_leave') {
                $paidDays++;
            } elseif ($status === 'unpaid_leave' || $status === 'absent') {
                $unpaidLeaveDays++;
            } elseif ($status === 'working' || $cOrders > 0 || $cCash > 0 || $cRejected > 0) {
                $paidDays++;
            }
        }

        return [
            'paid_days' => $paidDays,
            'unpaid_leave_days' => $unpaidLeaveDays,
            'total_orders' => $totalOrders,
            'rejected_orders' => $rejectedOrders,
            'cash_collected' => round($cashCollected, 3)
        ];
    }

    /**
     * Dispatch calculation based on driver payment method.
     */
    public static function calculateDriverContractPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?ContractDriverOverride $override,
        Collection $empLogs,
        ?int $vtId = null
    ): array {
        $vtPricing = is_array($contract->driver_pricing_rules) && $vtId && isset($contract->driver_pricing_rules[$vtId]) 
            ? $contract->driver_pricing_rules[$vtId] 
            : [];

        $method = $override?->override_type ?? $vtPricing['payment_method'] ?? 'fixed';

        return match ($method) {
            'fixed', 'target' => self::calculateFixedDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId),
            'per_order'       => self::calculatePerOrderDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId),
            'hybrid'          => self::calculateHybridDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId),
            'zones'           => self::calculateZonesDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId),
            'zones_tiers'     => self::calculateZonesTiersDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId),
            'tiers'           => self::calculateTiersDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId),
            default           => self::calculateFixedDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId),
        };
    }

    /**
     * Fixed Salary & Target Strategy
     * Formula: Earned Salary = Paid Days * (Base Monthly Salary / Contract Working Days)
     */
    public static function calculateFixedDriverPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?ContractDriverOverride $override,
        Collection $empLogs,
        ?int $vtId = null
    ): array {
        $vtPricing = is_array($contract->driver_pricing_rules) && $vtId && isset($contract->driver_pricing_rules[$vtId]) 
            ? $contract->driver_pricing_rules[$vtId] 
            : [];

        $stats = self::evaluateDriverAttendance($empLogs);

        $baseSalaryConfig = (float) ($override 
            ? ($override->fixed_amount ?? $override->custom_fixed_salary ?? 0) 
            : ($vtPricing['fixed_amount'] ?? $contract->default_fixed_salary ?? $employee->salary ?? 0));

        $targetConfig = (int) ($override 
            ? ($override->fixed_target ?? $override->custom_monthly_target ?? 0) 
            : ($vtPricing['fixed_target'] ?? $contract->default_monthly_target ?? 0));

        $deficitRateConfig = (float) ($override 
            ? ($override->fixed_deficit_rate ?? 0) 
            : ($vtPricing['fixed_deficit_rate'] ?? 0));

        $surplusRateConfig = (float) ($override 
            ? ($override->fixed_surplus_rate ?? $deficitRateConfig) 
            : ($vtPricing['fixed_surplus_rate'] ?? $deficitRateConfig));

        $contractWorkingDays = (int) ($contract->default_required_work_days ?? 28);
        if ($contractWorkingDays <= 0) {
            $contractWorkingDays = 28;
        }

        // Daily rates
        $dailySalaryRate = $baseSalaryConfig / $contractWorkingDays;
        $dailyTargetRate = $targetConfig > 0 ? ($targetConfig / $contractWorkingDays) : 0;

        // Driver entitlements based on valid paid/worked days
        $paidDays = $stats['paid_days'];
        $earnedBaseSalary = round($paidDays * $dailySalaryRate, 3);
        $requiredTarget = (int) round($paidDays * $dailyTargetRate);

        $totalOrders = $stats['total_orders'];
        $deficitDeduction = 0.0;
        $surplusBonus = 0.0;

        if ($requiredTarget > 0) {
            if ($totalOrders < $requiredTarget) {
                $deficitDeduction = round(($requiredTarget - $totalOrders) * $deficitRateConfig, 3);
            } else {
                $surplusBonus = round(($totalOrders - $requiredTarget) * $surplusRateConfig, 3);
            }
        }

        $gross = round($earnedBaseSalary - $deficitDeduction + $surplusBonus, 3);

        $dailyFormatted = number_format($dailySalaryRate, 3);
        $details = [
            [
                'label' => 'الراتب المستحق عن أيام الدوام الفعلي',
                'amount' => $earnedBaseSalary,
                'formula' => "{$paidDays} يوم دوام × اليومية ({$baseSalaryConfig} د.ك ÷ {$contractWorkingDays} يوم عمل = {$dailyFormatted} د.ك) = {$earnedBaseSalary} د.ك"
            ]
        ];

        if ($requiredTarget > 0) {
            if ($deficitDeduction > 0) {
                $details[] = [
                    'label' => "خصم النقص في التارغت (مستهدف: {$requiredTarget} | منفذ: {$totalOrders})",
                    'count' => ($requiredTarget - $totalOrders),
                    'orders' => ($requiredTarget - $totalOrders),
                    'unit' => 'order',
                    'type' => 'deficit',
                    'rate' => $deficitRateConfig,
                    'amount' => -$deficitDeduction,
                    'formula' => "نقص " . ($requiredTarget - $totalOrders) . " طلب × {$deficitRateConfig} د.ك = -{$deficitDeduction} د.ك"
                ];
            } elseif ($surplusBonus > 0) {
                $details[] = [
                    'label' => "بونص تجاوز التارغت (مستهدف: {$requiredTarget} | منفذ: {$totalOrders})",
                    'count' => ($totalOrders - $requiredTarget),
                    'orders' => ($totalOrders - $requiredTarget),
                    'unit' => 'order',
                    'type' => 'surplus',
                    'rate' => $surplusRateConfig,
                    'amount' => $surplusBonus,
                    'formula' => "زيادة " . ($totalOrders - $requiredTarget) . " طلب × {$surplusRateConfig} د.ك = {$surplusBonus} د.ك"
                ];
            }
        }

        return [
            'base_salary' => $earnedBaseSalary,
            'orders_count' => $totalOrders,
            'orders_bonus' => 0.0,
            'required_target' => $requiredTarget,
            'deficit_deduction' => $deficitDeduction,
            'surplus_bonus' => $surplusBonus,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => $gross,
            'calculation_details' => $details
        ];
    }

    /**
     * Per-Order Commission Strategy
     */
    public static function calculatePerOrderDriverPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?ContractDriverOverride $override,
        Collection $empLogs,
        ?int $vtId = null
    ): array {
        $vtPricing = is_array($contract->driver_pricing_rules) && $vtId && isset($contract->driver_pricing_rules[$vtId]) 
            ? $contract->driver_pricing_rules[$vtId] 
            : [];

        $rate = (float) ($override 
            ? ($override->order_commission ?? 0) 
            : ($vtPricing['order_commission'] ?? $contract->rate_per_order ?? 0.0));

        $stats = self::evaluateDriverAttendance($empLogs);
        $ordersCount = $stats['total_orders'];
        $ordersBonus = round($ordersCount * $rate, 3);

        $details = [
            [
                'label' => 'عمولة الطلبات المنجزة',
                'orders' => $ordersCount,
                'rate' => $rate,
                'amount' => $ordersBonus,
                'formula' => "{$ordersCount} طلب × {$rate} د.ك = {$ordersBonus} د.ك"
            ]
        ];

        return [
            'base_salary' => 0.0,
            'orders_count' => $ordersCount,
            'orders_bonus' => $ordersBonus,
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => $ordersBonus,
            'calculation_details' => $details
        ];
    }

    /**
     * Hybrid Strategy (Daily Base Salary + Per-Order Commission)
     */
    public static function calculateHybridDriverPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?ContractDriverOverride $override,
        Collection $empLogs,
        ?int $vtId = null
    ): array {
        $vtPricing = is_array($contract->driver_pricing_rules) && $vtId && isset($contract->driver_pricing_rules[$vtId]) 
            ? $contract->driver_pricing_rules[$vtId] 
            : [];

        $stats = self::evaluateDriverAttendance($empLogs);

        $baseSalaryConfig = (float) ($override 
            ? ($override->fixed_amount ?? 0) 
            : ($vtPricing['fixed_amount'] ?? $employee->salary ?? 0));

        $rate = (float) ($override 
            ? ($override->order_commission ?? 0) 
            : ($vtPricing['order_commission'] ?? 0.0));

        $contractWorkingDays = (int) ($contract->default_required_work_days ?? 28);
        if ($contractWorkingDays <= 0) {
            $contractWorkingDays = 28;
        }

        $dailySalaryRate = $baseSalaryConfig / $contractWorkingDays;
        $paidDays = $stats['paid_days'];
        $earnedBaseSalary = round($paidDays * $dailySalaryRate, 3);

        $ordersCount = $stats['total_orders'];
        $ordersBonus = round($ordersCount * $rate, 3);
        $gross = round($earnedBaseSalary + $ordersBonus, 3);

        $dailyFormatted = number_format($dailySalaryRate, 3);
        $details = [
            [
                'label' => 'الراتب الأساسي عن أيام الدوام الفعلي',
                'amount' => $earnedBaseSalary,
                'formula' => "{$paidDays} يوم دوام × اليومية ({$baseSalaryConfig} د.ك ÷ {$contractWorkingDays} يوم = {$dailyFormatted} د.ك) = {$earnedBaseSalary} د.ك"
            ],
            [
                'label' => 'عمولة الطلبات المنجزة',
                'orders' => $ordersCount,
                'rate' => $rate,
                'amount' => $ordersBonus,
                'formula' => "{$ordersCount} طلب × {$rate} د.ك = {$ordersBonus} د.ك"
            ]
        ];

        return [
            'base_salary' => $earnedBaseSalary,
            'orders_count' => $ordersCount,
            'orders_bonus' => $ordersBonus,
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => $gross,
            'calculation_details' => $details
        ];
    }

    /**
     * Zones Strategy (Commission per Zone)
     */
    public static function calculateZonesDriverPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?ContractDriverOverride $override,
        Collection $empLogs,
        ?int $vtId = null
    ): array {
        $pricingRules = null;
        if ($override && isset($override->zones) && is_array($override->zones) && count($override->zones) > 0) {
            $pricingRules = $override->zones;
        } else {
            $pricingRules = is_string($contract->driver_pricing_rules) ? json_decode($contract->driver_pricing_rules, true) : $contract->driver_pricing_rules;
            if (is_array($pricingRules)) {
                if ($vtId && isset($pricingRules[$vtId]['zones'])) {
                    $pricingRules = $pricingRules[$vtId]['zones'];
                } else {
                    $firstKey = array_key_first($pricingRules);
                    if ($firstKey !== null && isset($pricingRules[$firstKey]['zones'])) {
                        $pricingRules = $pricingRules[$firstKey]['zones'];
                    }
                }
            }
        }

        $zoneOrdersTotals = [];
        $totalOrders = 0;

        foreach ($empLogs as $l) {
            $cOrders = (int)$l->orders_count;
            if ($cOrders <= 0) continue;
            $totalOrders += $cOrders;

            $notesData = $l->notes ? json_decode($l->notes, true) : null;
            $zoneOrdersMap = (is_array($notesData) && isset($notesData['zone_orders']) && is_array($notesData['zone_orders'])) ? $notesData['zone_orders'] : [];

            if (!empty($zoneOrdersMap)) {
                foreach ($zoneOrdersMap as $zIdOrName => $zCount) {
                    $zCount = (int)$zCount;
                    if ($zCount <= 0) continue;
                    $zoneOrdersTotals[$zIdOrName] = ($zoneOrdersTotals[$zIdOrName] ?? 0) + $zCount;
                }
            } else {
                $zName = $l->zone ?: 'افتراضي';
                $zoneOrdersTotals[$zName] = ($zoneOrdersTotals[$zName] ?? 0) + $cOrders;
            }
        }

        $gross = 0.0;
        $details = [];

        if (is_array($pricingRules)) {
            foreach ($zoneOrdersTotals as $zIdOrName => $zCount) {
                $zRuleName = $zIdOrName;
                $zRate = 0.0;
                foreach ($pricingRules as $rule) {
                    if (is_array($rule) && (
                        (isset($rule['id']) && (string)$rule['id'] === (string)$zIdOrName) ||
                        (isset($rule['name']) && $rule['name'] === $zIdOrName) ||
                        (isset($rule['zone']) && $rule['zone'] === $zIdOrName)
                    )) {
                        $zRuleName = $rule['name'] ?? $rule['zone'] ?? $zIdOrName;
                        $zRate = (float) ($rule['price'] ?? $rule['rate'] ?? 0.0);
                        break;
                    }
                }
                $amt = round($zCount * $zRate, 3);
                $gross += $amt;
                $details[] = [
                    'label' => "فئة ({$zRuleName})",
                    'orders' => $zCount,
                    'rate' => $zRate,
                    'amount' => $amt,
                    'formula' => "{$zCount} طلب × {$zRate} د.ك = {$amt} د.ك"
                ];
            }
        }

        return [
            'base_salary' => 0.0,
            'orders_count' => $totalOrders,
            'orders_bonus' => round($gross, 3),
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => round($gross, 3),
            'calculation_details' => $details
        ];
    }

    /**
     * Zones with Tiers Strategy
     */
    public static function calculateZonesTiersDriverPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?ContractDriverOverride $override,
        Collection $empLogs,
        ?int $vtId = null
    ): array {
        $pricingRules = null;
        if ($override && isset($override->zones_tiers) && is_array($override->zones_tiers) && count($override->zones_tiers) > 0) {
            $pricingRules = $override->zones_tiers;
        } else {
            $pricingRules = is_string($contract->driver_pricing_rules) ? json_decode($contract->driver_pricing_rules, true) : $contract->driver_pricing_rules;
            if (is_array($pricingRules)) {
                if ($vtId && isset($pricingRules[$vtId]['zones_tiers'])) {
                    $pricingRules = $pricingRules[$vtId]['zones_tiers'];
                } else {
                    $firstKey = array_key_first($pricingRules);
                    if ($firstKey !== null && isset($pricingRules[$firstKey]['zones_tiers'])) {
                        $pricingRules = $pricingRules[$firstKey]['zones_tiers'];
                    }
                }
            }
        }

        $zoneOrdersTotals = [];
        $totalOrders = 0;

        foreach ($empLogs as $l) {
            $cOrders = (int)$l->orders_count;
            if ($cOrders <= 0) continue;
            $totalOrders += $cOrders;

            $notesData = $l->notes ? json_decode($l->notes, true) : null;
            $zoneOrdersMap = (is_array($notesData) && isset($notesData['zone_orders']) && is_array($notesData['zone_orders'])) ? $notesData['zone_orders'] : [];

            if (!empty($zoneOrdersMap)) {
                foreach ($zoneOrdersMap as $zIdOrName => $zCount) {
                    $zCount = (int)$zCount;
                    if ($zCount <= 0) continue;
                    $zoneOrdersTotals[$zIdOrName] = ($zoneOrdersTotals[$zIdOrName] ?? 0) + $zCount;
                }
            } else {
                $zName = $l->zone ?: 'افتراضي';
                $zoneOrdersTotals[$zName] = ($zoneOrdersTotals[$zName] ?? 0) + $cOrders;
            }
        }

        $gross = 0.0;
        $details = [];

        if (is_array($pricingRules)) {
            foreach ($zoneOrdersTotals as $zIdOrName => $zCount) {
                $zRuleName = $zIdOrName;
                $zTiers = [];
                foreach ($pricingRules as $rule) {
                    if (is_array($rule) && (
                        (isset($rule['id']) && (string)$rule['id'] === (string)$zIdOrName) ||
                        (isset($rule['name']) && $rule['name'] === $zIdOrName) ||
                        (isset($rule['zone']) && $rule['zone'] === $zIdOrName)
                    )) {
                        $zRuleName = $rule['name'] ?? $rule['zone'] ?? $zIdOrName;
                        $zTiers = $rule['tiers'] ?? [];
                        break;
                    }
                }

                $zRate = 0.0;
                $tierLabel = "الأساسية";
                if (!empty($zTiers)) {
                    foreach ($zTiers as $t) {
                        $min = (int) ($t['min'] ?? 1);
                        $max = isset($t['max']) && $t['max'] !== null && $t['max'] !== '' ? (int)$t['max'] : INF;
                        if ($zCount >= $min && $zCount <= $max) {
                            $zRate = (float) ($t['price'] ?? 0.0);
                            $maxText = $max === INF ? 'فأكثر' : "إلى {$max}";
                            $tierLabel = "الشريحة ({$min}-{$maxText} طلب)";
                            break;
                        }
                    }
                }

                $amt = round($zCount * $zRate, 3);
                $gross += $amt;
                $details[] = [
                    'label' => "فئة ({$zRuleName}) - {$tierLabel}",
                    'orders' => $zCount,
                    'rate' => $zRate,
                    'amount' => $amt,
                    'formula' => "{$zCount} طلب × {$zRate} د.ك = {$amt} د.ك"
                ];
            }
        }

        return [
            'base_salary' => 0.0,
            'orders_count' => $totalOrders,
            'orders_bonus' => round($gross, 3),
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => round($gross, 3),
            'calculation_details' => $details
        ];
    }

    /**
     * Single Tiers Strategy
     */
    public static function calculateTiersDriverPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?ContractDriverOverride $override,
        Collection $empLogs,
        ?int $vtId = null
    ): array {
        $tiers = [];
        if ($override) {
            $cRules = is_string($override->custom_pricing_rules) ? json_decode($override->custom_pricing_rules, true) : $override->custom_pricing_rules;
            if (is_array($cRules) && isset($cRules['tiers']) && is_array($cRules['tiers']) && count($cRules['tiers']) > 0) {
                $tiers = $cRules['tiers'];
            } elseif (is_array($override->pricing_rules) && isset($override->pricing_rules['tiers'])) {
                $tiers = $override->pricing_rules['tiers'];
            }
        }

        if (empty($tiers)) {
            $driverRules = is_string($contract->driver_pricing_rules) ? json_decode($contract->driver_pricing_rules, true) : $contract->driver_pricing_rules;
            if (is_array($driverRules)) {
                if ($vtId && isset($driverRules[$vtId]['tiers'])) {
                    $tiers = $driverRules[$vtId]['tiers'];
                } else {
                    $firstKey = array_key_first($driverRules);
                    if ($firstKey !== null && isset($driverRules[$firstKey]['tiers'])) {
                        $tiers = $driverRules[$firstKey]['tiers'];
                    }
                }
            }
        }

        $stats = self::evaluateDriverAttendance($empLogs);
        $ordersCount = $stats['total_orders'];
        $rate = 0.0;
        $tierLabel = "الأساسية";

        if (!empty($tiers)) {
            foreach ($tiers as $t) {
                $min = (int) ($t['min'] ?? 1);
                $max = isset($t['max']) && $t['max'] !== null && $t['max'] !== '' ? (int)$t['max'] : INF;
                if ($ordersCount >= $min && $ordersCount <= $max) {
                    $rate = (float) ($t['price'] ?? 0.0);
                    $maxText = $max === INF ? 'فأكثر' : "إلى {$max}";
                    $tierLabel = "الشريحة ({$min}-{$maxText} طلب)";
                    break;
                }
            }
        }

        $gross = round($ordersCount * $rate, 3);
        $details = [
            [
                'label' => "عمولة الطلبات حسب الشريحة - {$tierLabel}",
                'orders' => $ordersCount,
                'rate' => $rate,
                'amount' => $gross,
                'formula' => "{$ordersCount} طلب × {$rate} د.ك = {$gross} د.ك"
            ]
        ];

        return [
            'base_salary' => 0.0,
            'orders_count' => $ordersCount,
            'orders_bonus' => $gross,
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => $gross,
            'calculation_details' => $details
        ];
    }
}
