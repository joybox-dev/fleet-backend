<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DriverContractOverride;
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
            'cash_collected' => round($cashCollected, 3),
        ];
    }

    /**
     * Dispatch calculation based on driver payment method.
     */
    public static function calculateDriverContractPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?DriverContractOverride $override,
        Collection $empLogs,
        ?int $vtId = null,
        ?int $dayBudget = null
    ): array {
        $vtPricing = is_array($contract->driver_pricing_rules) && $vtId && isset($contract->driver_pricing_rules[$vtId])
            ? $contract->driver_pricing_rules[$vtId]
            : [];

        $method = $override?->override_type ?? $vtPricing['payment_method'] ?? 'fixed';

        // No rule for this vehicle type means there is no price to apply. That used to fall
        // through to the fixed strategy and its dead `default_fixed_salary ?? $employee->salary`
        // fallback - neither column exists - so the driver was paid 0.000 with nothing to say why.
        if (! $override && empty($vtPricing)) {
            $totalOrders = (int) $empLogs->sum('orders_count');

            return [
                'base_salary' => 0.0,
                'orders_count' => $totalOrders,
                'orders_bonus' => 0.0,
                'deficit_deduction' => 0.0,
                'surplus_bonus' => 0.0,
                'absence_deduction' => 0.0,
                'gross_contract_earnings' => 0.0,
                'unresolved_vehicle_type' => true,
                'calculation_details' => [[
                    'label' => $vtId
                        ? 'لا توجد قاعدة تسعير لنوع المركبة في هذا العقد'
                        : 'نوع المركبة غير محدد لهذا الشهر',
                    'orders' => $totalOrders,
                    'rate' => 0.0,
                    'amount' => 0.0,
                    'is_unpriced' => true,
                    'formula' => "{$totalOrders} طلب بلا سعر منطبق = 0.000 د.ك",
                ]],
            ];
        }

        // Every month is priced per day against the contract's working days, so a contract that
        // never named them cannot be priced at all. It used to fall back to a hardcoded 28 — a
        // number nobody chose, on a field left blank in 15 of the owner's 18 contracts, quietly
        // deciding what a day of work is worth. A driver on 280.000 was paid 260.000 or 280.000
        // depending on whether anyone had happened to open the contract's edit form.
        if ((int) $contract->default_required_work_days <= 0) {
            $totalOrders = (int) $empLogs->sum('orders_count');

            return [
                'base_salary' => 0.0,
                'orders_count' => $totalOrders,
                'orders_bonus' => 0.0,
                'deficit_deduction' => 0.0,
                'surplus_bonus' => 0.0,
                'absence_deduction' => 0.0,
                'gross_contract_earnings' => 0.0,
                'unresolved_working_days' => true,
                'calculation_details' => [[
                    'label' => 'أيام العمل المطلوبة غير محددة في العقد — لا يمكن احتساب الراتب',
                    'orders' => $totalOrders,
                    'rate' => 0.0,
                    'amount' => 0.0,
                    'is_unpriced' => true,
                    'formula' => 'حدّد «أيام العمل المطلوبة» في إعدادات العقد ثم أعد الاحتساب',
                ]],
            ];
        }

        return match ($method) {
            'fixed', 'target' => self::calculateFixedDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId, $dayBudget),
            'per_order' => self::calculatePerOrderDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId),
            'hybrid' => self::calculateHybridDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId, $dayBudget),
            'zones' => self::calculateZonesDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId, $dayBudget),
            'zones_tiers' => self::calculateZonesTiersDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId),
            'tiered_zones' => self::calculateTieredZonesDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId),
            'tiers' => self::calculateTiersDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId),
            default => self::calculateFixedDriverPayroll($employee, $contract, $assignment, $override, $empLogs, $vtId),
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
        ?DriverContractOverride $override,
        Collection $empLogs,
        ?int $vtId = null,
        ?int $dayBudget = null
    ): array {
        $vtPricing = is_array($contract->driver_pricing_rules) && $vtId && isset($contract->driver_pricing_rules[$vtId])
            ? $contract->driver_pricing_rules[$vtId]
            : [];

        $stats = self::evaluateDriverAttendance($empLogs);

        $baseSalaryConfig = (float) ($override
            ? ($override->fixed_amount ?? $override->custom_fixed_salary ?? 0)
            : ($vtPricing['fixed_amount'] ?? 0));

        $targetConfig = (int) ($override
            ? ($override->fixed_target ?? $override->custom_monthly_target ?? 0)
            : ($vtPricing['fixed_target'] ?? $contract->default_monthly_target ?? 0));

        $deficitRateConfig = (float) ($override
            ? ($override->fixed_deficit_rate ?? 0)
            : ($vtPricing['fixed_deficit_rate'] ?? 0));

        $surplusRateConfig = (float) ($override
            ? ($override->fixed_surplus_rate ?? $deficitRateConfig)
            : ($vtPricing['fixed_surplus_rate'] ?? $deficitRateConfig));

        // Guaranteed to be set: calculateDriverContractPayroll refuses the month without it,
        // rather than deciding for itself what a day of this contract is worth.
        $contractWorkingDays = (int) $contract->default_required_work_days;

        // Daily rates
        $dailySalaryRate = $baseSalaryConfig / $contractWorkingDays;
        $dailyTargetRate = $targetConfig > 0 ? ($targetConfig / $contractWorkingDays) : 0;

        // Driver entitlements based on valid paid/worked days.
        // The divisor is the contract's working days but the multiplier was raw attendance, so a
        // 31-day month against a 28-day contract paid 110.7% of the configured salary and demanded
        // 110.7% of the target. A month is capped at the days the contract actually pays for.
        // The cap belongs to the MONTH, not to a stretch of it. A month split across two vehicle
        // types is priced one stretch at a time, and each applying the contract's full cap to
        // itself paid a 31-day month over two vehicles for 31 days on a contract that pays 28.
        // The caller hands each stretch what is left of the month's allowance.
        $paidDays = $stats['paid_days'];
        $cap = $dayBudget !== null ? min($dayBudget, $contractWorkingDays) : $contractWorkingDays;
        $payableDays = max(0, min($paidDays, $cap));
        $earnedBaseSalary = round($payableDays * $dailySalaryRate, 3);
        $requiredTarget = (int) round($payableDays * $dailyTargetRate);

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

        // The line used to open on $paidDays while the amount was built from $payableDays, so a
        // month over the contract's days read "31 يوم × 1.923 = 50.000" — an equation that does not
        // hold, on the one screen the owner uses to explain a salary to a client.
        $cappedNote = $payableDays < $paidDays
            ? " (من {$paidDays} يوم مدفوع، بحد أقصى {$contractWorkingDays} يوم يدفعها العقد)"
            : '';

        $details = [
            [
                'label' => 'الراتب المستحق عن أيام الدوام الفعلي',
                'amount' => $earnedBaseSalary,
                'formula' => "{$payableDays} يوم مدفوع{$cappedNote} × اليومية ({$baseSalaryConfig} د.ك ÷ {$contractWorkingDays} يوم عمل = {$dailyFormatted} د.ك) = {$earnedBaseSalary} د.ك",
            ],
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
                    'formula' => 'نقص '.($requiredTarget - $totalOrders)." طلب × {$deficitRateConfig} د.ك = -{$deficitDeduction} د.ك",
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
                    'formula' => 'زيادة '.($totalOrders - $requiredTarget)." طلب × {$surplusRateConfig} د.ك = {$surplusBonus} د.ك",
                ];
            }
        }

        return [
            'base_salary' => $earnedBaseSalary,
            'paid_days' => $paidDays,
            'payable_days' => $payableDays,
            'orders_count' => $totalOrders,
            'orders_bonus' => 0.0,
            'required_target' => $requiredTarget,
            'deficit_deduction' => $deficitDeduction,
            'surplus_bonus' => $surplusBonus,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => $gross,
            'calculation_details' => $details,
        ];
    }

    /**
     * Per-Order Commission Strategy
     */
    public static function calculatePerOrderDriverPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?DriverContractOverride $override,
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
                'formula' => "{$ordersCount} طلب × {$rate} د.ك = {$ordersBonus} د.ك",
            ],
        ];

        return [
            'base_salary' => 0.0,
            'orders_count' => $ordersCount,
            'orders_bonus' => $ordersBonus,
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => $ordersBonus,
            'calculation_details' => $details,
        ];
    }

    /**
     * Hybrid Strategy (Daily Base Salary + Per-Order Commission)
     */
    public static function calculateHybridDriverPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?DriverContractOverride $override,
        Collection $empLogs,
        ?int $vtId = null,
        ?int $dayBudget = null
    ): array {
        $vtPricing = is_array($contract->driver_pricing_rules) && $vtId && isset($contract->driver_pricing_rules[$vtId])
            ? $contract->driver_pricing_rules[$vtId]
            : [];

        $stats = self::evaluateDriverAttendance($empLogs);

        // Hybrid is a base salary plus order tiers. The contract form writes `hybrid_fixed` and
        // `hybrid_tiers`; this used to read `fixed_amount` and a flat `order_commission`, neither
        // of which the hybrid section of the form ever sets — so a hybrid contract configured
        // through the UI was paid 0.000. The old keys are still honoured for legacy rows.
        $baseSalaryConfig = (float) ($override
            ? ($override->hybrid_fixed ?? $override->fixed_amount ?? 0)
            : ($vtPricing['hybrid_fixed'] ?? $vtPricing['fixed_amount'] ?? 0));

        $hybridTiers = $override
            ? ($override->hybrid_tiers ?? [])
            : ($vtPricing['hybrid_tiers'] ?? []);

        // Guaranteed to be set: calculateDriverContractPayroll refuses the month without it,
        // rather than deciding for itself what a day of this contract is worth.
        $contractWorkingDays = (int) $contract->default_required_work_days;

        $dailySalaryRate = $baseSalaryConfig / $contractWorkingDays;
        // Capped against what is left of the month — see calculateFixedDriverPayroll.
        $paidDays = max(0, min(
            $stats['paid_days'],
            $dayBudget !== null ? min($dayBudget, $contractWorkingDays) : $contractWorkingDays
        ));
        $earnedBaseSalary = round($paidDays * $dailySalaryRate, 3);

        $ordersCount = $stats['total_orders'];

        // The tier the month's total lands in; first match wins, an absent max is unbounded.
        $rate = 0.0;
        $tierLabel = null;
        if (is_array($hybridTiers) && ! empty($hybridTiers)) {
            foreach ($hybridTiers as $t) {
                $min = (int) ($t['min'] ?? 1);
                $max = isset($t['max']) && $t['max'] !== null && $t['max'] !== '' ? (int) $t['max'] : INF;
                if ($ordersCount >= $min && $ordersCount <= $max) {
                    $rate = (float) ($t['price'] ?? 0.0);
                    $maxText = $max === INF ? 'فأكثر' : "إلى {$max}";
                    $tierLabel = "الشريحة ({$min}-{$maxText} طلب)";
                    break;
                }
            }
        } else {
            // Legacy shape: a single flat commission with no tiers.
            $rate = (float) ($override
                ? ($override->order_commission ?? 0)
                : ($vtPricing['order_commission'] ?? 0.0));
        }

        $ordersBonus = round($ordersCount * $rate, 3);
        $gross = round($earnedBaseSalary + $ordersBonus, 3);

        $dailyFormatted = number_format($dailySalaryRate, 3);
        $details = [
            [
                'label' => 'الراتب الأساسي عن أيام الدوام الفعلي',
                'amount' => $earnedBaseSalary,
                'formula' => "{$paidDays} يوم دوام × اليومية ({$baseSalaryConfig} د.ك ÷ {$contractWorkingDays} يوم = {$dailyFormatted} د.ك) = {$earnedBaseSalary} د.ك",
            ],
            [
                'label' => $tierLabel ? "عمولة الطلبات - {$tierLabel}" : 'عمولة الطلبات المنجزة',
                'orders' => $ordersCount,
                'rate' => $rate,
                'amount' => $ordersBonus,
                'is_unpriced' => $rate <= 0.0 && $ordersCount > 0,
                'formula' => "{$ordersCount} طلب × {$rate} د.ك = {$ordersBonus} د.ك",
            ],
        ];

        return [
            'base_salary' => $earnedBaseSalary,
            'orders_count' => $ordersCount,
            'orders_bonus' => $ordersBonus,
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => $gross,
            'calculation_details' => $details,
        ];
    }

    /**
     * Zones Strategy (Commission per Zone)
     */
    public static function calculateZonesDriverPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?DriverContractOverride $override,
        Collection $empLogs,
        ?int $vtId = null,
        ?int $dayBudget = null
    ): array {
        // The target/deficit/surplus settings live on the rule itself, not on the zone list, so
        // capture them before $pricingRules is narrowed down to the zones array below.
        $targetCfg = [];
        if ($override) {
            $targetCfg = [
                'zone_target_orders' => $override->zone_target_orders,
                'zone_deficit_rate' => $override->zone_deficit_rate,
                'zone_bonus_type' => $override->zone_bonus_type,
                'zone_target_bonus' => $override->zone_target_bonus,
                'zone_surplus_rate' => $override->zone_surplus_rate,
            ];
        } else {
            $allRules = is_string($contract->driver_pricing_rules)
                ? json_decode($contract->driver_pricing_rules, true)
                : $contract->driver_pricing_rules;
            if (is_array($allRules)) {
                $ruleKey = ($vtId && isset($allRules[$vtId])) ? $vtId : array_key_first($allRules);
                if ($ruleKey !== null && is_array($allRules[$ruleKey] ?? null)) {
                    $targetCfg = $allRules[$ruleKey];
                }
            }
        }

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
            $cOrders = (int) $l->orders_count;
            if ($cOrders <= 0) {
                continue;
            }
            $totalOrders += $cOrders;

            $notesData = $l->notes ? json_decode($l->notes, true) : null;
            $zoneOrdersMap = (is_array($notesData) && isset($notesData['zone_orders']) && is_array($notesData['zone_orders'])) ? $notesData['zone_orders'] : [];

            if (! empty($zoneOrdersMap)) {
                foreach ($zoneOrdersMap as $zIdOrName => $zCount) {
                    $zCount = (int) $zCount;
                    if ($zCount <= 0) {
                        continue;
                    }
                    $zoneOrdersTotals[$zIdOrName] = ($zoneOrdersTotals[$zIdOrName] ?? 0) + $zCount;
                }
            } else {
                $zName = $l->zone ?: 'افتراضي';
                $zoneOrdersTotals[$zName] = ($zoneOrdersTotals[$zName] ?? 0) + $cOrders;
            }
        }

        // Orders a day recorded but never attributed to a zone. They were dropped out of the map
        // entirely, so the sheet paid for 60 of a driver's 100 orders with nothing on the row
        // saying where the other 40 went. They are worth nothing — no zone, no price — but they
        // are reported, because a silent reduction is how a real fault gets mistaken for the rate.
        $attributed = array_sum($zoneOrdersTotals);
        if ($totalOrders > $attributed) {
            $zoneOrdersTotals['افتراضي'] = ($zoneOrdersTotals['افتراضي'] ?? 0) + ($totalOrders - $attributed);
        }

        $gross = 0.0;
        $details = [];

        if (is_array($pricingRules)) {
            foreach ($zoneOrdersTotals as $zIdOrName => $zCount) {
                $zRuleName = $zIdOrName;
                $zRate = 0.0;
                foreach ($pricingRules as $rule) {
                    if (is_array($rule) && (
                        (isset($rule['id']) && (string) $rule['id'] === (string) $zIdOrName) ||
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
                $isUnpriced = $zRate <= 0.0 && $zCount > 0;
                $details[] = [
                    'label' => $isUnpriced
                        ? ($zIdOrName === 'افتراضي'
                            ? 'طلبات بلا فئة محددة — لا ينطبق عليها سعر'
                            : "فئة ({$zRuleName}) — لا ينطبق عليها سعر")
                        : "فئة ({$zRuleName})",
                    'orders' => $zCount,
                    'rate' => $zRate,
                    'amount' => $amt,
                    'is_unpriced' => $isUnpriced,
                    'formula' => "{$zCount} طلب × {$zRate} د.ك = {$amt} د.ك",
                ];
            }
        }

        // Zones and fixed are the only two methods that carry a target; the contract form offers
        // these fields for them alone. The backend never read them, so a zones contract silently
        // paid the raw zone total while the dashboard showed the target applied.
        //
        // The target is a monthly figure earned day by day, exactly as in the fixed strategy: a
        // 300-order target on a 30-day contract is 10 a day, so a driver who worked 5 days is
        // judged against 50, not 300.
        $ordersBonus = round($gross, 3);
        $monthlyZoneTarget = (int) ($targetCfg['zone_target_orders'] ?? 0);
        $zoneDeficitRate = (float) ($targetCfg['zone_deficit_rate'] ?? 0);
        $zoneBonusType = $targetCfg['zone_bonus_type'] ?? 'lump_sum';
        $zoneTargetBonus = (float) ($targetCfg['zone_target_bonus'] ?? 0);
        $zoneSurplusRate = (float) ($targetCfg['zone_surplus_rate'] ?? 0);

        // Guaranteed to be set: calculateDriverContractPayroll refuses the month without it,
        // rather than deciding for itself what a day of this contract is worth.
        $contractWorkingDays = (int) $contract->default_required_work_days;
        // Capped the same way as the fixed strategy: a driver cannot be judged against more than
        // the contract's own working days.
        // Capped against what is left of the month — see calculateFixedDriverPayroll.
        $paidDays = max(0, min(
            self::evaluateDriverAttendance($empLogs)['paid_days'],
            $dayBudget !== null ? min($dayBudget, $contractWorkingDays) : $contractWorkingDays
        ));
        $dailyZoneTargetRate = $monthlyZoneTarget > 0 ? ($monthlyZoneTarget / $contractWorkingDays) : 0;
        $zoneTarget = (int) round($paidDays * $dailyZoneTargetRate);

        $deficitDeduction = 0.0;
        $surplusBonus = 0.0;

        if ($zoneTarget > 0) {
            if ($totalOrders < $zoneTarget) {
                $deficitDeduction = round(($zoneTarget - $totalOrders) * $zoneDeficitRate, 3);
                if ($deficitDeduction > 0) {
                    $details[] = [
                        'label' => "خصم النقص في التارغت (مستهدف: {$zoneTarget} | منفذ: {$totalOrders})",
                        'count' => ($zoneTarget - $totalOrders),
                        'orders' => ($zoneTarget - $totalOrders),
                        'unit' => 'order',
                        'type' => 'deficit',
                        'rate' => $zoneDeficitRate,
                        'amount' => -$deficitDeduction,
                        'formula' => 'نقص '.($zoneTarget - $totalOrders)." طلب × {$zoneDeficitRate} د.ك = -{$deficitDeduction} د.ك",
                    ];
                }
            } else {
                $surplusCount = $totalOrders - $zoneTarget;
                $surplusBonus = $zoneBonusType === 'per_order'
                    ? round($surplusCount * $zoneSurplusRate, 3)
                    : round($zoneTargetBonus, 3);
                if ($surplusBonus > 0) {
                    $details[] = [
                        'label' => "بونص تجاوز التارغت (مستهدف: {$zoneTarget} | منفذ: {$totalOrders})",
                        'count' => $surplusCount,
                        'orders' => $surplusCount,
                        'unit' => 'order',
                        'type' => 'surplus',
                        'rate' => $zoneBonusType === 'per_order' ? $zoneSurplusRate : 0.0,
                        'amount' => $surplusBonus,
                        'formula' => $zoneBonusType === 'per_order'
                            ? "زيادة {$surplusCount} طلب × {$zoneSurplusRate} د.ك = {$surplusBonus} د.ك"
                            : "بونص مقطوع لتجاوز التارغت = {$surplusBonus} د.ك",
                    ];
                }
            }
        }

        $gross = round($gross - $deficitDeduction + $surplusBonus, 3);

        return [
            'base_salary' => 0.0,
            'orders_count' => $totalOrders,
            'orders_bonus' => $ordersBonus,
            'required_target' => $zoneTarget,
            'deficit_deduction' => $deficitDeduction,
            'surplus_bonus' => $surplusBonus,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => $gross,
            'calculation_details' => $details,
        ];
    }

    /**
     * Zones with Tiers Strategy
     */
    public static function calculateZonesTiersDriverPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?DriverContractOverride $override,
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
            $cOrders = (int) $l->orders_count;
            if ($cOrders <= 0) {
                continue;
            }
            $totalOrders += $cOrders;

            $notesData = $l->notes ? json_decode($l->notes, true) : null;
            $zoneOrdersMap = (is_array($notesData) && isset($notesData['zone_orders']) && is_array($notesData['zone_orders'])) ? $notesData['zone_orders'] : [];

            if (! empty($zoneOrdersMap)) {
                foreach ($zoneOrdersMap as $zIdOrName => $zCount) {
                    $zCount = (int) $zCount;
                    if ($zCount <= 0) {
                        continue;
                    }
                    $zoneOrdersTotals[$zIdOrName] = ($zoneOrdersTotals[$zIdOrName] ?? 0) + $zCount;
                }
            } else {
                $zName = $l->zone ?: 'افتراضي';
                $zoneOrdersTotals[$zName] = ($zoneOrdersTotals[$zName] ?? 0) + $cOrders;
            }
        }

        // Orders a day recorded but never attributed to a zone. They were dropped out of the map
        // entirely, so the sheet paid for 60 of a driver's 100 orders with nothing on the row
        // saying where the other 40 went. They are worth nothing — no zone, no price — but they
        // are reported, because a silent reduction is how a real fault gets mistaken for the rate.
        $attributed = array_sum($zoneOrdersTotals);
        if ($totalOrders > $attributed) {
            $zoneOrdersTotals['افتراضي'] = ($zoneOrdersTotals['افتراضي'] ?? 0) + ($totalOrders - $attributed);
        }

        $gross = 0.0;
        $details = [];

        if (is_array($pricingRules)) {
            foreach ($zoneOrdersTotals as $zIdOrName => $zCount) {
                $zRuleName = $zIdOrName;
                $zTiers = [];
                foreach ($pricingRules as $rule) {
                    if (is_array($rule) && (
                        (isset($rule['id']) && (string) $rule['id'] === (string) $zIdOrName) ||
                        (isset($rule['name']) && $rule['name'] === $zIdOrName) ||
                        (isset($rule['zone']) && $rule['zone'] === $zIdOrName)
                    )) {
                        $zRuleName = $rule['name'] ?? $rule['zone'] ?? $zIdOrName;
                        $zTiers = $rule['tiers'] ?? [];
                        break;
                    }
                }

                $zRate = 0.0;
                // Not a tier - the placeholder shown when no tier matched. Naming it "الأساسية"
                // made a pricing failure read like a configured band.
                $tierLabel = 'لا تنطبق شريحة';
                if (! empty($zTiers)) {
                    foreach ($zTiers as $t) {
                        $min = (int) ($t['min'] ?? 1);
                        $max = isset($t['max']) && $t['max'] !== null && $t['max'] !== '' ? (int) $t['max'] : INF;
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
                $isUnpriced = $zRate <= 0.0 && $zCount > 0;
                $details[] = [
                    'label' => $isUnpriced
                        ? ($zIdOrName === 'افتراضي'
                            ? 'طلبات بلا فئة محددة — لا ينطبق عليها سعر'
                            : "فئة ({$zRuleName}) — لا ينطبق عليها سعر")
                        : "فئة ({$zRuleName}) - {$tierLabel}",
                    'orders' => $zCount,
                    'rate' => $zRate,
                    'amount' => $amt,
                    // Stated by the backend rather than inferred from rate === 0 on the frontend,
                    // which fired on every legitimately zero-rate line.
                    'is_unpriced' => $isUnpriced,
                    'formula' => "{$zCount} طلب × {$zRate} د.ك = {$amt} د.ك",
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
            'calculation_details' => $details,
        ];
    }

    /** The placeholder a day carries when its orders were never attributed to a zone. */
    private const UNZONED = 'افتراضي';

    /**
     * Monthly Tier × Zone Price Strategy — «تسعيرة الفئات حسب شريحة الشهر».
     *
     * Easy to confuse with zones_tiers, and the difference is the whole point. There, every zone
     * carries its own ladder and each is placed on it by ITS OWN order count. Here the month's
     * TOTAL orders — every zone added together — pick ONE band for the whole month, and that band
     * holds a price for each zone plus a single bonus paid once.
     *
     * That is how عقد الدوائية is written: «إجمالي الطلبات بالشهر يحدد الشريحة», after which a
     * driver in زون 4 on the «جيد» band earns 900 فلس an order and the month carries a 20 د.ك
     * bonus. Read the same table the other way — each zone banded on its own count — and a
     * 400-order month spread evenly over four zones puts every zone on the lowest band: 180.000
     * د.ك where the contract says 270.000 plus the bonus.
     *
     * Rules are stored tier-major, the way the client draws the table — a row per band, a price
     * per zone across it:
     *   [{ id, min, max, label, bonus, prices: { <zoneId>: <rate>, ... } }, ...]
     */
    public static function calculateTieredZonesDriverPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?DriverContractOverride $override,
        Collection $empLogs,
        ?int $vtId = null
    ): array {
        $tiers = self::resolveTieredZonesRules($contract, $override, $vtId);
        [$zoneOrders, $totalOrders] = self::splitOrdersByZone($empLogs);
        $zoneNames = self::zoneNamesFor($contract, $vtId);

        // The band is chosen once, by the month as a whole.
        $tier = null;
        foreach ($tiers as $candidate) {
            $min = (int) ($candidate['min'] ?? 1);
            $max = isset($candidate['max']) && $candidate['max'] !== null && $candidate['max'] !== ''
                ? (int) $candidate['max']
                : PHP_INT_MAX;
            if ($totalOrders >= $min && $totalOrders <= $max) {
                $tier = $candidate;
                break;
            }
        }

        $details = [];
        $gross = 0.0;

        if ($tier === null) {
            // No band covers this month's volume. Every order is unpriced, and the sheet says so
            // rather than quietly paying zero.
            foreach ($zoneOrders as $zoneKey => $count) {
                $details[] = self::tieredZoneLine(
                    $zoneNames[(string) $zoneKey] ?? (string) $zoneKey,
                    $count,
                    0.0,
                    'لا تنطبق شريحة على إجمالي طلبات الشهر',
                    true
                );
            }

            return self::tieredZonesResult($totalOrders, 0.0, $details);
        }

        $min = (int) ($tier['min'] ?? 1);
        $max = isset($tier['max']) && $tier['max'] !== null && $tier['max'] !== '' ? (int) $tier['max'] : null;
        $band = $max === null ? "{$min} فأكثر" : "{$min}-{$max}";
        $named = trim((string) ($tier['label'] ?? ''));
        $tierLabel = $named !== ''
            ? "شريحة {$named} ({$band} طلب بالشهر)"
            : "الشريحة ({$band} طلب بالشهر)";
        $prices = is_array($tier['prices'] ?? null) ? $tier['prices'] : [];

        foreach ($zoneOrders as $zoneKey => $count) {
            $rate = (float) ($prices[$zoneKey] ?? $prices[(string) $zoneKey] ?? 0.0);
            $gross += round($count * $rate, 3);
            $details[] = self::tieredZoneLine(
                $zoneNames[(string) $zoneKey] ?? (string) $zoneKey,
                $count,
                $rate,
                $tierLabel,
                $rate <= 0.0 && $count > 0
            );
        }

        // Paid once for the month, not once per zone.
        $bonus = round((float) ($tier['bonus'] ?? 0), 3);
        if ($bonus > 0) {
            $gross += $bonus;
            $details[] = [
                'label' => "بونص {$tierLabel}",
                'amount' => $bonus,
                'formula' => "إجمالي {$totalOrders} طلب بالشهر ← {$tierLabel} = {$bonus} د.ك",
            ];
        }

        return self::tieredZonesResult($totalOrders, $gross, $details);
    }

    /**
     * @return array<string, mixed>
     */
    private static function tieredZoneLine(string $zoneName, int $count, float $rate, string $tierLabel, bool $isUnpriced): array
    {
        $amount = round($count * $rate, 3);

        return [
            'label' => $isUnpriced
                ? ($zoneName === self::UNZONED
                    ? 'طلبات بلا فئة محددة — لا ينطبق عليها سعر'
                    : "فئة ({$zoneName}) — لا يوجد سعر لها في هذه الشريحة")
                : "فئة ({$zoneName}) - {$tierLabel}",
            'orders' => $count,
            'rate' => $rate,
            'amount' => $amount,
            'is_unpriced' => $isUnpriced,
            'formula' => "{$count} طلب × {$rate} د.ك = {$amount} د.ك",
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     * @return array<string, mixed>
     */
    private static function tieredZonesResult(int $totalOrders, float $gross, array $details): array
    {
        return [
            'base_salary' => 0.0,
            'orders_count' => $totalOrders,
            'orders_bonus' => round($gross, 3),
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => round($gross, 3),
            'calculation_details' => $details,
        ];
    }

    /**
     * The month's orders gathered per zone, plus the total. Orders a day recorded without naming a
     * zone are kept under their own key rather than dropped, so the sheet can report them.
     *
     * @return array{0: array<string, int>, 1: int}
     */
    private static function splitOrdersByZone(Collection $empLogs): array
    {
        $zoneOrders = [];
        $totalOrders = 0;

        foreach ($empLogs as $log) {
            $orders = (int) $log->orders_count;
            if ($orders <= 0) {
                continue;
            }
            $totalOrders += $orders;

            $notes = $log->notes ? json_decode($log->notes, true) : null;
            $map = (is_array($notes) && is_array($notes['zone_orders'] ?? null)) ? $notes['zone_orders'] : [];

            if ($map !== []) {
                foreach ($map as $zoneKey => $count) {
                    $count = (int) $count;
                    if ($count > 0) {
                        $zoneOrders[$zoneKey] = ($zoneOrders[$zoneKey] ?? 0) + $count;
                    }
                }
            } else {
                $key = $log->zone ?: self::UNZONED;
                $zoneOrders[$key] = ($zoneOrders[$key] ?? 0) + $orders;
            }
        }

        $attributed = array_sum($zoneOrders);
        if ($totalOrders > $attributed) {
            $zoneOrders[self::UNZONED] = ($zoneOrders[self::UNZONED] ?? 0) + ($totalOrders - $attributed);
        }

        return [$zoneOrders, $totalOrders];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function resolveTieredZonesRules(Contract $contract, ?DriverContractOverride $override, ?int $vtId): array
    {
        if ($override) {
            $custom = is_string($override->custom_pricing_rules)
                ? json_decode($override->custom_pricing_rules, true)
                : $override->custom_pricing_rules;
            if (is_array($custom) && is_array($custom['tiered_zones'] ?? null) && $custom['tiered_zones'] !== []) {
                return array_values($custom['tiered_zones']);
            }
        }

        $rules = is_string($contract->driver_pricing_rules)
            ? json_decode($contract->driver_pricing_rules, true)
            : $contract->driver_pricing_rules;

        if (! is_array($rules)) {
            return [];
        }

        if ($vtId && is_array($rules[$vtId]['tiered_zones'] ?? null)) {
            return array_values($rules[$vtId]['tiered_zones']);
        }

        $firstKey = array_key_first($rules);
        if ($firstKey !== null && is_array($rules[$firstKey]['tiered_zones'] ?? null)) {
            return array_values($rules[$firstKey]['tiered_zones']);
        }

        return [];
    }

    /**
     * Zone id to display name. Zones are defined on the client side of the contract, so without
     * this a sheet reads «فئة (1786365892901)».
     *
     * @return array<string, string>
     */
    private static function zoneNamesFor(Contract $contract, ?int $vtId): array
    {
        $names = [];

        foreach (['client_pricing_rules', 'driver_pricing_rules'] as $side) {
            $rules = is_string($contract->{$side}) ? json_decode($contract->{$side}, true) : $contract->{$side};
            if (! is_array($rules)) {
                continue;
            }
            $candidates = ($vtId && isset($rules[$vtId])) ? [$rules[$vtId]] : array_values($rules);
            foreach ($candidates as $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                foreach (['zones', 'zones_tiers'] as $key) {
                    foreach ((array) ($rule[$key] ?? []) as $zone) {
                        if (is_array($zone) && isset($zone['id'], $zone['name'])) {
                            $names[(string) $zone['id']] = (string) $zone['name'];
                        }
                    }
                }
            }
        }

        return $names;
    }

    /**
     * Single Tiers Strategy
     */
    public static function calculateTiersDriverPayroll(
        Employee $employee,
        Contract $contract,
        ContractAssignment $assignment,
        ?DriverContractOverride $override,
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
        $tierLabel = 'الأساسية';

        if (! empty($tiers)) {
            foreach ($tiers as $t) {
                $min = (int) ($t['min'] ?? 1);
                $max = isset($t['max']) && $t['max'] !== null && $t['max'] !== '' ? (int) $t['max'] : INF;
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
                'formula' => "{$ordersCount} طلب × {$rate} د.ك = {$gross} د.ك",
            ],
        ];

        return [
            'base_salary' => 0.0,
            'orders_count' => $ordersCount,
            'orders_bonus' => $gross,
            'deficit_deduction' => 0.0,
            'surplus_bonus' => 0.0,
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => $gross,
            'calculation_details' => $details,
        ];
    }
}
