<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\TalabatStatement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What Talabat pays the company for a contract-month, from the first month the contract is read this
 * way (`contracts.talabat_settlement_from`).
 *
 * Talabat pays every order twice — once for the pickup, once for the drop-off — each at a price per
 * vehicle type that changes on dates of its choosing (`contracts.talabat_rates`). The month has two
 * states:
 *
 * - **Invoiced** — Talabat's statement for the month is imported. Every rider's net (pickups,
 *   drop-offs, the service-level payment, his own deductions) adds up to the month's revenue, to the
 *   fils, and is that driver's share. What Talabat charges the company as a whole — the cash deficit,
 *   contract fees — comes off the transfer only, never off the revenue (owner, 2026-11-08).
 * - **Estimated** — no statement yet. Each order logged here is one pickup and one drop-off at the
 *   rates of its day and vehicle. The service-level payment and Talabat's deductions are not guessed.
 *
 * Nothing here touches driver pay.
 */
class TalabatRevenueService
{
    /**
     * Talabat's rates as its partner sheet gave them for September 2026: car 0.500 + 0.550 and bike
     * 0.400 + 0.450 until the 15th; car 0.590 + 0.590 and bike 0.550 + 0.300 from the 16th.
     *
     * @var list<array{from: string, car_pickup: float, car_dropoff: float, bike_pickup: float, bike_dropoff: float}>
     */
    public const DEFAULT_RATES = [
        ['from' => '2026-09-01', 'car_pickup' => 0.500, 'car_dropoff' => 0.550, 'bike_pickup' => 0.400, 'bike_dropoff' => 0.450],
        ['from' => '2026-09-16', 'car_pickup' => 0.590, 'car_dropoff' => 0.590, 'bike_pickup' => 0.550, 'bike_dropoff' => 0.300],
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $memo = [];

    public static function forget(): void
    {
        self::$memo = [];
    }

    public static function appliesTo(Contract $contract, int $year, int $month): bool
    {
        $from = $contract->talabat_settlement_from;
        if (! $from) {
            return false;
        }
        $from = $from instanceof Carbon ? $from : Carbon::parse($from);

        return ($year * 12 + $month) >= ($from->year * 12 + $from->month);
    }

    /**
     * The month a set of one contract's logs belongs to, when that month is read the Talabat way.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function monthOfLogs(Contract $contract, Collection $logs): ?array
    {
        if (! $contract->talabat_settlement_from || $logs->isEmpty()) {
            return null;
        }
        $date = $logs->first()->log_date ?? null;
        if (! $date) {
            return null;
        }
        $day = Carbon::parse((string) $date);

        return self::appliesTo($contract, $day->year, $day->month) ? [$day->year, $day->month] : null;
    }

    /**
     * The contract's rate periods, oldest first; Talabat's own sheet when none were entered.
     *
     * @return list<array{from: string, car_pickup: float, car_dropoff: float, bike_pickup: float, bike_dropoff: float}>
     */
    public static function rates(Contract $contract): array
    {
        $rates = is_array($contract->talabat_rates) && $contract->talabat_rates !== [] ? $contract->talabat_rates : self::DEFAULT_RATES;
        $rates = array_map(fn (array $r) => [
            'from' => Carbon::parse($r['from'])->toDateString(),
            'car_pickup' => (float) ($r['car_pickup'] ?? 0),
            'car_dropoff' => (float) ($r['car_dropoff'] ?? 0),
            'bike_pickup' => (float) ($r['bike_pickup'] ?? 0),
            'bike_dropoff' => (float) ($r['bike_dropoff'] ?? 0),
        ], array_values($rates));
        usort($rates, fn ($a, $b) => strcmp($a['from'], $b['from']));

        return $rates;
    }

    /**
     * What one order pays on a day, by vehicle: its pickup and its drop-off at the period's rates.
     * Before the first period, the first period's rates.
     *
     * @param  list<array<string, mixed>>  $rates
     * @return array{pickup: float, dropoff: float, from: string}
     */
    public static function rateOn(array $rates, string $date, string $vehicle): array
    {
        $period = $rates[0];
        foreach ($rates as $r) {
            if ($r['from'] <= $date) {
                $period = $r;
            }
        }

        return [
            'pickup' => (float) $period[$vehicle.'_pickup'],
            'dropoff' => (float) $period[$vehicle.'_dropoff'],
            'from' => $period['from'],
        ];
    }

    /**
     * The month in full: its state, how the figure was reached, a row per driver, and Talabat's riders
     * that no driver of ours carries.
     *
     * @param  Collection<int, DailyLog>|null  $logs  the month's logs, or a slice of it; loaded when null
     * @return array<string, mixed>
     */
    public static function forMonth(Contract $contract, int $year, int $month, ?Collection $logs = null): array
    {
        $key = $contract->id.':'.$year.':'.$month;
        $given = $logs;
        if ($given === null && isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $logs = self::withDriverAndDate($contract, $year, $month, $logs);
        $statement = TalabatStatement::withoutGlobalScopes()
            ->where('contract_id', $contract->id)->where('year', $year)->where('month', $month)
            ->with(['riders', 'importer:id,name'])
            ->first();

        $result = $statement
            ? self::invoiced($statement, $logs)
            : self::estimated($contract, $logs);

        $result['year'] = $year;
        $result['month'] = $month;
        $result['settlement_from'] = $contract->talabat_settlement_from
            ? Carbon::parse($contract->talabat_settlement_from)->format('Y-m')
            : null;

        if ($given === null) {
            self::$memo[$key] = $result;
        }

        return $result;
    }

    /**
     * The month shaped the way ContractRevenueService::forContractMonth answers.
     *
     * @param  array<string, mixed>  $month
     * @return array<string, mixed>
     */
    public static function asContractRevenue(array $month): array
    {
        $t = $month['totals'];
        $suffix = $month['estimated'] ? ' (تقدير)' : '';
        $details = [
            self::line('استلام طلبات'.$suffix, (int) $t['pickups'], 0.0, (float) $t['pickup_payment']),
            self::line('تسليم طلبات'.$suffix, (int) $t['dropoffs'], 0.0, (float) $t['dropoff_payment']),
        ];
        if (abs((float) $t['service_payment']) > 0.0005) {
            $details[] = self::line('رسم مستوى الخدمة', 0, 0.0, (float) $t['service_payment']);
        }
        if (abs((float) $t['adjustments']) > 0.0005) {
            $details[] = self::line('خصومات وتعديلات طلبات على السائقين', 0, 0.0, (float) $t['adjustments']);
        }

        return [
            'revenue' => round((float) $t['revenue'], 3),
            'fixed_revenue' => 0.0,
            'orders' => (int) $t['orders_ours'],
            'unpriced_orders' => 0,
            'details' => $details,
            'talabat' => ['estimated' => (bool) $month['estimated']],
        ];
    }

    /**
     * One day's orders: at the rates of that day while the month is estimated, at the statement's own
     * average per order once it is in. The service-level payment is paid for the month, not a day.
     */
    public static function dayRevenue(Contract $contract, int $year, int $month, Collection $dayLogs): float
    {
        $result = self::forMonth($contract, $year, $month);
        if (! $result['estimated']) {
            return round((int) $dayLogs->sum('orders_count') * (float) $result['totals']['price_per_order'], 3);
        }

        $rates = self::rates($contract);
        $bikes = self::bikeTypeIds();
        $total = 0.0;
        foreach ($dayLogs as $log) {
            $rate = self::rateOn($rates, Carbon::parse((string) $log->log_date)->toDateString(), self::vehicleOf($log, $bikes));
            $total += (int) $log->orders_count * ($rate['pickup'] + $rate['dropoff']);
        }

        return round($total, 3);
    }

    /**
     * Each driver's share of the month, the way ContractRevenueService::forContractDrivers answers.
     *
     * @param  array<string, mixed>  $month
     * @return array{contract: array{revenue: float, orders: int, unpriced_orders: int}, drivers: array<int, array{revenue: float, orders: int, unpriced_orders: int, days: int}>}
     */
    public static function asDriverSplit(array $month): array
    {
        $drivers = [];
        foreach ($month['drivers'] as $empId => $row) {
            $drivers[(int) $empId] = [
                'revenue' => round((float) $row['revenue'], 3),
                'orders' => (int) $row['orders_ours'],
                'unpriced_orders' => 0,
                'days' => (int) $row['days'],
            ];
        }

        return [
            'contract' => [
                'revenue' => round((float) $month['totals']['revenue'], 3),
                'orders' => (int) $month['totals']['orders_ours'],
                'unpriced_orders' => 0,
            ],
            'drivers' => $drivers,
        ];
    }

    /**
     * @param  Collection<int, DailyLog>  $logs
     * @return array<string, mixed>
     */
    private static function invoiced(TalabatStatement $statement, Collection $logs): array
    {
        $ours = self::ordersByDriver($logs);
        $names = self::names(array_merge(array_keys($ours), $statement->riders->pluck('employee_id')->filter()->all()));

        $drivers = [];
        $unmatched = [];
        foreach ($statement->riders as $rider) {
            $row = [
                'rider_id' => $rider->rider_id,
                'talabat_name' => $rider->name,
                'vehicle' => $rider->vehicle,
                'hours' => round((float) $rider->hours, 2),
                'orders_talabat' => (int) $rider->deliveries,
                'pickups' => (int) $rider->pickups,
                'dropoffs' => (int) $rider->dropoffs,
                'pickup_payment' => round((float) $rider->pickup_payment, 3),
                'dropoff_payment' => round((float) $rider->dropoff_payment, 3),
                'service_payment' => round((float) $rider->service_payment, 3),
                'adjustments' => round((float) $rider->adjustments, 3),
                'revenue' => round((float) $rider->net, 3),
            ];
            if (! $rider->employee_id) {
                $unmatched[] = $row;

                continue;
            }
            $empId = (int) $rider->employee_id;
            // Two rows of one driver (a second rider id) add up to one.
            if (isset($drivers[$empId])) {
                foreach (['orders_talabat', 'pickups', 'dropoffs', 'pickup_payment', 'dropoff_payment', 'service_payment', 'adjustments', 'revenue', 'hours'] as $k) {
                    $drivers[$empId][$k] = round($drivers[$empId][$k] + $row[$k], 3);
                }

                continue;
            }
            $drivers[$empId] = self::driverRow($empId, $names, $ours) + $row;
        }
        foreach ($ours as $empId => $own) {
            $drivers[$empId] ??= self::driverRow($empId, $names, $ours) + [
                'rider_id' => null, 'talabat_name' => null, 'vehicle' => null, 'hours' => 0.0, 'orders_talabat' => 0,
                'pickups' => 0, 'dropoffs' => 0, 'pickup_payment' => 0.0, 'dropoff_payment' => 0.0,
                'service_payment' => 0.0, 'adjustments' => 0.0, 'revenue' => 0.0,
            ];
        }
        foreach ($drivers as $empId => $row) {
            $drivers[$empId]['orders_diff'] = $row['orders_ours'] - $row['orders_talabat'];
        }

        $deliveries = (int) $statement->deliveries;
        $revenue = round((float) $statement->riders_total, 3);

        return [
            'estimated' => false,
            'statement' => [
                'id' => $statement->id,
                'original_filename' => $statement->original_filename,
                'imported_at' => $statement->created_at?->toDateTimeString(),
                'imported_by' => $statement->importer?->name,
                'riders' => (int) $statement->riders_count,
                'company_lines' => $statement->company_lines ?? [],
                'company_total' => round((float) $statement->company_total, 3),
                'net_payment' => round((float) $statement->net_payment, 3),
            ],
            'totals' => [
                'orders_ours' => array_sum(array_column($ours, 'orders')),
                'orders_talabat' => $deliveries,
                'pickups' => (int) $statement->pickups,
                'dropoffs' => (int) $statement->dropoffs,
                'pickup_payment' => round((float) $statement->pickup_payment, 3),
                'dropoff_payment' => round((float) $statement->dropoff_payment, 3),
                'service_payment' => round((float) $statement->service_payment, 3),
                'adjustments' => round((float) $statement->rider_adjustments, 3),
                'revenue' => $revenue,
                'price_per_order' => $deliveries > 0 ? round($revenue / $deliveries, 4) : 0.0,
                'unmatched_revenue' => round(array_sum(array_column($unmatched, 'revenue')), 3),
            ],
            'periods' => [],
            'drivers' => self::sortRows($drivers),
            'unmatched' => $unmatched,
        ];
    }

    /**
     * @param  Collection<int, DailyLog>  $logs
     * @return array<string, mixed>
     */
    private static function estimated(Contract $contract, Collection $logs): array
    {
        $rates = self::rates($contract);
        $bikes = self::bikeTypeIds();
        $ours = self::ordersByDriver($logs);

        $byDriver = [];
        $periods = [];
        foreach ($logs as $log) {
            $count = (int) $log->orders_count;
            if ($count <= 0) {
                continue;
            }
            $vehicle = self::vehicleOf($log, $bikes);
            $rate = self::rateOn($rates, Carbon::parse((string) $log->log_date)->toDateString(), $vehicle);
            $empId = (int) $log->employee_id;
            $byDriver[$empId] ??= ['pickup' => 0.0, 'dropoff' => 0.0, 'vehicle' => $vehicle];
            $byDriver[$empId]['pickup'] += $count * $rate['pickup'];
            $byDriver[$empId]['dropoff'] += $count * $rate['dropoff'];

            // The month written out by period and vehicle, so the screen can show how it adds up.
            $pk = $rate['from'].':'.$vehicle;
            $periods[$pk] ??= ['from' => $rate['from'], 'vehicle' => $vehicle, 'orders' => 0, 'pickup_rate' => $rate['pickup'], 'dropoff_rate' => $rate['dropoff'], 'amount' => 0.0];
            $periods[$pk]['orders'] += $count;
            $periods[$pk]['amount'] = round($periods[$pk]['orders'] * ($rate['pickup'] + $rate['dropoff']), 3);
        }

        $names = self::names(array_keys($ours));
        $riderIds = self::riderIds($contract);
        $drivers = [];
        foreach ($ours as $empId => $own) {
            $pickup = round($byDriver[$empId]['pickup'] ?? 0.0, 3);
            $dropoff = round($byDriver[$empId]['dropoff'] ?? 0.0, 3);
            $drivers[$empId] = self::driverRow($empId, $names, $ours) + [
                'rider_id' => $riderIds[$empId] ?? null,
                'talabat_name' => null,
                'vehicle' => $byDriver[$empId]['vehicle'] ?? null,
                'hours' => null,
                'orders_talabat' => null,
                'pickups' => $own['orders'],
                'dropoffs' => $own['orders'],
                'pickup_payment' => $pickup,
                'dropoff_payment' => $dropoff,
                'service_payment' => 0.0,
                'adjustments' => 0.0,
                'revenue' => round($pickup + $dropoff, 3),
                'orders_diff' => null,
            ];
        }

        $orders = array_sum(array_column($ours, 'orders'));
        $pickupTotal = round(array_sum(array_column($drivers, 'pickup_payment')), 3);
        $dropoffTotal = round(array_sum(array_column($drivers, 'dropoff_payment')), 3);
        $revenue = round(array_sum(array_column($periods, 'amount')), 3);
        // The periods are the written sum; the fils rounding moved per driver go to the busiest one.
        $drivers = self::settleRounding($drivers, $revenue);
        ksort($periods);

        return [
            'estimated' => true,
            'statement' => null,
            'totals' => [
                'orders_ours' => $orders,
                'orders_talabat' => null,
                'pickups' => $orders,
                'dropoffs' => $orders,
                'pickup_payment' => $pickupTotal,
                'dropoff_payment' => $dropoffTotal,
                'service_payment' => 0.0,
                'adjustments' => 0.0,
                'revenue' => $revenue,
                'price_per_order' => $orders > 0 ? round($revenue / $orders, 4) : 0.0,
                'unmatched_revenue' => 0.0,
            ],
            'periods' => array_values($periods),
            'drivers' => self::sortRows($drivers),
            'unmatched' => [],
        ];
    }

    /**
     * @param  Collection<int, DailyLog>|null  $logs
     * @return Collection<int, DailyLog>
     */
    private static function withDriverAndDate(Contract $contract, int $year, int $month, ?Collection $logs): Collection
    {
        if ($logs !== null && $logs->isNotEmpty()) {
            $attributes = $logs->first()->getAttributes();
            if (array_key_exists('employee_id', $attributes) && array_key_exists('log_date', $attributes)) {
                return $logs;
            }
        }

        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $logs !== null && $logs->isNotEmpty() && ($logs->max('log_date') ?? null)
            ? Carbon::parse((string) $logs->max('log_date'))->toDateString()
            : $start->copy()->endOfMonth()->toDateString();

        return DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('contract_id', $contract->id)
            ->whereBetween('log_date', [$start->toDateString(), $end])
            ->with('vehicle:id,vehicle_type_id')
            ->get(['id', 'employee_id', 'contract_id', 'vehicle_id', 'log_date', 'orders_count']);
    }

    /**
     * @param  Collection<int, DailyLog>  $logs
     * @return array<int, array{orders: int, days: int}>
     */
    private static function ordersByDriver(Collection $logs): array
    {
        $out = [];
        foreach ($logs as $log) {
            $count = (int) $log->orders_count;
            if ($count <= 0) {
                continue;
            }
            $empId = (int) $log->employee_id;
            $out[$empId] ??= ['orders' => 0, 'days' => 0];
            $out[$empId]['orders'] += $count;
            $out[$empId]['days']++;
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $drivers
     * @return array<int, array<string, mixed>>
     */
    private static function settleRounding(array $drivers, float $total): array
    {
        $gap = round($total - array_sum(array_column($drivers, 'revenue')), 3);
        if ($drivers === [] || abs($gap) < 0.0005) {
            return $drivers;
        }
        $busiest = array_key_first($drivers);
        foreach ($drivers as $empId => $row) {
            if ($row['orders_ours'] > $drivers[$busiest]['orders_ours']) {
                $busiest = $empId;
            }
        }
        $drivers[$busiest]['revenue'] = round($drivers[$busiest]['revenue'] + $gap, 3);

        return $drivers;
    }

    /** @param  list<int>  $bikes */
    private static function vehicleOf(object $log, array $bikes): string
    {
        return in_array((int) ($log->vehicle?->vehicle_type_id ?? 0), $bikes, true) ? 'bike' : 'car';
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, array{orders: int, days: int}>  $ours
     * @return array<string, mixed>
     */
    private static function driverRow(int $empId, array $names, array $ours): array
    {
        return [
            'employee_id' => $empId,
            'employee_name' => $names[$empId] ?? "#{$empId}",
            'orders_ours' => (int) ($ours[$empId]['orders'] ?? 0),
            'days' => (int) ($ours[$empId]['days'] ?? 0),
        ];
    }

    /**
     * @param  list<int|string|null>  $ids
     * @return array<int, string>
     */
    private static function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : Employee::withoutGlobalScopes()->withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /** @return array<int, string> employee => Talabat rider id on his assignment to the contract */
    private static function riderIds(Contract $contract): array
    {
        return ContractAssignment::withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->whereNotNull('courier_id')->where('courier_id', '<>', '')
            ->pluck('courier_id', 'employee_id')
            ->all();
    }

    /** @return list<int> the vehicle types Talabat pays as a bike */
    private static function bikeTypeIds(): array
    {
        return DB::table('vehicle_types')->get(['id', 'name', 'name_ar'])
            ->filter(function ($type) {
                $name = mb_strtolower((string) $type->name.' '.(string) $type->name_ar);

                return str_contains($name, 'motor') || str_contains($name, 'bike') || str_contains($name, 'سيكل') || str_contains($name, 'دراج');
            })
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function sortRows(array $rows): array
    {
        uasort($rows, fn ($a, $b) => [$b['revenue'], $b['orders_ours']] <=> [$a['revenue'], $a['orders_ours']]);

        return $rows;
    }

    /** @return array<string, mixed> */
    private static function line(string $label, int $count, float $rate, float $amount): array
    {
        return [
            'label' => $label,
            'orders' => $count,
            'rate' => $rate,
            'amount' => round($amount, 3),
            'is_unpriced' => false,
            // The rate varies within a month (by period and vehicle), so the count is named, not multiplied.
            'formula' => number_format($amount, 3, '.', '').' د.ك'.($count > 0 ? " ({$count})" : ''),
        ];
    }
}
