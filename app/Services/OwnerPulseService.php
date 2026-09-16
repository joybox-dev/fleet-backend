<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractPayrollRun;
use App\Models\DailyLog;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The owner's four figures and the decisions waiting on him — the top of the dashboard.
 *
 * From the consultant's review of 2026-09-12: revenue first (today, yesterday, the month so far),
 * then the contribution — revenue less what the drivers actually earned — the cash sitting with
 * drivers as one total with no names, and a short list of things only the owner can settle. Every
 * figure is read through the same services the reports and payroll use, so this row and those
 * screens cannot disagree.
 */
class OwnerPulseService
{
    /** How long the month's contract figures are held; a log or approval refreshes them sooner. */
    private const CACHE_MINUTES = 5;

    /** A reservation is put in front of the owner this many days before it ends. */
    private const RESERVATION_NOTICE_DAYS = 7;

    /** Paperwork is flagged this far ahead of its expiry. */
    private const DOCUMENT_NOTICE_DAYS = 60;

    private const VEHICLE_DOCUMENTS = [
        'insurance_expiry' => 'تأمين السيارة',
        'comprehensive_insurance_expiry' => 'تأمين شامل',
        'food_authority_license_expiry' => 'رخصة هيئة الغذاء',
        'next_service_due' => 'صيانة دورية',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function forDay(int $companyId, Carbon $today): array
    {
        // The services below read relations (a log's vehicle, a contract's client) through the
        // company scope, which resolves to company 0 when nothing is bound — every vehicle vanishes,
        // no order has a type, and the month prices to zero. A request binds it; a console run
        // does not, and a zero result would then sit in the cache for the screen to read.
        if (! app()->bound('current_company_id')) {
            app()->instance('current_company_id', $companyId);
        }

        $rows = self::monthRows($companyId, (int) $today->year, (int) $today->month);
        $contribution = self::contribution($rows);

        return [
            'as_of' => $today->toDateString(),
            'revenue' => self::revenue($companyId, $today),
            'contribution' => $contribution,
            'pending_cash' => self::pendingCash($companyId),
            'decisions' => [
                'unapproved_sheets' => self::unapprovedSheets($rows),
                'negative_contracts' => array_values(array_filter(
                    $contribution['contracts'],
                    fn (array $contract) => $contract['contribution'] < -0.0005
                )),
                'vehicle_documents' => self::vehicleDocuments($companyId, $today),
                'reservations' => self::reservations($companyId, $today),
            ],
        ];
    }

    /**
     * The month's row per contract from the profitability service — the same figures the reports
     * show. Building them prices every contract sheet, a few seconds on a real month, so they are
     * held briefly under a key that changes with any daily log or sheet approval: an edit refreshes
     * them, an idle screen refreshing itself does not rebuild them. The operations screen reads the
     * same cache for its losing contracts, so the two screens name the same contracts.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function monthRows(int $companyId, int $year, int $month): array
    {
        $logs = DailyLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->selectRaw('COUNT(*) AS n, MAX(updated_at) AS u')
            ->first();
        $runs = ContractPayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->selectRaw('COUNT(*) AS n, MAX(updated_at) AS u, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS a', ['approved'])
            ->first();
        $stamp = md5(implode('|', [$logs->n, $logs->u, $runs->n, $runs->u, $runs->a]));
        $key = sprintf('owner-pulse:%d:%04d-%02d:%s', $companyId, $year, $month, $stamp);

        return Cache::remember(
            $key,
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => ContractProfitabilityService::forCompanyMonth($companyId, $year, $month)
        );
    }

    /**
     * What the month has billed so far, and what today and yesterday brought in. Orders are priced
     * by the client rules of their contract as they are logged; a flat monthly fee accrues one day
     * at a time in the daily figures and counts whole in the month figure, the way it is invoiced.
     *
     * @return array<string, mixed>
     */
    private static function revenue(int $companyId, Carbon $today): array
    {
        $start = $today->copy()->startOfMonth();
        $yesterday = $today->copy()->subDay();
        $yesterdayInMonth = $yesterday->gte($start);
        $daysInMonth = (int) $today->daysInMonth;
        $todayStr = $today->toDateString();
        $yesterdayStr = $yesterday->toDateString();

        $logsByContract = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereBetween('log_date', [$start->toDateString(), $todayStr])
            ->with('vehicle:id,vehicle_type_id')
            ->get(['id', 'contract_id', 'log_date', 'orders_count', 'zone', 'notes', 'vehicle_id'])
            ->groupBy('contract_id');

        $contracts = Contract::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereIn('id', $logsByContract->keys()->all())
            ->get();

        $today_ = 0.0;
        $yesterday_ = 0.0;
        $monthToDate = 0.0;
        $fixedMonthToDate = 0.0;
        $orders = 0;
        $unpriced = 0;

        foreach ($contracts as $contract) {
            $logs = $logsByContract->get($contract->id);
            $month = ContractRevenueService::forContractMonth($contract, $logs);
            $monthToDate += $month['revenue'];
            $fixedMonthToDate += $month['fixed_revenue'];
            $orders += $month['orders'];
            $unpriced += $month['unpriced_orders'];

            $fixedPerDay = $month['fixed_revenue'] / $daysInMonth;
            $byDay = $logs->groupBy(fn ($log) => substr((string) $log->log_date, 0, 10));

            $dayLogs = $byDay->get($todayStr);
            $today_ += $fixedPerDay + ($dayLogs
                ? ContractRevenueService::forContractMonth($contract, $dayLogs, 1, 0.0)['revenue']
                : 0.0);

            if ($yesterdayInMonth) {
                $dayLogs = $byDay->get($yesterdayStr);
                $yesterday_ += $fixedPerDay + ($dayLogs
                    ? ContractRevenueService::forContractMonth($contract, $dayLogs, 1, 0.0)['revenue']
                    : 0.0);
            }
        }

        return [
            'today' => round($today_, 3),
            'today_date' => $todayStr,
            // Null on the first of a month: yesterday belongs to a month already reported whole.
            'yesterday' => $yesterdayInMonth ? round($yesterday_, 3) : null,
            'yesterday_date' => $yesterdayStr,
            'month_to_date' => round($monthToDate, 3),
            'fixed_month_to_date' => round($fixedMonthToDate, 3),
            'orders_month_to_date' => $orders,
            'unpriced_orders' => $unpriced,
            'days_in_month' => $daysInMonth,
        ];
    }

    /**
     * Revenue less what the drivers earned on it, per contract and in total, for the contracts with
     * any activity this month — ranked so the weakest sits last and a loss is impossible to miss.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function contribution(array $rows): array
    {
        $revenue = 0.0;
        $driverCost = 0.0;
        $contracts = [];

        foreach ($rows as $row) {
            if (! self::hasActivity($row)) {
                continue;
            }
            $rowRevenue = round((float) $row['revenue'], 3);
            $rowCost = round((float) $row['driver_cost'], 3);
            $contribution = round($rowRevenue - $rowCost, 3);
            $revenue += $rowRevenue;
            $driverCost += $rowCost;
            $contracts[] = [
                'contract_id' => (int) $row['contract_id'],
                'name' => $row['contract_name'],
                'orders' => (int) $row['orders'],
                'unpriced_orders' => (int) $row['unpriced_orders'],
                'revenue' => $rowRevenue,
                'driver_cost' => $rowCost,
                'contribution' => $contribution,
                'margin_pct' => $rowRevenue > 0 ? round($contribution / $rowRevenue * 100, 1) : null,
                'sheet_is_approved' => (bool) $row['sheet_is_approved'],
            ];
        }

        usort($contracts, fn (array $a, array $b) => $b['contribution'] <=> $a['contribution']);
        $total = round($revenue - $driverCost, 3);

        return [
            'revenue' => round($revenue, 3),
            'driver_cost' => round($driverCost, 3),
            'contribution' => $total,
            'margin_pct' => $revenue > 0 ? round($total / $revenue * 100, 1) : null,
            'active_contracts' => count($contracts),
            'contracts' => $contracts,
        ];
    }

    /**
     * Contract sheets of the month that have work on them and no approval yet — the drivers on
     * them cannot be paid until the owner signs each one off.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function unapprovedSheets(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! self::hasActivity($row) || (bool) $row['sheet_is_approved']) {
                continue;
            }
            $out[] = [
                'contract_id' => (int) $row['contract_id'],
                'name' => $row['contract_name'],
                'orders' => (int) $row['orders'],
                'driver_cost' => round((float) $row['driver_cost'], 3),
            ];
        }
        usort($out, fn (array $a, array $b) => $b['driver_cost'] <=> $a['driver_cost']);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function hasActivity(array $row): bool
    {
        return (int) $row['orders'] > 0 || (float) $row['driver_cost'] > 0.0005;
    }

    /**
     * Cash collected from customers and still in drivers' hands: one figure and a head count. The
     * names belong on the operations screen, not in front of the owner.
     *
     * @return array{total: float, drivers: int}
     */
    private static function pendingCash(int $companyId): array
    {
        $row = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('cash_pending', '>', 0)
            ->selectRaw('COALESCE(SUM(cash_pending), 0) AS total, COUNT(DISTINCT employee_id) AS drivers')
            ->first();

        return [
            'total' => round((float) $row->total, 3),
            'drivers' => (int) $row->drivers,
        ];
    }

    /**
     * Paperwork by vehicle, not by document: «60 vehicles have a problem», then what each one
     * needs. A date nobody has entered is not an expiry — those vehicles are counted apart, so a
     * blank field cannot inflate the figure the way it inflated the old alert centre.
     *
     * @return array<string, mixed>
     */
    private static function vehicleDocuments(int $companyId, Carbon $today): array
    {
        $vehicles = Vehicle::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->get(array_merge(['id', 'plate_number', 'make', 'model'], array_keys(self::VEHICLE_DOCUMENTS)));

        $flagged = [];
        $missingOnly = 0;
        foreach ($vehicles as $vehicle) {
            $issues = [];
            $missing = 0;
            foreach (self::VEHICLE_DOCUMENTS as $field => $label) {
                $value = $vehicle->$field;
                if (! $value) {
                    $missing++;

                    continue;
                }
                $days = (int) $today->diffInDays(Carbon::parse($value)->startOfDay(), false);
                if ($days > self::DOCUMENT_NOTICE_DAYS) {
                    continue;
                }
                $issues[] = [
                    'document' => $label,
                    'expiry_date' => substr((string) $value, 0, 10),
                    'days_remaining' => $days,
                    'severity' => $days < 0 ? 'expired' : ($days <= 14 ? 'critical' : 'warning'),
                ];
            }

            if ($issues === []) {
                if ($missing > 0) {
                    $missingOnly++;
                }

                continue;
            }

            usort($issues, fn (array $a, array $b) => $a['days_remaining'] <=> $b['days_remaining']);
            $flagged[] = [
                'vehicle_id' => (int) $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'label' => trim("{$vehicle->make} {$vehicle->model}"),
                'issues' => $issues,
                'missing_documents' => $missing,
                'worst_days' => $issues[0]['days_remaining'],
            ];
        }
        usort($flagged, fn (array $a, array $b) => $a['worst_days'] <=> $b['worst_days']);

        return [
            'vehicles_count' => count($flagged),
            'expired_count' => count(array_filter($flagged, fn (array $v) => $v['worst_days'] < 0)),
            'missing_only_count' => $missingOnly,
            'vehicles' => $flagged,
        ];
    }

    /**
     * Vehicles held by an authority whose hold ends within the notice window or has already
     * ended: the owner decides whether it is back on the road or the hold was extended.
     *
     * @return array{reserved_count: int, due: array<int, array<string, mixed>>}
     */
    private static function reservations(int $companyId, Carbon $today): array
    {
        $reserved = Vehicle::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('status', 'reserved')
            ->get(['id', 'plate_number', 'make', 'model', 'reserved_by', 'reserved_until', 'reserved_note']);

        $limit = $today->copy()->addDays(self::RESERVATION_NOTICE_DAYS);
        $due = [];
        foreach ($reserved as $vehicle) {
            if (! $vehicle->reserved_until) {
                continue;
            }
            $until = Carbon::parse($vehicle->reserved_until)->startOfDay();
            if ($until->gt($limit)) {
                continue;
            }
            $due[] = [
                'vehicle_id' => (int) $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'label' => trim("{$vehicle->make} {$vehicle->model}"),
                'reserved_by' => $vehicle->reserved_by,
                'reserved_until' => $until->toDateString(),
                'days_left' => (int) $today->diffInDays($until, false),
                'note' => $vehicle->reserved_note,
            ];
        }
        usort($due, fn (array $a, array $b) => $a['days_left'] <=> $b['days_left']);

        return [
            'reserved_count' => $reserved->count(),
            'due' => $due,
        ];
    }
}
