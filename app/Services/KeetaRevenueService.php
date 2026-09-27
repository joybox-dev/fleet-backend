<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\KeetaInvoice;
use App\Models\KeetaLevelSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What Keeta pays the company for a contract-month, from the first month the contract is read this
 * way (`contracts.keeta_settlement_from`).
 *
 * Keeta prices every order by its own distance and ranks every rider against its whole market, so
 * neither half of its figure can be reproduced here. The month therefore has two states:
 *
 * - **Invoiced** — Keeta's statement for the month has been imported. Its invoice amount is the
 *   month's revenue, to the fils, and each rider's row is that driver's share. Tips are the riders'
 *   own money and never count.
 * - **Estimated** — no statement yet. The orders logged here are priced at what Keeta paid per order
 *   in the last statement (its base fee before any statement exists), and each driver's incentive is
 *   the one Keeta's newest «expected level» export for the month names, or else the one he was paid
 *   the month before. Keeta's deductions are not guessed. The figure says it is an estimate.
 *
 * Nothing here touches driver pay.
 */
class KeetaRevenueService
{
    /** Keeta's base fee per order, before a statement has shown what an order really pays. */
    public const BASE_FEE = ['car' => 0.650, 'bike' => 0.550];

    /** @var array<string, array<string, mixed>> */
    private static array $memo = [];

    public static function forget(): void
    {
        self::$memo = [];
    }

    public static function appliesTo(Contract $contract, int $year, int $month): bool
    {
        $from = $contract->keeta_settlement_from;
        if (! $from) {
            return false;
        }
        $from = $from instanceof Carbon ? $from : Carbon::parse($from);

        return ($year * 12 + $month) >= ($from->year * 12 + $from->month);
    }

    /**
     * The month a set of one contract's logs belongs to, when that month is read the Keeta way.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function monthOfLogs(Contract $contract, Collection $logs): ?array
    {
        if (! $contract->keeta_settlement_from || $logs->isEmpty()) {
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
     * The month in full: its state, how the figure was reached, a row per driver and Keeta's riders
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
        $ours = self::ordersByDriver($logs);

        $invoice = KeetaInvoice::withoutGlobalScopes()
            ->where('contract_id', $contract->id)->where('year', $year)->where('month', $month)
            ->with('riders')
            ->first();

        $result = $invoice
            ? self::invoiced($invoice, $ours)
            : self::estimated($contract, $year, $month, $ours, $logs);

        $result['year'] = $year;
        $result['month'] = $month;
        $result['settlement_from'] = $contract->keeta_settlement_from
            ? Carbon::parse($contract->keeta_settlement_from)->format('Y-m')
            : null;

        if ($given === null) {
            self::$memo[$key] = $result;
        }

        return $result;
    }

    /**
     * The month shaped the way ContractRevenueService::forContractMonth answers, so every report
     * that prices a contract-month reads Keeta's figure without knowing it is Keeta's.
     *
     * @param  array<string, mixed>  $month
     * @return array<string, mixed>
     */
    public static function asContractRevenue(array $month): array
    {
        $t = $month['totals'];
        // An estimate is our orders × one price; a statement prices each order by its distance, so
        // its line is Keeta's own sum over Keeta's count, not a product that would not add up.
        $details = [
            $month['estimated']
                ? self::line('طلبات كيتا (تقدير)', (int) $t['orders'], (float) $t['price_per_order'], (float) $t['order_revenue'])
                : self::line('تسعير كيتا لطلبات الشهر', (int) $t['orders_keeta'], 0.0, (float) $t['order_revenue']),
        ];
        if (abs((float) $t['incentives']) > 0.0005) {
            $details[] = self::line($month['estimated'] ? 'حوافز كيتا (تقدير)' : 'حوافز كيتا', 0, 0.0, (float) $t['incentives']);
        }
        if (abs((float) $t['adjustments']) > 0.0005) {
            $details[] = self::line('خصومات وتعديلات كيتا', 0, 0.0, (float) $t['adjustments']);
        }

        return [
            'revenue' => round((float) $t['revenue'], 3),
            'fixed_revenue' => 0.0,
            'orders' => (int) $t['orders_ours'],
            'unpriced_orders' => 0,
            'details' => $details,
            'keeta' => [
                'estimated' => (bool) $month['estimated'],
                'basis' => $month['basis'],
            ],
        ];
    }

    /**
     * One day's orders at the month's price per order — the statement's own average once it is in.
     * Incentives are paid for the month and never land on a day.
     */
    public static function dayRevenue(Contract $contract, int $year, int $month, Collection $dayLogs): float
    {
        $price = self::forMonth($contract, $year, $month)['totals']['price_per_order'];

        return round((int) $dayLogs->sum('orders_count') * (float) $price, 3);
    }

    /**
     * The split ContractRevenueService::forContractDrivers answers: each driver's share of the month.
     * Riders Keeta paid that no driver of ours carries count in the contract figure only.
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
     * @param  array<int, array{orders: int, days: int}>  $ours
     * @return array<string, mixed>
     */
    private static function invoiced(KeetaInvoice $invoice, array $ours): array
    {
        $names = self::names(array_merge(array_keys($ours), $invoice->riders->pluck('employee_id')->filter()->all()));
        $price = $invoice->averageOrderPrice() ?? 0.0;

        $drivers = [];
        $unmatched = [];
        foreach ($invoice->riders as $rider) {
            $row = [
                'courier_id' => $rider->courier_id,
                'keeta_name' => $rider->name,
                'orders_keeta' => (int) $rider->orders,
                'is_valid' => (bool) $rider->is_valid,
                'level' => null,
                'order_revenue' => round((float) $rider->order_pricing, 3),
                'incentive' => $rider->incentives(),
                'adjustments' => $rider->adjustments(),
                'tips' => round((float) $rider->tips, 3),
                'revenue' => $rider->revenue(),
            ];
            if (! $rider->employee_id) {
                $unmatched[] = $row;

                continue;
            }
            $empId = (int) $rider->employee_id;
            $drivers[$empId] = self::driverRow($empId, $names, $ours) + $row;
        }

        // A driver we logged that Keeta's statement has no row for earned nothing from it.
        foreach ($ours as $empId => $own) {
            $drivers[$empId] ??= self::driverRow($empId, $names, $ours) + [
                'courier_id' => null, 'keeta_name' => null, 'orders_keeta' => 0, 'is_valid' => null, 'level' => null,
                'order_revenue' => 0.0, 'incentive' => 0.0, 'adjustments' => 0.0, 'tips' => 0.0, 'revenue' => 0.0,
            ];
        }
        foreach ($drivers as $empId => $row) {
            $drivers[$empId]['orders_diff'] = $row['orders_ours'] - $row['orders_keeta'];
        }

        return [
            'estimated' => false,
            'invoice' => [
                'id' => $invoice->id,
                'billing_cycle' => $invoice->billing_cycle,
                'original_filename' => $invoice->original_filename,
                'imported_at' => $invoice->created_at?->toDateTimeString(),
                'imported_by' => $invoice->importer?->name,
                'riders' => (int) $invoice->riders_count,
                'valid_riders' => (int) $invoice->valid_riders,
                'invoice_amount' => round((float) $invoice->invoice_amount, 3),
                'total_payable' => round((float) $invoice->total_payable, 3),
            ],
            'basis' => [
                'price_per_order' => $price,
                'price_source' => 'invoice',
                'price_source_month' => sprintf('%04d-%02d', $invoice->year, $invoice->month),
                'incentive_source' => 'invoice',
                'levels_taken_on' => null,
            ],
            'totals' => [
                'orders_ours' => array_sum(array_column($ours, 'orders')),
                'orders_keeta' => (int) $invoice->orders_count,
                'orders' => array_sum(array_column($ours, 'orders')),
                'price_per_order' => $price,
                'order_revenue' => round((float) $invoice->order_pricing, 3),
                'incentives' => $invoice->incentives(),
                'adjustments' => $invoice->adjustments(),
                'tips' => round((float) $invoice->tips, 3),
                'revenue' => round((float) $invoice->invoice_amount, 3),
                'unmatched_revenue' => round(array_sum(array_column($unmatched, 'revenue')), 3),
            ],
            'drivers' => self::sortRows($drivers),
            'unmatched' => $unmatched,
        ];
    }

    /**
     * @param  array<int, array{orders: int, days: int}>  $ours
     * @return array<string, mixed>
     */
    private static function estimated(Contract $contract, int $year, int $month, array $ours, Collection $logs): array
    {
        $index = $year * 12 + $month;
        $previous = KeetaInvoice::withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->whereRaw('(year * 12 + month) < ?', [$index])
            ->orderByRaw('(year * 12 + month) desc')
            ->with('riders')
            ->get();
        $priced = $previous->first(fn (KeetaInvoice $i) => $i->orders_count > 0);
        $lastPaid = $previous->first();

        $snapshot = KeetaLevelSnapshot::withoutGlobalScopes()
            ->where('contract_id', $contract->id)->where('year', $year)->where('month', $month)
            ->orderByDesc('taken_on')->orderByDesc('id')
            ->with('rows')
            ->first();

        // Price per order: what the last statement paid on average; Keeta's base fee before any.
        $price = $priced?->averageOrderPrice();
        $priceSource = $priced ? 'last_invoice' : 'base_fee';
        $orderRevenueByDriver = [];
        if ($price !== null) {
            foreach ($ours as $empId => $own) {
                $orderRevenueByDriver[$empId] = round($own['orders'] * $price, 3);
            }
            // The month is its orders × that price, to the fils, as the screen writes it out.
            $orderRevenueByDriver = self::settleRounding($orderRevenueByDriver, round(array_sum(array_column($ours, 'orders')) * $price, 3), $ours);
        } else {
            $bikeTypes = self::bikeTypeIds();
            foreach ($logs as $log) {
                $count = (int) $log->orders_count;
                if ($count <= 0) {
                    continue;
                }
                $fee = in_array((int) ($log->vehicle?->vehicle_type_id ?? 0), $bikeTypes, true) ? self::BASE_FEE['bike'] : self::BASE_FEE['car'];
                $orderRevenueByDriver[(int) $log->employee_id] = round(($orderRevenueByDriver[(int) $log->employee_id] ?? 0.0) + $count * $fee, 3);
            }
            $totalOrders = array_sum(array_column($ours, 'orders'));
            $price = $totalOrders > 0 ? round(array_sum($orderRevenueByDriver) / $totalOrders, 4) : self::BASE_FEE['car'];
        }

        // Incentive: Keeta's own expected level when uploaded, else what the driver got last month.
        $incentiveByDriver = [];
        $levelByDriver = [];
        $unmatched = [];
        if ($snapshot) {
            $incentiveSource = 'levels';
            foreach ($snapshot->rows as $row) {
                $reward = $row->level ? round((float) $row->reward, 3) : 0.0;
                if (! $row->employee_id) {
                    $unmatched[] = [
                        'courier_id' => $row->courier_id, 'keeta_name' => $row->name, 'orders_keeta' => (int) $row->orders,
                        'is_valid' => null, 'level' => $row->level, 'order_revenue' => 0.0, 'incentive' => $reward,
                        'adjustments' => 0.0, 'tips' => 0.0, 'revenue' => 0.0,
                    ];

                    continue;
                }
                $incentiveByDriver[(int) $row->employee_id] = $reward;
                $levelByDriver[(int) $row->employee_id] = $row->level;
            }
        } elseif ($lastPaid) {
            $incentiveSource = 'last_invoice';
            foreach ($lastPaid->riders as $rider) {
                if ($rider->employee_id && isset($ours[(int) $rider->employee_id])) {
                    $incentiveByDriver[(int) $rider->employee_id] = $rider->incentives();
                }
            }
        } else {
            $incentiveSource = 'none';
        }

        $names = self::names(array_merge(array_keys($ours), array_keys($incentiveByDriver)));
        $couriers = self::courierIds($contract);
        $drivers = [];
        foreach (array_unique(array_merge(array_keys($ours), array_keys($incentiveByDriver))) as $empId) {
            $orderRevenue = $orderRevenueByDriver[$empId] ?? 0.0;
            $incentive = $incentiveByDriver[$empId] ?? 0.0;
            $drivers[$empId] = self::driverRow($empId, $names, $ours) + [
                'courier_id' => $couriers[$empId] ?? null,
                'keeta_name' => null,
                'orders_keeta' => null,
                'is_valid' => null,
                'level' => $levelByDriver[$empId] ?? null,
                'order_revenue' => $orderRevenue,
                'incentive' => $incentive,
                'adjustments' => 0.0,
                'tips' => 0.0,
                'revenue' => round($orderRevenue + $incentive, 3),
                'orders_diff' => null,
            ];
        }

        $orderRevenue = round(array_sum($orderRevenueByDriver), 3);
        $incentives = round(array_sum(array_column($drivers, 'incentive')), 3);
        $ordersOurs = array_sum(array_column($ours, 'orders'));

        return [
            'estimated' => true,
            'invoice' => null,
            'basis' => [
                'price_per_order' => $price,
                'price_source' => $priceSource,
                'price_source_month' => $priced ? sprintf('%04d-%02d', $priced->year, $priced->month) : null,
                'incentive_source' => $incentiveSource,
                'incentive_source_month' => $incentiveSource === 'last_invoice' ? sprintf('%04d-%02d', $lastPaid->year, $lastPaid->month) : null,
                'levels_taken_on' => $snapshot?->taken_on?->toDateString(),
            ],
            'totals' => [
                'orders_ours' => $ordersOurs,
                'orders_keeta' => null,
                'orders' => $ordersOurs,
                'price_per_order' => $price,
                'order_revenue' => $orderRevenue,
                'incentives' => $incentives,
                'adjustments' => 0.0,
                'tips' => 0.0,
                'revenue' => round($orderRevenue + $incentives, 3),
                'unmatched_revenue' => 0.0,
            ],
            'drivers' => self::sortRows($drivers),
            'unmatched' => $unmatched,
        ];
    }

    /**
     * The logs to read: those given, when they carry a driver and a date, else the month's own.
     *
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
     * Shares rounded to the fils one by one, brought back to their exact total: the fils that
     * rounding moved go to the driver with the most orders.
     *
     * @param  array<int, float>  $shares
     * @param  array<int, array{orders: int, days: int}>  $ours
     * @return array<int, float>
     */
    private static function settleRounding(array $shares, float $total, array $ours): array
    {
        $gap = round($total - array_sum($shares), 3);
        if ($shares === [] || abs($gap) < 0.0005) {
            return $shares;
        }
        $busiest = array_key_first($shares);
        foreach (array_keys($shares) as $empId) {
            if (($ours[$empId]['orders'] ?? 0) > ($ours[$busiest]['orders'] ?? 0)) {
                $busiest = $empId;
            }
        }
        $shares[$busiest] = round($shares[$busiest] + $gap, 3);

        return $shares;
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

    /** @return array<int, string> */
    private static function courierIds(Contract $contract): array
    {
        return ContractAssignment::withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->whereNotNull('courier_id')->where('courier_id', '<>', '')
            ->pluck('courier_id', 'employee_id')
            ->all();
    }

    /** @return list<int> the vehicle types Keeta prices as a bike */
    private static function bikeTypeIds(): array
    {
        return DB::table('vehicle_types')->get(['id', 'name'])
            ->filter(function ($type) {
                $name = mb_strtolower((string) $type->name);

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
    private static function line(string $label, int $orders, float $rate, float $amount): array
    {
        return [
            'label' => $label,
            'orders' => $orders,
            'rate' => $rate,
            'amount' => round($amount, 3),
            'is_unpriced' => false,
            'formula' => $orders > 0 && $rate > 0
                ? "{$orders} طلب × {$rate} د.ك = ".number_format($amount, 3, '.', '').' د.ك'
                : number_format($amount, 3, '.', '').' د.ك',
        ];
    }
}
