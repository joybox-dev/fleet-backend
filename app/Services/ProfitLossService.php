<?php

namespace App\Services;

use App\Models\CompanyExpense;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\VehicleExpense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The company's month as an income statement: revenue, the direct cost of earning it, the gross
 * profit, the administrative and general spending, and the net.
 *
 * Every figure is read from where the system already keeps it, never recomputed here:
 * - revenue and driver pay are the contracts' own month (ContractProfitabilityService), so this
 *   statement and the profitability screens cannot disagree about them;
 * - vehicle costs are the whole fleet's (forVehiclesMonth), which also carries a vehicle that
 *   earned nothing this month — the contract rows only carry costs they can place on a contract;
 * - the company's share of driver expenses, which no profitability screen counted until now;
 * - spending from operational custody floats;
 * - the vehicles' fixed costs (rent, instalments, depreciation) from VehicleFixedCostService;
 * - administrative salaries, from the employees' files (the system pays no administrative payroll,
 *   so the monthly salary on file is the only figure there is — named as such);
 * - the company's own expenses, by its expense tree.
 *
 * Cash basis: each record counts in the month of its date.
 */
class ProfitLossService
{
    /**
     * @return array<string, mixed>
     */
    public static function forMonth(int $companyId, int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = Carbon::parse($start)->endOfMonth()->toDateString();

        $contracts = collect(ContractProfitabilityService::forCompanyMonth($companyId, $year, $month));
        $vehicles = ContractProfitabilityService::forVehiclesMonth($companyId, $year, $month);
        $fleet = $vehicles['totals'];

        $revenue = round($contracts->sum('revenue'), 3);

        $direct = [];
        $line = function (string $key, string $label, float $amount, ?string $note = null) use (&$direct) {
            if (abs($amount) >= 0.0005) {
                $direct[] = ['key' => $key, 'label' => $label, 'amount' => round($amount, 3), 'note' => $note];
            }
        };

        $line('driver_salaries', 'رواتب السائقين الأساسية', (float) $contracts->sum('driver_salaries'));
        $line('driver_commissions', 'عمولات وحوافز السائقين', (float) $contracts->sum('driver_commissions'));
        $line('fuel_allowance', 'بدل وقود المركبات', (float) $fleet['fuel_allowance']);
        foreach ($fleet['expenses_by_type'] as $type => $amount) {
            $line("vehicle_expense:{$type}", 'مصاريف مركبات · '.VehicleExpense::typeLabel((string) $type), (float) $amount);
        }
        $line('maintenance', 'صيانة وحوادث (حصة الشركة)', (float) $fleet['total_maintenance']);
        $line('violations', 'مخالفات (حصة الشركة)', (float) $fleet['total_violations']);
        $line('driver_expenses', 'مصاريف السائقين على الشركة', self::driverExpensesCompanyShare($companyId, $start, $end));

        $fixed = VehicleFixedCostService::forMonth($companyId, $year, $month);
        $line('vehicle_rent', 'إيجار السيارات المستأجرة', (float) $fixed['totals']['rent']);
        $line('vehicle_installments', 'أقساط السيارات الممولة', (float) $fixed['totals']['installment']);
        $line('vehicle_depreciation', 'إهلاك السيارات المملوكة', (float) $fixed['totals']['depreciation']);

        $directTotal = round(array_sum(array_column($direct, 'amount')), 3);
        $gross = round($revenue - $directTotal, 3);

        $admin = [];
        $adminSalaries = self::adminSalaries($companyId);
        if ($adminSalaries['total'] > 0) {
            $admin[] = [
                'key' => 'admin_salaries',
                'label' => 'رواتب الإداريين (من ملفاتهم)',
                'amount' => $adminSalaries['total'],
                'items' => $adminSalaries['items'],
                'note' => 'النظام لا يصرف رواتب الإداريين؛ الرقم هو الراتب الفعلي المسجّل في ملف كل موظف إداري نشط.',
            ];
        }

        $custody = self::custodySpending($companyId, $start, $end);
        if ($custody > 0) {
            $admin[] = ['key' => 'custody', 'label' => 'مصاريف العهد التشغيلية', 'amount' => $custody, 'items' => [], 'note' => null];
        }

        foreach (self::companyExpensesByCategory($companyId, $start, $end) as $category) {
            $admin[] = $category;
        }

        $adminTotal = round(array_sum(array_column($admin, 'amount')), 3);
        $net = round($gross - $adminTotal, 3);

        return [
            'period' => ['year' => $year, 'month' => $month, 'start' => $start, 'end' => $end],
            'revenue' => [
                'total' => $revenue,
                'by_contract' => $contracts
                    ->filter(fn ($r) => abs((float) $r['revenue']) >= 0.0005)
                    ->map(fn ($r) => ['contract_id' => $r['contract_id'], 'contract_name' => $r['contract_name'], 'client_name' => $r['client_name'], 'amount' => round((float) $r['revenue'], 3)])
                    ->sortByDesc('amount')->values()->all(),
                'unpriced_orders' => (int) $contracts->sum('unpriced_orders'),
            ],
            'direct_costs' => ['lines' => $direct, 'total' => $directTotal],
            'gross_profit' => $gross,
            'gross_margin' => $revenue > 0 ? round($gross / $revenue * 100, 2) : 0.0,
            'admin_costs' => ['lines' => $admin, 'total' => $adminTotal],
            'net_profit' => $net,
            'net_margin' => $revenue > 0 ? round($net / $revenue * 100, 2) : 0.0,
            'notes' => array_values(array_filter([
                ($unpriced = (int) $contracts->sum('unpriced_orders')) > 0 ? "{$unpriced} طلب بلا سعر لم يدخل الإيراد." : null,
                ($fleet['maintenance_pending'] ?? 0) > 0 ? 'صيانة بانتظار الاعتماد ('.number_format((float) $fleet['maintenance_pending'], 3).') لا تُحسب قبل اعتمادها.' : null,
                'أساس نقدي: كل مصروف يُحسب في شهر تاريخه.',
            ])),
        ];
    }

    /** What the company bore of its drivers' expenses — the part not taken from their pay. */
    private static function driverExpensesCompanyShare(int $companyId, string $start, string $end): float
    {
        return round((float) DriverExpense::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereBetween('expense_date', [$start, $end])
            ->sum('company_amount'), 3);
    }

    /** Spent from operational custody floats in the month, whatever contract it named. */
    private static function custodySpending(int $companyId, string $start, string $end): float
    {
        return round((float) DB::table('operational_advance_expenses as e')
            ->join('operational_advances as a', 'a.id', '=', 'e.operational_advance_id')
            ->where('a.company_id', $companyId)
            ->whereBetween('e.date', [$start, $end])
            ->sum('e.amount'), 3);
    }

    /**
     * @return array{total: float, items: array<int, array{name: string, amount: float}>}
     */
    private static function adminSalaries(int $companyId): array
    {
        $items = Employee::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('role_category', 'admin')
            ->where('status', 'active')
            ->where('actual_salary', '>', 0)
            ->orderBy('name')
            ->get(['name', 'actual_salary'])
            ->map(fn ($e) => ['name' => $e->name, 'amount' => round((float) $e->actual_salary, 3)]);

        return ['total' => round($items->sum('amount'), 3), 'items' => $items->values()->all()];
    }

    /**
     * The company's own expenses by category of its tree, each with its items.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function companyExpensesByCategory(int $companyId, string $start, string $end): array
    {
        $rows = CompanyExpense::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereBetween('expense_date', [$start, $end])
            ->selectRaw('expense_category_id, SUM(amount) AS total')
            ->groupBy('expense_category_id')
            ->pluck('total', 'expense_category_id');
        if ($rows->isEmpty()) {
            return [];
        }

        $nodes = ExpenseCategory::withoutGlobalScopes()->where('company_id', $companyId)->get()->keyBy('id');
        $categories = [];
        foreach ($rows as $nodeId => $total) {
            $node = $nodes->get($nodeId);
            $parent = $node?->parent_id ? $nodes->get($node->parent_id) : $node;
            $key = 'category:'.($parent?->id ?? 0);
            $categories[$key] ??= ['key' => $key, 'label' => $parent?->name_ar ?? 'بلا فئة', 'amount' => 0.0, 'items' => [], 'note' => null, 'sort' => $parent?->sort_order ?? 999];
            $categories[$key]['amount'] = round($categories[$key]['amount'] + (float) $total, 3);
            $categories[$key]['items'][] = ['name' => $node?->parent_id ? $node->name_ar : ($node?->name_ar ?? '—'), 'amount' => round((float) $total, 3)];
        }

        return collect($categories)->sortBy('sort')->map(function ($c) {
            unset($c['sort']);
            usort($c['items'], fn ($a, $b) => $b['amount'] <=> $a['amount']);

            return $c;
        })->values()->all();
    }
}
