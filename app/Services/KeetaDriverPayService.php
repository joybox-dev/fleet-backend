<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\KeetaInvoice;
use App\Models\KeetaInvoiceRider;
use App\Models\KeetaLevelSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A Keeta driver's month under the owner's scenario («السيناريو المحسّن المعتدل», adopted from
 * August 2026). The salary follows the level Keeta gave the rider for the month, so the month is
 * settled from Keeta's own statement, and Keeta's order count is the one paid:
 *
 * - Level S, A or B, and valid by Mersal's standard (at least `min_orders` orders and at most
 *   `max_invalid_days` days Keeta did not count): the level's salary, plus `surplus_rate` for each
 *   order above `target`, less `deficit_rate` for each order below it.
 * - Level C or D, or not valid: `per_order_rate` for each order.
 * - Either way, the bonus of the highest achievement threshold reached.
 *
 * The level is read from the experience incentive Keeta paid for the rider (`tier_incentives`),
 * the orders and the valid days from the same row. Until the statement arrives the month is an
 * estimate — the level from Keeta's «expected level» export, else from last month's statement; the
 * orders from the daily log — and the sheet cannot be approved. It applies from the month the
 * contract is settled by Keeta's statement; the months before keep the contract's own rules.
 */
class KeetaDriverPayService
{
    public const METHOD = 'keeta_tiers';

    /** The scenario as the owner adopted it. */
    public const DEFAULT_RULES = [
        'enabled' => true,
        'tier_salaries' => ['S' => 350, 'A' => 300, 'B' => 250],
        'target' => 400,
        'surplus_rate' => 0.55,
        'deficit_rate' => 0.55,
        'min_orders' => 280,
        'max_invalid_days' => 4,
        'per_order_rate' => 0.4,
        'achievements' => [['orders' => 500, 'amount' => 25], ['orders' => 600, 'amount' => 35]],
        'tier_incentives' => ['S' => 370, 'A' => 270, 'B' => 170, 'C' => 50, 'D' => 0],
    ];

    private const SALARIED = ['S', 'A', 'B'];

    /** @var array<string, array<string, mixed>> */
    private static array $memo = [];

    public static function forget(): void
    {
        self::$memo = [];
    }

    /**
     * The rules in force for this contract-month, or null when its drivers are paid the ordinary way.
     *
     * @return array<string, mixed>|null
     */
    public static function rulesFor(Contract $contract, int $year, int $month): ?array
    {
        $rules = $contract->keeta_pay_rules;
        if (! is_array($rules) || empty($rules['enabled']) || ! KeetaRevenueService::appliesTo($contract, $year, $month)) {
            return null;
        }

        // Key by key rather than a recursive merge, which would keep a default achievement the
        // owner removed from the list.
        $merged = self::DEFAULT_RULES;
        foreach ($rules as $key => $value) {
            if (array_key_exists($key, $merged) && $value !== null) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * One driver's month, in the shape every pricing strategy returns.
     *
     * @param  Collection<int, DailyLog>  $empLogs
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function forDriverMonth(Employee $employee, Contract $contract, int $year, int $month, Collection $empLogs, array $rules): array
    {
        $source = self::source($contract, $year, $month);
        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;
        $logged = (int) $empLogs->sum('orders_count');
        $details = [];

        if ($source['invoice']) {
            /** @var KeetaInvoiceRider|null $rider */
            $rider = $source['riders'][$employee->id] ?? null;
            if (! $rider) {
                // Work on our log that Keeta's statement does not carry: there is nothing to pay it
                // by, and a silent 0.000 is how a missing courier id becomes an unpaid driver.
                return self::result(0.0, 0, 0.0, 0.0, 0.0, 0.0, 0, $logged > 0 ? [[
                    'label' => 'له طلبات في السجل اليومي وليس له سطر في كشف كيتا — اربط رقم كيتا بتعيينه ثم أعد استيراد الكشف',
                    'orders' => $logged,
                    'amount' => 0.0,
                    'is_unpriced' => true,
                    'formula' => "{$logged} طلب في السجل اليومي، ولا شيء في كشف كيتا",
                ]] : [], ['tier' => null, 'estimated' => false]);
            }

            $orders = (int) $rider->orders;
            $validDays = (float) $rider->valid_days;
            $invalidDays = $rider->invalid_days_override !== null
                ? (float) $rider->invalid_days_override
                : max(0.0, $daysInMonth - $validDays);
            $incentive = round((float) $rider->experience_incentive, 3);
            $tier = self::tierOf($incentive, $rules);
            $estimated = false;

            $details[] = [
                'label' => 'من كشف كيتا: '.($tier ? "مستوى {$tier}" : 'مستوى غير معروف')." (حافز {$incentive} د.ك) · {$orders} طلب · "
                    .self::days($validDays)." يوم صالح من {$daysInMonth}"
                    .($rider->invalid_days_override !== null ? ' · الأيام غير الصالحة مُدخلة يدوياً: '.self::days($invalidDays) : ''),
                'amount' => 0.0,
                'formula' => $logged !== $orders ? "طلبات السجل اليومي {$logged}، والمعتمد طلبات كيتا {$orders}" : "طلبات السجل اليومي {$logged}",
            ];

            if ($tier === null) {
                $details[] = [
                    'label' => "حافز كيتا {$incentive} د.ك لا يطابق أي مستوى في قواعد العقد — صحّح مبالغ المستويات",
                    'orders' => $orders,
                    'amount' => 0.0,
                    'is_unpriced' => true,
                    'formula' => 'المستويات المعرّفة: '.collect($rules['tier_incentives'])->map(fn ($v, $k) => "{$k} {$v}")->implode(' · '),
                ];
            }
        } else {
            // No statement yet: an estimate the sheet says out loud, and cannot approve.
            $orders = $logged;
            $invalidDays = null;
            [$tier, $from] = self::estimatedTier($source, $employee->id, $rules);
            $estimated = true;

            if ($orders > 0 || $tier) {
                $details[] = [
                    'label' => 'تقدير حتى يصل كشف كيتا: '.($tier ? "مستوى {$tier} {$from}" : 'لا مستوى معروف بعد')." · {$orders} طلب من السجل اليومي",
                    'orders' => $orders,
                    'amount' => 0.0,
                    'is_unpriced' => $orders > 0,
                    'formula' => 'يُحسب الأجر النهائي من مستوى كيتا وطلباتها وأيامها الصالحة حين يُستورد كشف الشهر',
                ];
            }
        }

        $minOrders = (int) $rules['min_orders'];
        $maxInvalid = (float) $rules['max_invalid_days'];
        $validByOrders = $orders >= $minOrders;
        $validByDays = $invalidDays === null || $invalidDays <= $maxInvalid;
        $salaried = in_array($tier, self::SALARIED, true) && isset($rules['tier_salaries'][$tier]);

        $base = 0.0;
        $perOrder = 0.0;
        $surplus = 0.0;
        $deficit = 0.0;
        $target = 0;

        if ($salaried && $validByOrders && $validByDays) {
            $base = round((float) $rules['tier_salaries'][$tier], 3);
            $target = (int) $rules['target'];
            $details[] = ['label' => "راتب مستوى {$tier}", 'amount' => $base, 'formula' => number_format($base, 3).' د.ك'];

            if ($orders > $target) {
                $rate = (float) $rules['surplus_rate'];
                $surplus = round(($orders - $target) * $rate, 3);
                $details[] = [
                    'label' => "زيادة فوق التارغت (مستهدف: {$target} | منفذ: {$orders})",
                    'orders' => $orders - $target, 'type' => 'surplus', 'rate' => $rate, 'amount' => $surplus,
                    'formula' => 'زيادة '.($orders - $target)." طلب × {$rate} د.ك = {$surplus} د.ك",
                ];
            } elseif ($orders < $target) {
                $rate = (float) $rules['deficit_rate'];
                $deficit = round(($target - $orders) * $rate, 3);
                $details[] = [
                    'label' => "نقص عن التارغت (مستهدف: {$target} | منفذ: {$orders})",
                    'orders' => $target - $orders, 'type' => 'deficit', 'rate' => $rate, 'amount' => -$deficit,
                    'formula' => 'نقص '.($target - $orders)." طلب × {$rate} د.ك = -{$deficit} د.ك",
                ];
            }
        } else {
            $rate = (float) $rules['per_order_rate'];
            $perOrder = round($orders * $rate, 3);
            $why = match (true) {
                ! $salaried => $tier ? "مستوى {$tier}: بالطلب" : 'بلا مستوى: بالطلب',
                ! $validByOrders => "غير صالح عند مرسال: أقل من {$minOrders} طلب",
                default => 'غير صالح عند مرسال: '.self::days((float) $invalidDays)." أيام غير صالحة (الحد {$rules['max_invalid_days']})",
            };
            $details[] = [
                'label' => $why,
                'orders' => $orders, 'rate' => $rate, 'amount' => $perOrder,
                'formula' => "{$orders} طلب × {$rate} د.ك = {$perOrder} د.ك",
            ];
        }

        $achievement = self::achievement($orders, $rules);
        if ($achievement['amount'] > 0) {
            $details[] = [
                'label' => "مكافأة إنجاز ({$achievement['orders']} طلب فأكثر)",
                'amount' => $achievement['amount'],
                'formula' => number_format($achievement['amount'], 3).' د.ك',
            ];
        }

        return self::result(
            $base,
            $orders,
            $perOrder,
            round($surplus + $achievement['amount'], 3),
            $deficit,
            round($base + $perOrder + $surplus + $achievement['amount'] - $deficit, 3),
            $target,
            $details,
            ['tier' => $tier, 'estimated' => $estimated, 'invalid_days' => $invalidDays, 'valid' => $salaried ? ($validByOrders && $validByDays) : null],
        );
    }

    /** The level whose incentive Keeta paid, or null when the amount names none of them. */
    public static function tierOf(float $incentive, array $rules): ?string
    {
        foreach ((array) $rules['tier_incentives'] as $tier => $amount) {
            if (abs($incentive - (float) $amount) < 0.0005) {
                return (string) $tier;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{orders: int, amount: float}
     */
    private static function achievement(int $orders, array $rules): array
    {
        $best = ['orders' => 0, 'amount' => 0.0];
        foreach ((array) $rules['achievements'] as $step) {
            $threshold = (int) ($step['orders'] ?? 0);
            if ($threshold > 0 && $orders >= $threshold && $threshold >= $best['orders']) {
                $best = ['orders' => $threshold, 'amount' => round((float) ($step['amount'] ?? 0), 3)];
            }
        }

        return $best;
    }

    /**
     * The month's statement rows by driver, or — before it arrives — what can stand in for the level.
     *
     * @return array<string, mixed>
     */
    private static function source(Contract $contract, int $year, int $month): array
    {
        $key = $contract->id.':'.$year.':'.$month;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $invoice = KeetaInvoice::withoutGlobalScopes()
            ->where('contract_id', $contract->id)->where('year', $year)->where('month', $month)
            ->first();

        $source = ['invoice' => $invoice, 'riders' => [], 'levels' => [], 'previous' => []];

        if ($invoice) {
            foreach ($invoice->riders()->whereNotNull('employee_id')->get() as $rider) {
                $source['riders'][(int) $rider->employee_id] = $rider;
            }
        } else {
            $snapshot = KeetaLevelSnapshot::withoutGlobalScopes()
                ->where('contract_id', $contract->id)->where('year', $year)->where('month', $month)
                ->orderByDesc('taken_on')->orderByDesc('id')
                ->first();
            if ($snapshot) {
                foreach ($snapshot->rows()->whereNotNull('employee_id')->get() as $row) {
                    $source['levels'][(int) $row->employee_id] = $row->level ? strtoupper((string) $row->level) : null;
                }
                $source['levels_taken_on'] = $snapshot->taken_on?->toDateString();
            }

            $previous = KeetaInvoice::withoutGlobalScopes()
                ->where('contract_id', $contract->id)
                ->whereRaw('(year * 12 + month) < ?', [$year * 12 + $month])
                ->orderByRaw('(year * 12 + month) desc')
                ->first();
            if ($previous) {
                foreach ($previous->riders()->whereNotNull('employee_id')->get() as $rider) {
                    $source['previous'][(int) $rider->employee_id] = round((float) $rider->experience_incentive, 3);
                }
                $source['previous_month'] = sprintf('%04d-%02d', $previous->year, $previous->month);
            }
        }

        return self::$memo[$key] = $source;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $rules
     * @return array{0: ?string, 1: string}
     */
    private static function estimatedTier(array $source, int $employeeId, array $rules): array
    {
        if (array_key_exists($employeeId, $source['levels'])) {
            $level = $source['levels'][$employeeId];

            return [$level ?: 'D', 'من ملف «المستوى المتوقع» بتاريخ '.($source['levels_taken_on'] ?? '')];
        }
        if (array_key_exists($employeeId, $source['previous'])) {
            return [self::tierOf($source['previous'][$employeeId], $rules), 'كما كان في كشف '.($source['previous_month'] ?? '')];
        }

        return [null, ''];
    }

    private static function days(float $days): string
    {
        return rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     * @param  array<string, mixed>  $keeta
     * @return array<string, mixed>
     */
    private static function result(float $base, int $orders, float $perOrder, float $bonus, float $deficit, float $gross, int $target, array $details, array $keeta): array
    {
        return [
            'payment_method' => self::METHOD,
            'base_salary' => round($base, 3),
            'orders_count' => $orders,
            'orders_bonus' => round($perOrder, 3),
            'required_target' => $target,
            'deficit_deduction' => round($deficit, 3),
            'surplus_bonus' => round($bonus, 3),
            'absence_deduction' => 0.0,
            'gross_contract_earnings' => round($gross, 3),
            'calculation_details' => $details,
            'unresolved_vehicle_type' => false,
            'keeta' => $keeta,
        ];
    }
}
