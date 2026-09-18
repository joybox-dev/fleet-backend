<?php

namespace App\Services;

use App\Models\Contract;

/**
 * What a contract bills its client for a month.
 *
 * Client pricing moved to `contracts.client_pricing_rules` — zones or tiers, per vehicle type,
 * the same shape the driver side uses — but the revenue readers never followed it. They summed
 * `daily_logs.income_amount`, which `DailyLogController` fills from `contracts.rate_per_order`:
 * a single flat rate the pricing rules replaced and which is 0.000 on every live contract. So the
 * stored income is 0 on all 2,209 logs, and every screen that reads it shows no revenue at all
 * while driver cost — which does read the rules — computes normally.
 *
 * Pricing here is done from the rules at read time rather than trusting the stored column, so
 * history is right without a data migration. Orders whose zone matches no rule are NOT given an
 * invented price: they are counted and reported, the same rule the driver side follows.
 */
class ContractRevenueService
{
    /**
     * @param  iterable  $logs  daily logs for one contract-month, with `vehicle` loaded
     * @param  float  $fixedShare  how much of a flat monthly fee to count: 1.0 for a month, 1/30 for one of its days, 0 to leave it out
     * @return array{revenue: float, fixed_revenue: float, orders: int, unpriced_orders: int, details: array<int, array<string, mixed>>}
     */
    public static function forContractMonth(Contract $contract, iterable $logs, int $monthsCount = 1, float $fixedShare = 1.0): array
    {
        $rules = self::rules($contract);
        $fixedRevenue = 0.0;

        // Orders split by vehicle type, and within a type by zone. A log's type comes from the
        // vehicle actually driven; the contract's own type is the fallback for a log with none.
        $byType = [];
        $orders = 0;

        foreach ($logs as $log) {
            $count = (int) $log->orders_count;
            if ($count <= 0) {
                continue;
            }
            $orders += $count;

            $vtId = (string) ($log->vehicle?->vehicle_type_id ?? $contract->vehicle_type_id ?? '');
            $byType[$vtId] ??= ['orders' => 0, 'zones' => []];
            $byType[$vtId]['orders'] += $count;

            foreach (self::zoneCounts($log, $count) as $zone => $zoneCount) {
                $byType[$vtId]['zones'][$zone] = ($byType[$vtId]['zones'][$zone] ?? 0) + $zoneCount;
            }
        }

        $revenue = 0.0;
        $unpriced = 0;
        $details = [];

        foreach ($byType as $vtId => $bucket) {
            $rule = $rules[$vtId] ?? null;

            if (! is_array($rule)) {
                $unpriced += $bucket['orders'];
                $details[] = self::line(
                    'لا توجد قاعدة تسعير للعميل لنوع المركبة في هذا العقد',
                    $bucket['orders'], 0.0, 0.0, true
                );

                continue;
            }

            // A rule that does not say how it bills is not billed. This used to fall back to zones,
            // so a rule carrying a perfectly good `fixed_amount` and no `payment_method` was priced
            // by zones it had none of, billed nothing, and never said why — the stated amount was
            // simply ignored.
            $method = $rule['payment_method'] ?? null;

            if ($method === null || $method === '') {
                $unpriced += $bucket['orders'];
                $details[] = self::line(
                    'قاعدة تسعير العميل لا تذكر طريقة الاحتساب — لا يمكن تسعير طلبات هذا النوع',
                    $bucket['orders'], 0.0, 0.0, true
                );

                continue;
            }

            if ($method === 'tiers') {
                [$amount, $line] = self::priceByTier($rule, $bucket['orders']);
                $revenue += $amount;
                if ($amount <= 0.0 && $bucket['orders'] > 0) {
                    $unpriced += $bucket['orders'];
                }
                $details[] = $line;

                continue;
            }

            if ($method === 'fixed' || $method === 'hybrid') {
                $fixed = round((float) ($rule['fixed_amount'] ?? 0) * $monthsCount * $fixedShare, 3);
                $revenue += $fixed;
                $fixedRevenue += $fixed;
                $details[] = self::line('مبلغ شهري ثابت', $bucket['orders'], 0.0, $fixed, $fixed <= 0.0);

                continue;
            }

            // Anything the client side does not know how to bill is said so rather than quietly
            // read as zones — a typo, or a driver-side method like «zones_tiers» that the client
            // billing does not have, used to be priced by zones the rule never declared.
            if ($method !== 'zones') {
                $unpriced += $bucket['orders'];
                $details[] = self::line(
                    "طريقة احتساب غير معروفة للعميل ({$method}) — لا يمكن تسعير طلبات هذا النوع",
                    $bucket['orders'], 0.0, 0.0, true
                );

                continue;
            }

            foreach ($bucket['zones'] as $zone => $zoneCount) {
                [$name, $rate] = self::zoneRate($rule, $zone);
                $amount = round($zoneCount * $rate, 3);
                $revenue += $amount;

                $isUnpriced = $rate <= 0.0;
                if ($isUnpriced) {
                    $unpriced += $zoneCount;
                }

                $details[] = self::line(
                    $isUnpriced
                        ? ($zone === self::NO_ZONE
                            ? 'طلبات بلا فئة محددة — لا ينطبق عليها سعر للعميل'
                            : "فئة ({$name}) — لا ينطبق عليها سعر للعميل")
                        : "فئة ({$name})",
                    $zoneCount, $rate, $amount, $isUnpriced
                );
            }
        }

        return [
            'revenue' => round($revenue, 3),
            'fixed_revenue' => round($fixedRevenue, 3),
            'orders' => $orders,
            'unpriced_orders' => $unpriced,
            'details' => $details,
        ];
    }

    /**
     * The same month split by driver: what each driver's own orders billed the client, priced by
     * the rule that priced the contract. Zone orders carry their own rate; a tier rate is the one
     * the contract's whole volume earned, so a driver is never re-tiered on his volume alone; a
     * flat monthly fee is shared by days logged, since the client pays for coverage, not orders.
     * Each type's driver shares are settled to the fils against the contract's own figure, so the
     * driver rows always add up to the contract row.
     *
     * @param  iterable  $logs  daily logs for one contract-month, with `vehicle` loaded
     * @return array{contract: array{revenue: float, orders: int, unpriced_orders: int}, drivers: array<int, array{revenue: float, orders: int, unpriced_orders: int, days: int}>}
     */
    public static function forContractDrivers(Contract $contract, iterable $logs): array
    {
        $rules = self::rules($contract);

        // type => orders/days for the type and per driver, with each driver's zone split.
        $byType = [];
        $drivers = [];
        foreach ($logs as $log) {
            $empId = (int) $log->employee_id;
            $count = max(0, (int) $log->orders_count);
            $vtId = (string) ($log->vehicle?->vehicle_type_id ?? $contract->vehicle_type_id ?? '');

            $drivers[$empId] ??= ['revenue' => 0.0, 'orders' => 0, 'unpriced_orders' => 0, 'days' => 0];
            $drivers[$empId]['orders'] += $count;
            $drivers[$empId]['days']++;

            $byType[$vtId] ??= ['orders' => 0, 'days' => 0, 'drivers' => []];
            $byType[$vtId]['orders'] += $count;
            $byType[$vtId]['days']++;
            $byType[$vtId]['drivers'][$empId] ??= ['orders' => 0, 'days' => 0, 'zones' => []];
            $byType[$vtId]['drivers'][$empId]['orders'] += $count;
            $byType[$vtId]['drivers'][$empId]['days']++;
            if ($count > 0) {
                foreach (self::zoneCounts($log, $count) as $zone => $zoneCount) {
                    $byType[$vtId]['drivers'][$empId]['zones'][$zone] = ($byType[$vtId]['drivers'][$empId]['zones'][$zone] ?? 0) + $zoneCount;
                }
            }
        }

        $contractRevenue = 0.0;
        $contractOrders = 0;
        $contractUnpriced = 0;

        foreach ($byType as $vtId => $type) {
            $contractOrders += $type['orders'];
            // forContractMonth never opens a bucket for a type with no orders; neither is one priced here.
            if ($type['orders'] <= 0) {
                continue;
            }

            $rule = $rules[$vtId] ?? null;
            $method = is_array($rule) ? ($rule['payment_method'] ?? null) : null;
            $amounts = [];
            $unpricedBy = [];
            $typeAmount = 0.0;

            if (! is_array($rule) || ! in_array($method, ['tiers', 'fixed', 'hybrid', 'zones'], true)) {
                foreach ($type['drivers'] as $empId => $share) {
                    $unpricedBy[$empId] = $share['orders'];
                }
            } elseif ($method === 'tiers') {
                [$typeAmount] = self::priceByTier($rule, $type['orders']);
                $rate = $typeAmount > 0 ? $typeAmount / $type['orders'] : 0.0;
                foreach ($type['drivers'] as $empId => $share) {
                    if ($typeAmount > 0) {
                        $amounts[$empId] = $share['orders'] * $rate;
                    } else {
                        $unpricedBy[$empId] = $share['orders'];
                    }
                }
            } elseif ($method === 'fixed' || $method === 'hybrid') {
                $typeAmount = round((float) ($rule['fixed_amount'] ?? 0), 3);
                foreach ($type['drivers'] as $empId => $share) {
                    $amounts[$empId] = $type['days'] > 0 ? $typeAmount * $share['days'] / $type['days'] : 0.0;
                }
            } else {
                $zoneTotals = [];
                foreach ($type['drivers'] as $empId => $share) {
                    $amounts[$empId] = 0.0;
                    foreach ($share['zones'] as $zone => $zoneCount) {
                        $zoneTotals[$zone] = ($zoneTotals[$zone] ?? 0) + $zoneCount;
                        [, $rate] = self::zoneRate($rule, (string) $zone);
                        if ($rate <= 0.0) {
                            $unpricedBy[$empId] = ($unpricedBy[$empId] ?? 0) + $zoneCount;

                            continue;
                        }
                        $amounts[$empId] += $zoneCount * $rate;
                    }
                }
                // The contract prices each zone as one rounded line; the type figure is that sum.
                foreach ($zoneTotals as $zone => $zoneCount) {
                    [, $rate] = self::zoneRate($rule, (string) $zone);
                    $typeAmount += round($zoneCount * $rate, 3);
                }
            }

            // Round each share, then settle the rounding residue on the largest one, so the drivers
            // add up to exactly what the contract billed for this type.
            $rounded = array_map(fn (float $amount) => round($amount, 3), $amounts);
            if ($rounded !== []) {
                $residue = round($typeAmount - array_sum($rounded), 3);
                if (abs($residue) >= 0.0005) {
                    $largest = array_keys($rounded, max($rounded))[0];
                    $rounded[$largest] = round($rounded[$largest] + $residue, 3);
                }
            }
            foreach ($rounded as $empId => $amount) {
                $drivers[$empId]['revenue'] += $amount;
            }
            foreach ($unpricedBy as $empId => $unpricedCount) {
                $drivers[$empId]['unpriced_orders'] += $unpricedCount;
            }
            $contractRevenue += $typeAmount;
            $contractUnpriced += array_sum($unpricedBy);
        }

        foreach ($drivers as &$driver) {
            $driver['revenue'] = round($driver['revenue'], 3);
        }
        unset($driver);

        return [
            'contract' => [
                'revenue' => round($contractRevenue, 3),
                'orders' => $contractOrders,
                'unpriced_orders' => $contractUnpriced,
            ],
            'drivers' => $drivers,
        ];
    }

    /** Marks orders carrying no zone at all, so they read differently from a zone with no price. */
    private const NO_ZONE = '__no_zone__';

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function rules(Contract $contract): array
    {
        $rules = $contract->client_pricing_rules;
        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }

        return is_array($rules) ? $rules : [];
    }

    /**
     * The per-zone split a log was saved with. `notes.zone_orders` is where the daily-log editor
     * writes it; the older `zone` column holds a single name and covers only a handful of rows.
     *
     * A day with no split at all and a zone named in the column is that zone's, whole — the same
     * reading the driver side gives it (ContractPayrollService::splitOrdersByZone). The column was
     * checked only after the unattributed remainder had been filled in, so it was never reached
     * and those days were billed at nothing while the driver was paid for them.
     *
     * @return array<string, int>
     */
    private static function zoneCounts(object $log, int $count): array
    {
        $notes = $log->notes ? json_decode((string) $log->notes, true) : null;
        $map = (is_array($notes) && isset($notes['zone_orders']) && is_array($notes['zone_orders']))
            ? $notes['zone_orders']
            : [];

        $out = [];
        foreach ($map as $zone => $zoneCount) {
            $zoneCount = (int) $zoneCount;
            if ($zoneCount > 0) {
                $out[(string) $zone] = ($out[(string) $zone] ?? 0) + $zoneCount;
            }
        }

        if ($out === [] && $log->zone) {
            return [(string) $log->zone => $count];
        }

        $attributed = array_sum($out);
        if ($attributed < $count) {
            // Partly attributed days are real: the remainder carries no zone and no price.
            $out[self::NO_ZONE] = ($out[self::NO_ZONE] ?? 0) + ($count - $attributed);
        }

        return $out;
    }

    /**
     * How many of a day's orders the client would be billed nothing for because they carry no zone
     * that day's vehicle type can price: no split at all, the ids of another vehicle type's zones,
     * a name the rule does not know. Zero when that vehicle type is not billed by zone — the
     * question only exists where a zone decides the price. A zone the rule knows but has not priced
     * yet is the contract's gap, not the day's, and is not counted.
     *
     * This is what the daily-log writers ask before saving, so an order cannot be entered in a way
     * this reader would then bill at zero without anyone having chosen that.
     */
    public static function ordersWithoutBillableZone(Contract $contract, ?int $vehicleTypeId, int $orders, ?string $zone, ?string $notes): int
    {
        if ($orders <= 0) {
            return 0;
        }

        $rule = self::rules($contract)[(string) ($vehicleTypeId ?? $contract->vehicle_type_id ?? '')] ?? null;
        if (! is_array($rule) || ($rule['payment_method'] ?? null) !== 'zones') {
            return 0;
        }

        $unbillable = 0;
        foreach (self::zoneCounts((object) ['notes' => $notes, 'zone' => $zone], $orders) as $key => $count) {
            if ((string) $key === self::NO_ZONE || self::zoneOf($rule, (string) $key) === null) {
                $unbillable += $count;
            }
        }

        return $unbillable;
    }

    /**
     * The zone of a rule a day's key names — by id, by name, or by the older `zone` field.
     *
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>|null
     */
    private static function zoneOf(array $rule, string $key): ?array
    {
        $key = trim($key);

        foreach (($rule['zones'] ?? []) as $z) {
            if (! is_array($z)) {
                continue;
            }
            foreach (['id', 'name', 'zone'] as $field) {
                if (isset($z[$field]) && trim((string) $z[$field]) === $key) {
                    return $z;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array{0: string, 1: float}
     */
    private static function zoneRate(array $rule, string $zone): array
    {
        if ($zone === self::NO_ZONE) {
            return ['—', 0.0];
        }

        $z = self::zoneOf($rule, $zone);

        return $z === null
            ? [$zone, 0.0]
            : [(string) ($z['name'] ?? $z['zone'] ?? $zone), (float) ($z['price'] ?? $z['rate'] ?? 0)];
    }

    /**
     * First band that contains the month's volume wins; a missing max means no upper limit.
     *
     * @param  array<string, mixed>  $rule
     * @return array{0: float, 1: array<string, mixed>}
     */
    private static function priceByTier(array $rule, int $orders): array
    {
        foreach (($rule['tiers'] ?? []) as $tier) {
            if (! is_array($tier)) {
                continue;
            }
            $min = (int) ($tier['min'] ?? 0);
            $max = ($tier['max'] ?? null) !== null && $tier['max'] !== '' ? (int) $tier['max'] : PHP_INT_MAX;

            if ($orders >= $min && $orders <= $max) {
                $rate = (float) ($tier['price'] ?? 0);
                $amount = round($orders * $rate, 3);

                return [$amount, self::line("شريحة ({$min}–".($max === PHP_INT_MAX ? '∞' : $max).')', $orders, $rate, $amount, $rate <= 0.0)];
            }
        }

        return [0.0, self::line('لا تنطبق شريحة على حجم الشهر — لا ينطبق سعر للعميل', $orders, 0.0, 0.0, true)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(string $label, int $orders, float $rate, float $amount, bool $isUnpriced): array
    {
        return [
            'label' => $label,
            'orders' => $orders,
            'rate' => $rate,
            'amount' => $amount,
            'is_unpriced' => $isUnpriced,
            'formula' => $rate > 0
                ? "{$orders} طلب × {$rate} د.ك = {$amount} د.ك"
                : "{$orders} طلب بلا سعر = 0.000 د.ك",
        ];
    }
}
