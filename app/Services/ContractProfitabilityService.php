<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\MaintenanceRecord;
use App\Models\SupervisorCostAllocation;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleExpense;
use App\Models\Violation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * What a contract earned and what it cost for a month — one definition.
 *
 * The contract's own dashboard, the main dashboard and the two profitability reports each used to
 * work this out on their own, with their own idea of which accident counts and whose fuel it was,
 * and read different columns for the drivers' pay. The same contract in the same month came out
 * with a profit of −57.679 on one screen and +179.821 on the next. They all read this now.
 *
 * The rules, stated once:
 *  - Revenue is the contract's logs priced by its client rules. Nothing is read from
 *    `daily_logs.income_amount`, a column filled from a flat rate no live contract has.
 *  - Driver cost is the contract payroll sheet — what the drivers are actually paid.
 *  - A vehicle's expense, repair or fine is charged to the contract(s) the vehicle worked on THAT
 *    DAY, split equally when it worked several; a day with no log falls back to the contract the
 *    vehicle's assignment names; a fine raised against a contract goes there outright, and a fine
 *    on a driver with no vehicle match goes to the contract he logged on that day.
 *  - Only the company's share of a repair or a fine is a cost here: what the driver bears is his.
 *  - A vehicle's monthly fuel allowance follows its orders across the contracts it worked.
 *  - A supervisor's cost is the allocation in force for the month, at the percentage set.
 */
class ContractProfitabilityService
{
    /**
     * One contract's month. `$withSheet` carries the payroll sheet along for the screen that
     * shows the drivers.
     *
     * @return array<string, mixed>
     */
    public static function forContractMonth(Contract $contract, int $year, int $month, int $companyId, bool $withSheet = false): array
    {
        $context = self::monthContext($companyId, $year, $month);

        return self::contractRow($contract, $context, $withSheet);
    }

    /**
     * Every active contract's month, keyed by contract id.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forCompanyMonth(int $companyId, int $year, int $month, ?Collection $contracts = null): array
    {
        $contracts ??= Contract::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->with('client:id,name')
            ->get();

        $context = self::monthContext($companyId, $year, $month);

        $rows = [];
        foreach ($contracts as $contract) {
            $rows[$contract->id] = self::contractRow($contract, $context, false);
        }

        return $rows;
    }

    /**
     * The same month read per vehicle: its share of the revenue and driver pay of every contract
     * it worked, and the repairs and fines it carries itself.
     *
     * @return array{vehicles: array<int, array<string, mixed>>, totals: array<string, float|int>}
     */
    public static function forVehiclesMonth(int $companyId, int $year, int $month): array
    {
        $context = self::monthContext($companyId, $year, $month);
        $contracts = Contract::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereIn('id', array_keys($context['logs_by_contract']))
            ->get();

        $revenueByVehicle = [];
        $driverCostByVehicle = [];

        foreach ($contracts as $contract) {
            $row = self::contractRow($contract, $context, true);
            $contractLogs = $context['logs_by_contract'][$contract->id] ?? collect();

            // Revenue follows the orders: a contract billed by bands is banded on its whole
            // month, so a vehicle's share of it is its share of the orders, not a re-pricing
            // of its own logs.
            $ordersByVehicle = $contractLogs->groupBy('vehicle_id')->map(fn ($l) => (int) $l->sum('orders_count'));
            $contractOrders = max(1, (int) $ordersByVehicle->sum());
            foreach ($ordersByVehicle as $vehicleId => $orders) {
                if (! $vehicleId) {
                    continue;
                }
                $revenueByVehicle[$vehicleId] = ($revenueByVehicle[$vehicleId] ?? 0.0)
                    + $row['revenue'] * ($orders / $contractOrders);
            }

            // A driver's pay follows the vehicle he earned it on; a fixed salary with no orders
            // follows the days instead.
            foreach ($row['sheet']['drivers'] ?? [] as $driver) {
                $gross = (float) ($driver['gross_contract_earnings'] ?? 0);
                if ($gross == 0.0) {
                    continue;
                }
                $driverLogs = $contractLogs->where('employee_id', $driver['employee_id']);
                $byVehicle = $driverLogs->groupBy('vehicle_id');
                $weights = $byVehicle->map(fn ($l) => (int) $l->sum('orders_count'));
                if ((int) $weights->sum() === 0) {
                    $weights = $byVehicle->map(fn ($l) => $l->count());
                }
                $total = max(1, (int) $weights->sum());
                foreach ($weights as $vehicleId => $weight) {
                    if (! $vehicleId) {
                        continue;
                    }
                    $driverCostByVehicle[$vehicleId] = ($driverCostByVehicle[$vehicleId] ?? 0.0) + $gross * ($weight / $total);
                }
            }
        }

        $vehicles = Vehicle::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereIn('status', ['working', 'available', 'maintenance'])
            ->get(['id', 'plate_number', 'make', 'model', 'status']);

        $rows = $vehicles->map(function ($v) use ($context, $revenueByVehicle, $driverCostByVehicle) {
            $revenue = round($revenueByVehicle[$v->id] ?? 0.0, 3);
            $driverCost = round($driverCostByVehicle[$v->id] ?? 0.0, 3);
            $maintenance = round($context['maintenance_by_vehicle'][$v->id] ?? 0.0, 3);
            $violations = round($context['violations_by_vehicle'][$v->id] ?? 0.0, 3);

            return [
                'vehicle_id' => $v->id,
                'plate_number' => $v->plate_number,
                'label' => trim("{$v->make} {$v->model}"),
                'status' => $v->status,
                'total_orders' => (int) ($context['orders_by_vehicle'][$v->id] ?? 0),
                'revenue' => $revenue,
                'driver_cost' => $driverCost,
                'total_maintenance' => $maintenance,
                'total_violations' => $violations,
                'net_profit' => round($revenue - $driverCost - $maintenance - $violations, 3),
            ];
        })->sortByDesc('net_profit')->values();

        return [
            'vehicles' => $rows->all(),
            'totals' => [
                'revenue' => round($rows->sum('revenue'), 3),
                'driver_cost' => round($rows->sum('driver_cost'), 3),
                'total_maintenance' => round($rows->sum('total_maintenance'), 3),
                'total_violations' => round($rows->sum('total_violations'), 3),
                'net_profit' => round($rows->sum('net_profit'), 3),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function contractRow(Contract $contract, array $context, bool $withSheet): array
    {
        $logs = $context['logs_by_contract'][$contract->id] ?? collect();

        $billed = $logs->isEmpty()
            ? ['revenue' => 0.0, 'orders' => 0, 'unpriced_orders' => 0, 'details' => []]
            : ContractRevenueService::forContractMonth($contract, $logs);

        $sheet = ContractSheetService::build($contract, $context['year'], $context['month'], $context['company_id']);
        $salaries = 0.0;
        $gross = 0.0;
        foreach ($sheet['drivers'] ?? [] as $driver) {
            $salaries += (float) ($driver['base_salary'] ?? 0);
            $gross += (float) ($driver['gross_contract_earnings'] ?? 0);
        }

        $costs = $context['costs_by_contract'][$contract->id] ?? [];
        $fuel = round((float) ($costs['fuel'] ?? 0), 3);
        $expenses = round((float) ($costs['expenses'] ?? 0), 3);
        $maintenance = round((float) ($costs['maintenance'] ?? 0), 3);
        $violations = round((float) ($costs['violations'] ?? 0), 3);

        $supervisors = $context['supervisors_by_contract'][$contract->id] ?? [];
        $supervisorsCost = round(array_sum(array_column($supervisors, 'allocated_amount')), 3);

        $driverCost = round($gross, 3);
        $direct = round($driverCost + $fuel + $expenses + $maintenance + $violations, 3);
        $indirect = $supervisorsCost;
        $revenue = round((float) $billed['revenue'], 3);
        $profit = round($revenue - $direct - $indirect, 3);

        $row = [
            'contract_id' => $contract->id,
            'contract_name' => $contract->name,
            'contract_number' => $contract->contract_number,
            'client_name' => $contract->client?->name ?? $contract->client_name ?? '—',
            'payment_type' => $contract->payment_type,
            'is_active' => (bool) $contract->is_active,
            'status' => $contract->status,
            'orders' => (int) $billed['orders'],
            'revenue' => $revenue,
            'unpriced_orders' => (int) $billed['unpriced_orders'],
            'revenue_details' => $billed['details'],
            'driver_salaries' => round($salaries, 3),
            'driver_commissions' => round($gross - $salaries, 3),
            'driver_cost' => $driverCost,
            'fuel_allowance' => $fuel,
            'vehicle_expenses' => $expenses,
            'vehicle_costs' => round($fuel + $expenses, 3),
            'maintenance_cost' => $maintenance,
            'accidents_count' => (int) ($costs['accidents_count'] ?? 0),
            'violations_cost' => $violations,
            'supervisors' => array_values($supervisors),
            'supervisors_cost' => $supervisorsCost,
            'direct_expenses' => $direct,
            'indirect_expenses' => $indirect,
            'expenses' => round($direct + $indirect, 3),
            'profit' => $profit,
            'margin' => $revenue > 0 ? round($profit / $revenue * 100, 2) : 0.0,
            'sheet_is_approved' => (bool) ($sheet['is_approved'] ?? false),
        ];

        if ($withSheet) {
            $row['sheet'] = $sheet;
        }

        return $row;
    }

    /**
     * Everything the month's costs are built from, gathered once for the whole company.
     *
     * @return array<string, mixed>
     */
    private static function monthContext(int $companyId, int $year, int $month): array
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = Carbon::parse($startDate)->endOfMonth()->toDateString();

        $logs = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereBetween('log_date', [$startDate, $endDate])
            ->with('vehicle:id,vehicle_type_id')
            ->get(['id', 'employee_id', 'vehicle_id', 'contract_id', 'log_date', 'orders_count', 'zone', 'notes']);

        $dateOf = fn ($log) => substr((string) $log->log_date, 0, 10);

        // vehicle => date => contract => orders, and driver => date => contracts: who worked where
        // on a given day, which is what every allocation below asks.
        $vehicleDays = [];
        $driverDays = [];
        $vehicleContractOrders = [];
        $vehicleContractDays = [];
        $ordersByVehicle = [];
        foreach ($logs as $log) {
            $date = $dateOf($log);
            $orders = (int) $log->orders_count;
            if ($log->vehicle_id && $log->contract_id) {
                $vehicleDays[$log->vehicle_id][$date][$log->contract_id] = ($vehicleDays[$log->vehicle_id][$date][$log->contract_id] ?? 0) + $orders;
                $vehicleContractOrders[$log->vehicle_id][$log->contract_id] = ($vehicleContractOrders[$log->vehicle_id][$log->contract_id] ?? 0) + $orders;
                $vehicleContractDays[$log->vehicle_id][$log->contract_id] = ($vehicleContractDays[$log->vehicle_id][$log->contract_id] ?? 0) + 1;
            }
            if ($log->employee_id && $log->contract_id) {
                $driverDays[$log->employee_id][$date][$log->contract_id] = true;
            }
            if ($log->vehicle_id) {
                $ordersByVehicle[$log->vehicle_id] = ($ordersByVehicle[$log->vehicle_id] ?? 0) + $orders;
            }
        }

        $vehicleIds = array_keys($vehicleDays);

        $assignments = VehicleAssignment::withoutGlobalScopes()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereDate('assigned_date', '<=', $endDate)
            ->where(fn ($q) => $q->whereNull('unassigned_date')->orWhereDate('unassigned_date', '>=', $startDate))
            ->get(['vehicle_id', 'contract_id', 'assigned_date', 'unassigned_date'])
            ->groupBy('vehicle_id');

        /**
         * The contract(s) a cost on this vehicle, on this date, belongs to — as fractions.
         *
         * @return array<int, float>
         */
        $allocate = function (?int $vehicleId, string $date, ?int $employeeId = null) use ($vehicleDays, $driverDays, $assignments): array {
            $onDay = $vehicleId ? ($vehicleDays[$vehicleId][$date] ?? []) : [];
            if ($onDay !== []) {
                $share = 1 / count($onDay);

                return array_fill_keys(array_keys($onDay), $share);
            }

            if ($vehicleId) {
                $assigned = ($assignments[$vehicleId] ?? collect())->first(function ($a) use ($date) {
                    $from = substr((string) $a->assigned_date, 0, 10);
                    $to = $a->unassigned_date ? substr((string) $a->unassigned_date, 0, 10) : null;

                    return $from <= $date && (! $to || $to >= $date) && $a->contract_id;
                });
                if ($assigned) {
                    return [(int) $assigned->contract_id => 1.0];
                }
            }

            $driverContracts = $employeeId ? array_keys($driverDays[$employeeId][$date] ?? []) : [];
            if (count($driverContracts) === 1) {
                return [(int) $driverContracts[0] => 1.0];
            }

            return [];
        };

        $costs = [];
        $add = function (array $shares, string $bucket, float $amount) use (&$costs) {
            foreach ($shares as $contractId => $fraction) {
                $costs[$contractId][$bucket] = ($costs[$contractId][$bucket] ?? 0.0) + $amount * $fraction;
            }
        };

        // Fuel: the vehicle's monthly allowance, shared by its orders (or its days, when it ran
        // with no orders) across the contracts it worked.
        $vehicles = Vehicle::withoutGlobalScopes()->whereIn('id', $vehicleIds)->get(['id', 'monthly_fuel_allowance']);
        foreach ($vehicles as $vehicle) {
            $allowance = (float) ($vehicle->monthly_fuel_allowance ?? 0);
            if ($allowance <= 0) {
                continue;
            }
            $weights = $vehicleContractOrders[$vehicle->id] ?? [];
            if (array_sum($weights) === 0) {
                $weights = $vehicleContractDays[$vehicle->id] ?? [];
            }
            $total = array_sum($weights);
            if ($total <= 0) {
                continue;
            }
            $add(array_map(fn ($w) => $w / $total, $weights), 'fuel', $allowance);
        }

        // Vehicle expenses recorded in the month.
        $expenses = VehicleExpense::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->get(['vehicle_id', 'amount', 'expense_date']);
        foreach ($expenses as $expense) {
            $add($allocate((int) $expense->vehicle_id, substr((string) $expense->expense_date, 0, 10)), 'expenses', (float) $expense->amount);
        }

        // Repairs and accidents the company paid for: the approved cost less what the driver bears.
        $maintenanceByVehicle = [];
        $records = MaintenanceRecord::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereBetween('maintenance_date', [$startDate, $endDate])
            ->whereIn('status', ['approved', 'completed'])
            ->get(['vehicle_id', 'liable_employee_id', 'maintenance_type', 'maintenance_date', 'actual_cost', 'estimated_cost', 'is_driver_liable', 'driver_deduction']);
        foreach ($records as $record) {
            $cost = (float) ($record->actual_cost ?? $record->estimated_cost ?? 0);
            $driverShare = $record->is_driver_liable ? (float) ($record->driver_deduction ?? 0) : 0.0;
            $companyShare = max(0.0, $cost - $driverShare);
            $date = substr((string) $record->maintenance_date, 0, 10);
            $shares = $allocate((int) $record->vehicle_id, $date, $record->liable_employee_id ? (int) $record->liable_employee_id : null);
            $add($shares, 'maintenance', $companyShare);
            if ($record->maintenance_type === 'accident') {
                foreach ($shares as $contractId => $fraction) {
                    $costs[$contractId]['accidents_count'] = ($costs[$contractId]['accidents_count'] ?? 0) + 1;
                }
            }
            $maintenanceByVehicle[$record->vehicle_id] = ($maintenanceByVehicle[$record->vehicle_id] ?? 0.0) + $companyShare;
        }

        // Fines: the company's share, on the contract the fine names — or the vehicle's day.
        $violationsByVehicle = [];
        $fines = Violation::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereBetween('violation_date', [$startDate, $endDate])
            ->get(['vehicle_id', 'employee_id', 'violation_date', 'amount', 'driver_share', 'contract_share', 'driver_deduction', 'charge_contract_id']);
        foreach ($fines as $fine) {
            $amount = (float) $fine->amount;
            $companyShare = $fine->contract_share !== null
                ? (float) $fine->contract_share
                : max(0.0, $amount - (float) ($fine->driver_share ?? $fine->driver_deduction ?? 0));
            $date = substr((string) $fine->violation_date, 0, 10);
            $shares = $fine->charge_contract_id
                ? [(int) $fine->charge_contract_id => 1.0]
                : $allocate($fine->vehicle_id ? (int) $fine->vehicle_id : null, $date, $fine->employee_id ? (int) $fine->employee_id : null);
            $add($shares, 'violations', $companyShare);
            if ($fine->vehicle_id) {
                $violationsByVehicle[$fine->vehicle_id] = ($violationsByVehicle[$fine->vehicle_id] ?? 0.0) + $companyShare;
            }
        }

        // Supervisors: the allocation in force for the month — the latest row on or before its end,
        // per supervisor per contract. Summing every row the contract ever had charged a
        // supervisor once per month he was ever allocated.
        $supervisorsByContract = [];
        $allocations = SupervisorCostAllocation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('effective_date', '<=', $endDate)
            ->where('allocation_percentage', '>', 0)
            ->with('employee:id,name,actual_salary')
            ->orderByDesc('effective_date')
            ->get();
        $seen = [];
        foreach ($allocations as $allocation) {
            $key = $allocation->employee_id.'|'.$allocation->contract_id;
            if (isset($seen[$key]) || ! $allocation->employee) {
                continue;
            }
            $seen[$key] = true;
            $salary = (float) ($allocation->employee->actual_salary ?? 0);
            $percent = (float) $allocation->allocation_percentage;
            $supervisorsByContract[$allocation->contract_id][] = [
                'id' => $allocation->employee->id,
                'name' => $allocation->employee->name,
                'salary' => $salary,
                'percentage' => $percent,
                'allocated_amount' => round($salary * $percent / 100, 3),
            ];
        }

        return [
            'company_id' => $companyId,
            'year' => $year,
            'month' => $month,
            'logs_by_contract' => $logs->groupBy('contract_id')->all(),
            'orders_by_vehicle' => $ordersByVehicle,
            'costs_by_contract' => $costs,
            'maintenance_by_vehicle' => $maintenanceByVehicle,
            'violations_by_vehicle' => $violationsByVehicle,
            'supervisors_by_contract' => $supervisorsByContract,
        ];
    }
}
