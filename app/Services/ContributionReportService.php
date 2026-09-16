<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Contribution: what each contract and each driver brought in above what the driver was paid.
 *
 * One rule, from the consultant's review of 2026-09-12: revenue is the driver's own orders priced
 * by his contract's client rules, and cost is what that driver earned on that contract in the
 * contract sheet itself — the frozen sheet once it is approved. The report can therefore never
 * disagree with payroll, and the driver rows of a contract add up to its contract row to the fils.
 */
class ContributionReportService
{
    public const BASIS = 'الإيراد = طلبات السائق مسعَّرة بقواعد عميل عقده · التكلفة = ما استحقه في كشف العقد نفسه (المعتمد إن اعتُمد) · المساهمة = الفرق · الربح بعد المصاريف المسجَّلة = المساهمة − مصاريف المركبات − حصة الشركة من الصيانة والمخالفات − الإداريون الموزَّعون على العقد. تسويات السائقين ومخالفاتهم المخصومة ليست تكلفة على الشركة؛ مكانها كشف الرواتب.';

    public const GRADE_BASIS = 'التقييم: A من 100٪ فأكثر، B من 80٪، C من 60٪، D تحتها — من هدف السائق الشهري إن كان مسجَّلاً في ملفه، وإلا نسبةً إلى متوسط طلبات زملائه في اليوم على العقد نفسه (يحتاج سائقَين على الأقل).';

    /**
     * @return array<string, mixed>
     */
    public static function forMonth(int $companyId, int $year, int $month, ?int $contractId = null): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = Carbon::parse($start)->endOfMonth()->toDateString();

        $options = Contract::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $contracts = Contract::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->when($contractId, fn ($q) => $q->whereKey($contractId))
            ->with('client:id,name')
            ->orderBy('name')
            ->get();

        $logsByContract = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereBetween('log_date', [$start, $end])
            ->when($contractId, fn ($q) => $q->where('contract_id', $contractId))
            ->with('vehicle:id,vehicle_type_id')
            ->get(['id', 'employee_id', 'contract_id', 'vehicle_id', 'log_date', 'orders_count', 'zone', 'notes'])
            ->groupBy('contract_id');

        $employeeIds = $logsByContract->flatten(1)->pluck('employee_id')->unique()->all();
        $targets = $employeeIds === []
            ? collect()
            : Employee::withoutGlobalScopes()->whereIn('id', $employeeIds)->pluck('target_orders_monthly', 'id');

        // One pass of the profitability service for these contracts, sheets included: the driver
        // rows read the sheet, and the contract row carries the same recorded expenses and profit
        // the dashboard shows — nothing is computed twice, so nothing can come out differently.
        $profitRows = ContractProfitabilityService::forCompanyMonth($companyId, $year, $month, $contracts, true);

        $contractRows = [];
        $driverRows = [];
        foreach ($contracts as $contract) {
            $logs = $logsByContract->get($contract->id);
            $profit = $profitRows[$contract->id] ?? null;
            if (! $logs || $logs->isEmpty() || ! $profit) {
                continue;
            }

            $sheet = $profit['sheet'] ?? [];
            $split = ContractRevenueService::forContractDrivers($contract, $logs);

            $rows = [];
            foreach ($sheet['drivers'] ?? [] as $driver) {
                $empId = (int) ($driver['employee_id'] ?? 0);
                $share = $split['drivers'][$empId] ?? ['revenue' => 0.0, 'orders' => 0, 'unpriced_orders' => 0, 'days' => 0];
                $cost = round((float) ($driver['gross_contract_earnings'] ?? 0), 3);
                $orders = (int) ($driver['orders_count'] ?? $share['orders']);
                $revenue = round((float) $share['revenue'], 3);
                if ($orders <= 0 && $cost <= 0.0005 && $revenue <= 0.0005) {
                    continue;
                }
                $contribution = round($revenue - $cost, 3);
                $rows[] = [
                    'employee_id' => $empId,
                    'employee_name' => $driver['employee_name'] ?? "#{$empId}",
                    'employee_number' => $driver['employee_number'] ?? null,
                    'contract_id' => (int) $contract->id,
                    'contract_name' => $contract->name,
                    'orders' => $orders,
                    'unpriced_orders' => (int) $share['unpriced_orders'],
                    'work_days' => (int) ($driver['actual_work_days'] ?? $share['days']),
                    'revenue' => $revenue,
                    'driver_cost' => $cost,
                    'contribution' => $contribution,
                    'margin_pct' => $revenue > 0 ? round($contribution / $revenue * 100, 1) : null,
                    'target' => null,
                    'achievement_pct' => null,
                    'grade' => null,
                    'grade_basis' => null,
                ];
            }

            // Logs with nothing on them (a row per driver opened for the month, no orders yet) are
            // not activity: such a contract has no row until something is delivered or earned.
            if ($rows === [] && (int) $split['contract']['orders'] <= 0) {
                continue;
            }

            self::grade($rows, $targets);

            $revenue = round((float) $split['contract']['revenue'], 3);
            $cost = round(array_sum(array_column($rows, 'driver_cost')), 3);
            $contribution = round($revenue - $cost, 3);
            $contractRows[] = [
                'contract_id' => (int) $contract->id,
                'contract_name' => $contract->name,
                'client_name' => $contract->client?->name ?? '—',
                'orders' => (int) $split['contract']['orders'],
                'unpriced_orders' => (int) $split['contract']['unpriced_orders'],
                'drivers_count' => count($rows),
                'revenue' => $revenue,
                'driver_cost' => $cost,
                'contribution' => $contribution,
                'margin_pct' => $revenue > 0 ? round($contribution / $revenue * 100, 1) : null,
                // The expenses on record beyond the drivers, and what is left after them — the
                // profitability figures, column by column, so the owner sees where the rest goes.
                'vehicle_costs' => round((float) $profit['vehicle_costs'], 3),
                'maintenance_cost' => round((float) $profit['maintenance_cost'], 3),
                'violations_cost' => round((float) $profit['violations_cost'], 3),
                'supervisors_cost' => round((float) $profit['supervisors_cost'], 3),
                'other_expenses' => round((float) $profit['expenses'] - (float) $profit['driver_cost'], 3),
                'profit' => round((float) $profit['profit'], 3),
                'profit_margin_pct' => $revenue > 0 ? round((float) $profit['profit'] / $revenue * 100, 1) : null,
                'sheet_is_approved' => (bool) ($sheet['is_approved'] ?? false),
            ];
            array_push($driverRows, ...$rows);
        }

        usort($contractRows, fn (array $a, array $b) => $b['contribution'] <=> $a['contribution']);
        usort($driverRows, fn (array $a, array $b) => $b['contribution'] <=> $a['contribution']);

        $revenue = round(array_sum(array_column($contractRows, 'revenue')), 3);
        $cost = round(array_sum(array_column($contractRows, 'driver_cost')), 3);
        $contribution = round($revenue - $cost, 3);
        $otherExpenses = round(array_sum(array_column($contractRows, 'other_expenses')), 3);
        $profit = round(array_sum(array_column($contractRows, 'profit')), 3);

        return [
            'year' => $year,
            'month' => $month,
            'contract_id' => $contractId,
            'basis' => self::BASIS,
            'grade_basis' => self::GRADE_BASIS,
            'contracts' => $contractRows,
            'drivers' => $driverRows,
            'totals' => [
                'revenue' => $revenue,
                'driver_cost' => $cost,
                'contribution' => $contribution,
                'margin_pct' => $revenue > 0 ? round($contribution / $revenue * 100, 1) : null,
                'other_expenses' => $otherExpenses,
                'profit' => $profit,
                'profit_margin_pct' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
                'orders' => (int) array_sum(array_column($contractRows, 'orders')),
                'unpriced_orders' => (int) array_sum(array_column($contractRows, 'unpriced_orders')),
                'contracts' => count($contractRows),
                'drivers' => count($driverRows),
            ],
            'contract_options' => $options->map(fn (Contract $c) => ['id' => (int) $c->id, 'name' => $c->name])->values()->all(),
        ];
    }

    /**
     * A letter per driver row. A personal monthly target on the driver's file is measured directly;
     * without one, the driver is read against the average orders a day of his colleagues on the
     * same contract this month. The contract's own `target_orders_monthly` is not used: it carries
     * a 400 that no screen ever sets, not a target anyone chose.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  Collection<int, int|null>  $targets
     */
    private static function grade(array &$rows, $targets): void
    {
        $withDays = array_filter($rows, fn (array $r) => $r['work_days'] > 0);
        $avgPerDay = null;
        if (count($withDays) >= 2) {
            $days = array_sum(array_column($withDays, 'work_days'));
            $avgPerDay = $days > 0 ? array_sum(array_column($withDays, 'orders')) / $days : null;
        }

        foreach ($rows as &$row) {
            $target = (int) ($targets[$row['employee_id']] ?? 0);
            if ($target > 0) {
                $pct = round($row['orders'] / $target * 100, 1);
                $row['target'] = $target;
                $row['achievement_pct'] = $pct;
                $row['grade'] = self::letter($pct, [100, 80, 60]);
                $row['grade_basis'] = 'personal_target';

                continue;
            }
            if ($avgPerDay === null || $avgPerDay <= 0 || $row['work_days'] <= 0) {
                continue;
            }
            $pct = round(($row['orders'] / $row['work_days']) / $avgPerDay * 100, 1);
            $row['achievement_pct'] = $pct;
            $row['grade'] = self::letter($pct, [120, 90, 60]);
            $row['grade_basis'] = 'contract_average';
        }
        unset($row);
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $cuts  the A, B and C floors
     */
    private static function letter(float $pct, array $cuts): string
    {
        return match (true) {
            $pct >= $cuts[0] => 'A',
            $pct >= $cuts[1] => 'B',
            $pct >= $cuts[2] => 'C',
            default => 'D',
        };
    }
}
