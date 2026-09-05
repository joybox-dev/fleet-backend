<?php

namespace App\Services;

use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\Violation;

/**
 * Money spent, in one list.
 *
 * The three kinds of spending lived in three tables behind three screens, so answering "what did
 * this month cost and who bore it" meant opening all three and adding up by hand. Each record
 * already answers both halves — an amount, and a split between the company and the driver — they
 * were simply never read together.
 *
 * Deliberately only these three. A salary advance is a loan with a repayment schedule, a
 * maintenance record is a work order with an approval step, and custody is an item handed over that
 * only sometimes becomes a charge. None of them is an expense, and folding them in here would have
 * meant a form that hid half its fields behind a type selector.
 *
 * Read-only. Nothing here writes, and each row carries the screen it came from so a reader can open
 * the original record where it has always lived.
 */
class ExpenseLedgerService
{
    public const KINDS = [
        'driver_expense' => 'مصروف على السائق',
        'vehicle_expense' => 'مصروف مركبة',
        'violation' => 'مخالفة مرورية',
    ];

    /**
     * @param  array<string, mixed>  $filters  from, to, kind, employee_id, vehicle_id, borne_by, search
     * @return array<string, mixed>
     */
    public static function build(int $companyId, array $filters = []): array
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $rows = array_merge(
            self::driverExpenses($companyId, $from, $to),
            self::vehicleExpenses($companyId, $from, $to),
            self::violations($companyId, $from, $to),
        );

        $rows = self::name($rows, $companyId);
        $rows = self::apply($rows, $filters);

        usort($rows, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

        return [
            'kinds' => self::KINDS,
            'rows' => array_values($rows),
            'totals' => self::totals($rows),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function totals(array $rows): array
    {
        $byKind = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $byKind[$kind] = ['count' => 0, 'amount' => 0.0, 'company' => 0.0, 'driver' => 0.0];
        }

        $total = ['count' => count($rows), 'amount' => 0.0, 'company' => 0.0, 'driver' => 0.0];

        foreach ($rows as $r) {
            $k = $r['kind'];
            $byKind[$k]['count']++;
            foreach (['amount', 'company', 'driver'] as $f) {
                $byKind[$k][$f] = round($byKind[$k][$f] + $r[$f], 3);
                $total[$f] = round($total[$f] + $r[$f], 3);
            }
        }

        return ['all' => $total, 'by_kind' => $byKind];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private static function apply(array $rows, array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return array_values(array_filter($rows, function ($r) use ($filters, $search) {
            if (! empty($filters['kind']) && $r['kind'] !== $filters['kind']) {
                return false;
            }
            if (! empty($filters['employee_id']) && (int) $r['employee_id'] !== (int) $filters['employee_id']) {
                return false;
            }
            if (! empty($filters['vehicle_id']) && (int) $r['vehicle_id'] !== (int) $filters['vehicle_id']) {
                return false;
            }
            if (! empty($filters['borne_by']) && $r['borne_by'] !== $filters['borne_by']) {
                return false;
            }
            if ($search !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    $r['label'], $r['employee_name'] ?? '', $r['employee_number'] ?? '',
                    $r['plate_number'] ?? '', $r['reference'] ?? '',
                ]));
                if (! str_contains($haystack, mb_strtolower($search))) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Names are resolved once for the whole list rather than per row.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function name(array $rows, int $companyId): array
    {
        $employees = Employee::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->get(['id', 'name', 'employee_number'])->keyBy('id');

        $vehicles = Vehicle::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->get(['id', 'plate_number'])->keyBy('id');

        foreach ($rows as &$r) {
            $e = $r['employee_id'] ? $employees->get($r['employee_id']) : null;
            $v = $r['vehicle_id'] ? $vehicles->get($r['vehicle_id']) : null;
            $r['employee_name'] = $e?->name;
            $r['employee_number'] = $e?->employee_number;
            $r['plate_number'] = $v?->plate_number;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(
        string $kind, int $id, ?string $date, string $label,
        float $amount, float $driver, ?int $employeeId, ?int $vehicleId,
        string $status, string $screen, ?string $reference = null
    ): array {
        $company = round(max(0.0, $amount - $driver), 3);

        return [
            'kind' => $kind,
            'kind_label' => self::KINDS[$kind],
            // Three tables number their rows independently, so the row needs an identity of its own
            // before anything can list them together.
            'id' => $kind.':'.$id,
            'source_id' => $id,
            'date' => $date ? substr($date, 0, 10) : null,
            'label' => $label,
            'amount' => round($amount, 3),
            'driver' => round($driver, 3),
            'company' => $company,
            'borne_by' => $driver <= 0 ? 'company' : ($company <= 0 ? 'driver' : 'split'),
            'employee_id' => $employeeId,
            'vehicle_id' => $vehicleId,
            'status' => $status,
            'screen' => $screen,
            'reference' => $reference,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function violations(int $companyId, ?string $from, ?string $to): array
    {
        return Violation::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->when($from, fn ($q) => $q->whereDate('violation_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('violation_date', '<=', $to))
            ->get()
            ->map(fn ($v) => self::row(
                'violation', (int) $v->id, (string) $v->violation_date,
                $v->violation_type ?: 'مخالفة',
                (float) $v->amount, (float) $v->driver_deduction,
                $v->employee_id, $v->vehicle_id,
                $v->is_deducted ? 'collected' : 'pending',
                '/violations', $v->reference_number
            ))->all();
    }

    /** @return array<int, array<string, mixed>> */
    private static function driverExpenses(int $companyId, ?string $from, ?string $to): array
    {
        return DriverExpense::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
            ->get()
            ->map(fn ($e) => self::row(
                'driver_expense', (int) $e->id, (string) $e->expense_date,
                $e->expense_type ?: 'مصروف',
                (float) $e->amount, (float) $e->driver_amount,
                $e->employee_id, $e->vehicle_id,
                $e->is_deducted ? 'collected' : 'pending',
                '/driver-expenses', $e->vendor
            ))->all();
    }

    /** @return array<int, array<string, mixed>> */
    private static function vehicleExpenses(int $companyId, ?string $from, ?string $to): array
    {
        return VehicleExpense::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
            ->get()
            // A vehicle expense has no driver at all: the company bears every fils of it.
            ->map(fn ($e) => self::row(
                'vehicle_expense', (int) $e->id, (string) $e->expense_date,
                $e->expense_type ?: 'مصروف مركبة',
                (float) $e->amount, 0.0,
                null, $e->vehicle_id,
                'company', '/vehicle-expenses', $e->vendor
            ))->all();
    }
}
