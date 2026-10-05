<?php

namespace App\Services;

use App\Models\Vehicle;
use Carbon\Carbon;

/**
 * What each vehicle costs in a month whether it drives or not: the rent of a rented one, the
 * instalment of a financed one, the depreciation of an owned one.
 *
 * Defaults the owner may change (they are rules, not readings):
 * - Rent and instalments run between their start and end dates; a month the period only partly
 *   covers is charged for the days it covers. With no dates the charge is every month.
 * - Depreciation is the hand-entered monthly figure when there is one, else straight line —
 *   (purchase price − salvage value) ÷ useful life in months — from the purchase month until the
 *   life is spent. A financed vehicle is NOT depreciated: its instalments already pay for it, and
 *   counting both would charge its price twice.
 *
 * These costs reach the vehicle report and the company's profit and loss. They are not placed on
 * contracts: the contract profitability screens are unchanged.
 */
class VehicleFixedCostService
{
    /**
     * @return array{vehicles: array<int, array{rent: float, installment: float, depreciation: float, total: float}>, totals: array{rent: float, installment: float, depreciation: float, total: float}}
     */
    public static function forMonth(int $companyId, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $days = $start->daysInMonth;

        $vehicles = [];
        $totals = ['rent' => 0.0, 'installment' => 0.0, 'depreciation' => 0.0, 'total' => 0.0];

        Vehicle::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->get()
            ->each(function (Vehicle $v) use ($start, $end, $days, &$vehicles, &$totals) {
                $ownership = $v->ownership_type ?: 'owned';
                $rent = $ownership === 'rented'
                    ? round((float) $v->rental_price * self::coveredShare($v->rental_start_date, $v->rental_end_date, $start, $end, $days), 3)
                    : 0.0;
                $installment = $ownership === 'installment'
                    ? round((float) $v->installment_price * self::coveredShare($v->installment_start_date, $v->installment_end_date, $start, $end, $days), 3)
                    : 0.0;
                $depreciation = in_array($ownership, ['owned', 'asset'], true) ? self::depreciation($v, $start) : 0.0;

                $total = round($rent + $installment + $depreciation, 3);
                if ($total <= 0.0) {
                    return;
                }

                $vehicles[$v->id] = ['rent' => $rent, 'installment' => $installment, 'depreciation' => $depreciation, 'total' => $total];
                foreach (['rent', 'installment', 'depreciation', 'total'] as $k) {
                    $totals[$k] = round($totals[$k] + $vehicles[$v->id][$k], 3);
                }
            });

        return ['vehicles' => $vehicles, 'totals' => $totals];
    }

    /** The share of the month a dated period covers: 1 for a whole month or no dates, 0 outside it. */
    private static function coveredShare($from, $to, Carbon $start, Carbon $end, int $days): float
    {
        $from = $from ? Carbon::parse($from)->startOfDay() : null;
        $to = $to ? Carbon::parse($to)->startOfDay() : null;

        $first = $from && $from->gt($start) ? $from : $start;
        $last = $to && $to->lt($end) ? $to : $end;
        if ($last->lt($first)) {
            return 0.0;
        }

        return min(1.0, ($first->diffInDays($last) + 1) / $days);
    }

    private static function depreciation(Vehicle $v, Carbon $start): float
    {
        if ($v->monthly_depreciation !== null && (float) $v->monthly_depreciation > 0) {
            return round((float) $v->monthly_depreciation, 3);
        }

        $price = (float) $v->purchase_price;
        $life = (int) $v->useful_life_months;
        if ($price <= 0 || $life <= 0) {
            return 0.0;
        }

        // From the purchase month (when it is known) for as many months as the life runs.
        if ($v->purchase_date) {
            $bought = Carbon::parse($v->purchase_date)->startOfMonth();
            $elapsed = ($start->year - $bought->year) * 12 + ($start->month - $bought->month);
            if ($elapsed < 0 || $elapsed >= $life) {
                return 0.0;
            }
        }

        return round(max(0.0, $price - (float) $v->salvage_value) / $life, 3);
    }
}
