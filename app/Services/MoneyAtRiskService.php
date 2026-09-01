<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\Violation;
use Carbon\Carbon;

/**
 * The money the operation is losing or has not collected, for the dashboard.
 *
 * The dashboard counted vehicles, employees, today's orders and pending cash — all true, none of
 * it about where money goes. Every figure here came out of a real fault found in this data, and
 * each one points at the screen that closes it:
 *
 *   • orders with no zone cannot be billed to the client OR paid to the driver — the same missing
 *     attribution costs twice, and on August that was 1,049 orders
 *   • charges resolved but never collected sit in six separate screens and nowhere together
 *   • a fine is scoped to its own month, so one missed in its month is never collectable again
 *   • revenue against driver cost is the operation's actual margin, and revenue only started
 *     computing once it was priced from the client rules
 */
class MoneyAtRiskService
{
    /**
     * @return array<string, mixed>
     */
    public static function forMonth(int $companyId, int $year, int $month): array
    {
        $requested = ['year' => $year, 'month' => $month];

        // On the first of a month there is nothing logged yet, and a band of zeros says nothing.
        // Fall back to the last month that has any activity and name it, rather than render an
        // empty answer to a question the operator asked in good faith.
        $resolved = self::monthWithActivity($companyId, $year, $month);

        $start = Carbon::create($resolved['year'], $resolved['month'], 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();

        $billing = self::billing($companyId, $startStr, $endStr);

        return [
            'period' => $resolved,
            'requested_period' => $requested,
            'is_fallback' => $resolved !== $requested,
            'unbilled_orders' => $billing['unbilled'],
            'margin' => $billing['margin'],
            'uncollected_charges' => self::uncollectedCharges($companyId),
            'unreachable_fines' => self::unreachableFines($companyId, $startStr),
            'expired_documents' => self::expiredDocuments($companyId),
            'trend' => self::trend($companyId, $resolved['year'], $resolved['month']),
        ];
    }

    /** Arabic month names, so the chart does not have to carry a translation table of its own. */
    private const MONTHS = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
        7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
    ];

    /**
     * Six months to the reported one. A single figure says how the month went; the shape of six
     * says whether it is going anywhere.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function trend(int $companyId, int $year, int $month): array
    {
        $out = [];

        for ($back = 5; $back >= 0; $back--) {
            $point = Carbon::create($year, $month, 1)->startOfMonth()->subMonths($back);
            $billing = self::billing(
                $companyId,
                $point->toDateString(),
                $point->copy()->endOfMonth()->toDateString()
            );

            $out[] = [
                'label' => self::MONTHS[$point->month].' '.$point->format('y'),
                'year' => (int) $point->year,
                'month' => (int) $point->month,
                'revenue' => $billing['margin']['revenue'],
                'driver_cost' => $billing['margin']['driver_cost'],
                'net' => $billing['margin']['net'],
                'unbilled_value' => $billing['unbilled']['estimated_value'],
            ];
        }

        return $out;
    }

    /**
     * The requested month if anything was logged in it, otherwise the most recent month that has
     * something to show.
     *
     * @return array{year: int, month: int}
     */
    private static function monthWithActivity(int $companyId, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();

        // Measured in orders, not in rows: a new month is often opened with a row per driver
        // carrying nothing yet, and every figure in this band is about orders delivered.
        $ordersThisMonth = (int) DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereBetween('log_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->sum('orders_count');

        if ($ordersThisMonth > 0) {
            return ['year' => $year, 'month' => $month];
        }

        $latest = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('log_date', '<', $start->toDateString())
            ->where('orders_count', '>', 0)
            ->max('log_date');

        if (! $latest) {
            return ['year' => $year, 'month' => $month];
        }

        $latest = Carbon::parse($latest);

        return ['year' => (int) $latest->year, 'month' => (int) $latest->month];
    }

    /**
     * One pass over the month's contracts: what was billable, what was not, and what it is worth.
     *
     * @return array{unbilled: array<string, mixed>, margin: array<string, mixed>}
     */
    private static function billing(int $companyId, string $startStr, string $endStr): array
    {
        $contracts = Contract::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)->where('is_active', true)->get();

        $revenue = 0.0;
        $orders = 0;
        $unpriced = 0;
        $byContract = [];

        foreach ($contracts as $contract) {
            $logs = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
                ->with('vehicle:id,vehicle_type_id')
                ->where('contract_id', $contract->id)
                ->whereBetween('log_date', [$startStr, $endStr])
                ->get(['id', 'orders_count', 'zone', 'notes', 'vehicle_id']);

            if ($logs->isEmpty()) {
                continue;
            }

            $billed = ContractRevenueService::forContractMonth($contract, $logs);
            $revenue += $billed['revenue'];
            $orders += $billed['orders'];
            $unpriced += $billed['unpriced_orders'];

            if ($billed['unpriced_orders'] > 0) {
                $byContract[] = [
                    'contract_id' => $contract->id,
                    'name' => $contract->name,
                    'orders' => $billed['unpriced_orders'],
                ];
            }
        }

        usort($byContract, fn ($a, $b) => $b['orders'] <=> $a['orders']);

        // Valued at what the same month's priced orders actually fetched — an estimate, and named
        // as one, rather than a rate invented from the pricing table.
        $pricedOrders = max($orders - $unpriced, 0);
        $averageRate = $pricedOrders > 0 ? $revenue / $pricedOrders : 0.0;

        $driverCost = (float) \DB::table('contract_payroll_runs')
            ->where('company_id', $companyId)
            ->where('year', (int) substr($startStr, 0, 4))
            ->where('month', (int) substr($startStr, 5, 2))
            ->sum('total_net_payout');

        return [
            'unbilled' => [
                'orders' => $unpriced,
                'total_orders' => $orders,
                'share' => $orders > 0 ? (int) round($unpriced / $orders * 100) : 0,
                'estimated_value' => round($unpriced * $averageRate, 3),
                'average_rate' => round($averageRate, 3),
                'contracts' => array_slice($byContract, 0, 4),
            ],
            'margin' => [
                'revenue' => round($revenue, 3),
                'driver_cost' => round($driverCost, 3),
                'net' => round($revenue - $driverCost, 3),
            ],
        ];
    }

    /**
     * Charges that have been resolved against a driver but never taken off a payslip. Deliberately
     * not month-bound: the question is what is outstanding, not what this month would collect.
     *
     * @return array<string, mixed>
     */
    private static function uncollectedCharges(int $companyId): array
    {
        $fines = Violation::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('is_deducted', false)->where('is_driver_liable', true)
            ->where('driver_deduction', '>', 0)
            ->get(['employee_id', 'driver_deduction']);

        $expenses = DriverExpense::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('is_deducted', false)->where('driver_amount', '>', 0)
            ->get(['employee_id', 'driver_amount']);

        $employees = $fines->pluck('employee_id')
            ->merge($expenses->pluck('employee_id'))
            ->unique()->filter()->count();

        return [
            'amount' => round((float) $fines->sum('driver_deduction') + (float) $expenses->sum('driver_amount'), 3),
            'items' => $fines->count() + $expenses->count(),
            'employees' => $employees,
        ];
    }

    /**
     * A fine is resolved only within its own calendar month, so one that was not collected then is
     * not collectable now — unlike every other charge, which carries forward until it is taken.
     *
     * @return array<string, mixed>
     */
    private static function unreachableFines(int $companyId, string $startStr): array
    {
        $rows = Violation::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('is_deducted', false)->where('is_driver_liable', true)
            ->where('driver_deduction', '>', 0)
            ->whereDate('violation_date', '<', $startStr)
            ->get(['driver_deduction']);

        return [
            'count' => $rows->count(),
            'amount' => round((float) $rows->sum('driver_deduction'), 3),
        ];
    }

    /**
     * Already expired, not merely approaching — the expiry centre mixes the two.
     *
     * @return array<string, mixed>
     */
    private static function expiredDocuments(int $companyId): array
    {
        $today = Carbon::today()->toDateString();

        $count = Employee::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereIn('status', ['active', 'probation'])
            ->where(function ($q) use ($today) {
                $q->whereDate('residence_expiry', '<', $today)
                    ->orWhereDate('driving_license_expiry', '<', $today)
                    ->orWhereDate('health_card_expiry', '<', $today)
                    ->orWhereDate('work_permit_expiry', '<', $today);
            })
            ->count();

        return ['employees' => $count];
    }
}
