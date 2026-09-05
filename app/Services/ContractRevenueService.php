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
     * @return array{revenue: float, orders: int, unpriced_orders: int, details: array<int, array<string, mixed>>}
     */
    public static function forContractMonth(Contract $contract, iterable $logs, int $monthsCount = 1): array
    {
        $rules = self::rules($contract);

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
                $fixed = round((float) ($rule['fixed_amount'] ?? 0) * $monthsCount, 3);
                $revenue += $fixed;
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
            'orders' => $orders,
            'unpriced_orders' => $unpriced,
            'details' => $details,
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

        $attributed = array_sum($out);
        if ($attributed < $count) {
            // Partly attributed days are real: the remainder carries no zone and no price.
            $out[self::NO_ZONE] = ($out[self::NO_ZONE] ?? 0) + ($count - $attributed);
        }

        if (empty($out) && $log->zone) {
            return [(string) $log->zone => $count];
        }

        return $out ?: [self::NO_ZONE => $count];
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

        foreach (($rule['zones'] ?? []) as $z) {
            if (! is_array($z)) {
                continue;
            }
            $matches = (isset($z['id']) && (string) $z['id'] === $zone)
                || (isset($z['name']) && (string) $z['name'] === $zone)
                || (isset($z['zone']) && (string) $z['zone'] === $zone);

            if ($matches) {
                return [
                    (string) ($z['name'] ?? $z['zone'] ?? $zone),
                    (float) ($z['price'] ?? $z['rate'] ?? 0),
                ];
            }
        }

        return [$zone, 0.0];
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
