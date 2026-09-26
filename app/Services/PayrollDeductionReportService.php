<?php

namespace App\Services;

use App\Models\ConsolidatedPayrollDeduction;
use App\Models\CustodyItem;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\MaintenanceRecord;
use App\Models\SalaryAdvance;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\Violation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The consolidated month taken apart so it can be checked by hand.
 *
 * The owner reconciles payroll against the screens and the paper: the expenses column against the
 * expenses page and the invoices, the advances column against the advances. The sheet gives each
 * kind as one figure per driver. This gives every line behind those figures, every record of the
 * month with whether it was taken from a driver and where, and the bridge between the two, so a
 * column that does not match its page says why.
 *
 * Read-only, and built on the sheet itself (ConsolidatedSheetService::build), so it cannot
 * disagree with the screen: an approved month reads its frozen snapshot, an open one its projection.
 */
class PayrollDeductionReportService
{
    public const VEHICLE_EXPENSE = 'vehicle_expense';

    public const KIND_LABELS = [
        ConsolidatedPayrollDeduction::SOURCE_VIOLATION => 'مخالفات مرورية',
        ConsolidatedPayrollDeduction::SOURCE_ADVANCE => 'أقساط سلف',
        ConsolidatedPayrollDeduction::SOURCE_CUSTODY => 'عهد',
        ConsolidatedPayrollDeduction::SOURCE_MAINTENANCE => 'صيانة على السائق',
        ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE => 'مصاريف على السائق',
        self::VEHICLE_EXPENSE => 'مصاريف المركبات',
    ];

    /** The sheet's own name for each kind's column. */
    public const SHEET_COLUMNS = [
        ConsolidatedPayrollDeduction::SOURCE_VIOLATION => 'violations',
        ConsolidatedPayrollDeduction::SOURCE_ADVANCE => 'advances',
        ConsolidatedPayrollDeduction::SOURCE_CUSTODY => 'custody',
        ConsolidatedPayrollDeduction::SOURCE_MAINTENANCE => 'maintenance',
        ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE => 'driver_expenses',
    ];

    public const STATUS_THIS_SHEET = 'this_sheet';

    public const STATUS_OTHER_MONTH = 'other_month';

    public const STATUS_COMPANY = 'company';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_PENDING = 'pending';

    /**
     * @return array{period: array<string, mixed>, is_approved: bool, run: ?array<string, mixed>, sheet_lines: array<int, array<string, mixed>>, items: array<int, array<string, mixed>>, advances: array<int, array<string, mixed>>, reconciliation: array<int, array<string, mixed>>, advances_summary: array<string, mixed>}
     */
    public static function forMonth(int $companyId, int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = Carbon::parse($start)->endOfMonth()->toDateString();

        $sheet = ConsolidatedSheetService::build($companyId, $year, $month);
        $applied = (bool) ($sheet['deductions_applied'] ?? false);
        $run = $sheet['consolidated_run'] ?? null;
        $runId = $run['id'] ?? null;
        $approvedAt = ! empty($run['approved_at']) ? Carbon::parse($run['approved_at']) : null;

        [$onSheet, $deferred, $sheetDriverIds, $sheetColumns] = self::readSheet($sheet);
        [$collected, $advanceTaken] = self::ledger($companyId);

        $context = [
            'start' => $start, 'end' => $end, 'applied' => $applied, 'approved_at' => $approvedAt,
            'on_sheet' => $onSheet, 'deferred' => $deferred, 'collected' => $collected, 'sheet_driver_ids' => $sheetDriverIds,
        ];

        $idsOnSheet = fn (string $type): array => collect(array_keys($onSheet))
            ->filter(fn (string $key) => str_starts_with($key, $type.':'))
            ->map(fn (string $key) => (int) substr($key, strlen($type) + 1))
            ->values()->all();

        $records = collect()
            ->merge(self::violations($companyId, $start, $end, $idsOnSheet(ConsolidatedPayrollDeduction::SOURCE_VIOLATION)))
            ->merge(self::driverExpenses($companyId, $start, $end, $idsOnSheet(ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE)))
            ->merge(self::maintenance($companyId, $start, $end, $idsOnSheet(ConsolidatedPayrollDeduction::SOURCE_MAINTENANCE)))
            ->merge(self::custody($companyId, $start, $end, $idsOnSheet(ConsolidatedPayrollDeduction::SOURCE_CUSTODY)))
            ->merge(self::vehicleExpenses($companyId, $start, $end));

        $advances = SalaryAdvance::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->whereBetween('advance_date', [$start, $end])
                ->orWhereIn('id', $idsOnSheet(ConsolidatedPayrollDeduction::SOURCE_ADVANCE)))
            ->orderBy('advance_date')->orderBy('id')
            ->get();

        [$names, $plates] = self::lookups(
            $companyId,
            $records->pluck('employee_id')->merge($advances->pluck('employee_id'))->merge(array_keys($sheetDriverIds))->filter()->unique()->all(),
            $records->pluck('vehicle_id')->filter()->unique()->all()
        );

        $items = $records->map(fn (array $record) => self::item($record, $context, $names, $plates))
            ->sortBy([['date', 'asc'], ['kind', 'asc']])
            ->values();

        $advanceRows = $advances->map(fn (SalaryAdvance $advance) => self::advanceRow(
            $advance, $context, $names, $advanceTaken[$advance->id] ?? [], $runId
        ))->values();

        return [
            'period' => [
                'year' => $year,
                'month' => $month,
                'start' => $start,
                'end' => $end,
                'label' => sprintf('%02d/%04d', $month, $year),
            ],
            'is_approved' => $applied,
            'run' => $run,
            'sheet_lines' => self::sheetLines($sheet, $items, $advanceRows),
            'items' => $items->all(),
            'advances' => $advanceRows->all(),
            'reconciliation' => self::reconciliation($items, $sheetColumns),
            'advances_summary' => [
                // A cancelled advance was never money out; it is counted apart, as the advances page shows it.
                'issued_count' => $advanceRows->where('in_month', true)->where('cancelled', false)->count(),
                'issued_amount' => round($advanceRows->where('in_month', true)->where('cancelled', false)->sum('amount'), 3),
                'cancelled_count' => $advanceRows->where('in_month', true)->where('cancelled', true)->count(),
                'cancelled_amount' => round($advanceRows->where('in_month', true)->where('cancelled', true)->sum('amount'), 3),
                'instalments_count' => $advanceRows->where('on_sheet_amount', '>', 0)->count(),
                'instalments_amount' => round($advanceRows->sum('on_sheet_amount'), 3),
                'remaining_after' => round($advanceRows->where('on_sheet_amount', '>', 0)->sum('remaining_after'), 3),
                'sheet_column' => round((float) ($sheetColumns[ConsolidatedPayrollDeduction::SOURCE_ADVANCE] ?? 0), 3),
            ],
        ];
    }

    /**
     * What the sheet takes, per source; what the owner held back from it; who is on it; and each
     * column's total exactly as the sheet itself computed it.
     *
     * @param  array<string, mixed>  $sheet
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, ?string>, 2: array<int, true>, 3: array<string, float>}
     */
    private static function readSheet(array $sheet): array
    {
        $onSheet = [];
        $deferred = [];
        $driverIds = [];
        $columns = array_fill_keys(array_keys(self::SHEET_COLUMNS), 0.0);

        foreach ($sheet['drivers'] ?? [] as $driver) {
            $employeeId = (int) $driver['employee_id'];
            $driverIds[$employeeId] = true;

            foreach ($driver['deduction_items'] ?? [] as $item) {
                if (($item['source_id'] ?? null) === null) {
                    continue;
                }
                $onSheet[$item['source_type'].':'.$item['source_id']] = [
                    'employee_id' => $employeeId,
                    'amount' => round((float) $item['amount'], 3),
                    'label' => (string) ($item['label'] ?? ''),
                ];
            }
            foreach ($driver['deferred_items'] ?? [] as $item) {
                $deferred[$item['source_type'].':'.$item['source_id']] = $item['override']['defer_to'] ?? null;
            }
            foreach (self::SHEET_COLUMNS as $type => $column) {
                $columns[$type] = round($columns[$type] + (float) ($driver["pending_{$column}_deduction"] ?? 0), 3);
            }
        }

        return [$onSheet, $deferred, $driverIds, $columns];
    }

    /**
     * Which month's sheet collected each source, and every instalment each advance has paid.
     *
     * @return array{0: array<string, array{run_id: int, label: string}>, 1: array<int, array<int, array{run_id: int, amount: float}>>}
     */
    private static function ledger(int $companyId): array
    {
        $collected = [];
        $advanceTaken = [];

        ConsolidatedPayrollDeduction::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('source_id')
            ->with('run:id,year,month')
            ->get(['id', 'consolidated_run_id', 'source_type', 'source_id', 'amount'])
            ->each(function (ConsolidatedPayrollDeduction $row) use (&$collected, &$advanceTaken) {
                if (! $row->run) {
                    return;
                }
                if ($row->source_type === ConsolidatedPayrollDeduction::SOURCE_ADVANCE) {
                    $advanceTaken[$row->source_id][] = ['run_id' => (int) $row->run->id, 'amount' => (float) $row->amount];

                    return;
                }
                $collected["{$row->source_type}:{$row->source_id}"] = [
                    'run_id' => (int) $row->run->id,
                    'label' => sprintf('%02d/%04d', $row->run->month, $row->run->year),
                ];
            });

        return [$collected, $advanceTaken];
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @param  array<int, int>  $vehicleIds
     * @return array{0: Collection<int, Employee>, 1: Collection<int, string>}
     */
    private static function lookups(int $companyId, array $employeeIds, array $vehicleIds): array
    {
        $names = Employee::withoutGlobalScopes()->withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('id', $employeeIds)
            ->get(['id', 'name', 'employee_number'])
            ->keyBy('id');

        $plates = Vehicle::withoutGlobalScopes()->withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('id', $vehicleIds)
            ->pluck('plate_number', 'id');

        return [$names, $plates];
    }

    /**
     * One record with its answer to the owner's question: was the driver's share taken, and where.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $context
     * @param  Collection<int, Employee>  $names
     * @param  Collection<int, string>  $plates
     * @return array<string, mixed>
     */
    private static function item(array $record, array $context, Collection $names, Collection $plates): array
    {
        $key = $record['kind'].':'.$record['source_id'];
        $sheetItem = $context['on_sheet'][$key] ?? null;
        $driverShare = round((float) $record['driver_share'], 3);
        $employee = $record['employee_id'] ? $names->get($record['employee_id']) : null;

        [$status, $statusLabel] = match (true) {
            $sheetItem !== null => [self::STATUS_THIS_SHEET, $context['applied'] ? 'خُصم في هذا الكشف' : 'يُخصم عند اعتماد هذا الكشف'],
            isset($context['collected'][$key]) => [self::STATUS_OTHER_MONTH, 'خُصم في كشف '.$context['collected'][$key]['label']],
            $driverShare <= 0 => [self::STATUS_COMPANY, 'على الشركة'],
            array_key_exists($key, $context['deferred']) => [self::STATUS_DEFERRED, 'مؤجَّل بقرار'.($context['deferred'][$key] ? ' إلى '.$context['deferred'][$key] : '')],
            default => [self::STATUS_PENDING, self::pendingReason($record, $context)],
        };

        return [
            'kind' => $record['kind'],
            'kind_label' => self::KIND_LABELS[$record['kind']],
            'source_id' => $record['source_id'],
            'date' => $record['date'],
            'in_month' => $record['date'] !== null && $record['date'] >= $context['start'] && $record['date'] <= $context['end'],
            'employee_id' => $record['employee_id'],
            'employee_number' => $employee?->employee_number,
            'employee_name' => $employee?->name,
            'plate_number' => $record['vehicle_id'] ? $plates->get($record['vehicle_id']) : null,
            'description' => $record['description'],
            'reference' => $record['reference'],
            'amount' => round((float) $record['amount'], 3),
            'driver_share' => $driverShare,
            'company_share' => round(max(0.0, (float) $record['amount'] - $driverShare), 3),
            'on_sheet_amount' => $sheetItem ? $sheetItem['amount'] : 0.0,
            'status' => $status,
            'status_label' => $statusLabel,
        ];
    }

    /**
     * Why a driver's share that nobody has taken is still sitting there.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $context
     */
    private static function pendingReason(array $record, array $context): string
    {
        $isFine = $record['kind'] === ConsolidatedPayrollDeduction::SOURCE_VIOLATION;

        if (($record['awaiting_approval'] ?? false) === true) {
            return 'بانتظار اعتماد الصيانة';
        }

        if ($context['applied'] && $context['approved_at'] && $record['created_at']
            && Carbon::parse($record['created_at'])->greaterThan($context['approved_at'])) {
            return $isFine
                ? 'سُجِّلت بعد اعتماد الشهر، وتُخصم يدوياً في شهر مفتوح'
                : 'سُجِّل بعد اعتماد الشهر، ويُخصم في الكشف التالي';
        }

        if ($record['employee_id'] && ! isset($context['sheet_driver_ids'][$record['employee_id']])) {
            return $isFine
                ? 'لم تُخصم: السائق ليس على كشف هذا الشهر، والمخالفة لا تنتقل إلا بخصم يدوي'
                : 'لم يُخصم: السائق ليس على كشف هذا الشهر، ويُخصم في أول كشف يظهر فيه';
        }

        return 'لم يُخصم بعد';
    }

    /**
     * An advance with this month's instalment beside what it was, what it pays and what is left.
     *
     * @param  array<string, mixed>  $context
     * @param  Collection<int, Employee>  $names
     * @param  array<int, array{run_id: int, amount: float}>  $taken
     * @return array<string, mixed>
     */
    private static function advanceRow(SalaryAdvance $advance, array $context, Collection $names, array $taken, ?int $runId): array
    {
        $key = 'advance:'.$advance->id;
        $instalment = round((float) ($context['on_sheet'][$key]['amount'] ?? 0), 3);
        $amount = round((float) $advance->amount, 3);
        $remainingNow = round((float) $advance->remaining_balance, 3);
        $employee = $names->get($advance->employee_id);
        $date = $advance->advance_date ? substr((string) $advance->advance_date, 0, 10) : null;

        // An approved month is read as it stood when it closed: the principal less every instalment
        // taken up to and including its own run, whatever has been taken since.
        $remainingAfter = $context['applied'] && $runId
            ? round(max(0.0, $amount - collect($taken)->filter(fn ($t) => $t['run_id'] <= $runId)->sum('amount')), 3)
            : round(max(0.0, $remainingNow - $instalment), 3);

        [$status, $statusLabel] = match (true) {
            $instalment > 0 => [self::STATUS_THIS_SHEET, $context['applied'] ? 'قسط خُصم في هذا الكشف' : 'قسط يُخصم عند اعتماد هذا الكشف'],
            $advance->status === 'cancelled' => [self::STATUS_COMPANY, 'ملغاة، لا يُخصم منها شيء'],
            array_key_exists($key, $context['deferred']) => [self::STATUS_DEFERRED, 'قسط هذا الشهر مؤجَّل بقرار'],
            $remainingNow <= 0 => [self::STATUS_OTHER_MONTH, 'مسدَّدة بالكامل'],
            ! isset($context['sheet_driver_ids'][$advance->employee_id]) => [self::STATUS_PENDING, 'السائق ليس على كشف هذا الشهر'],
            default => [self::STATUS_PENDING, 'لا قسط في هذا الكشف'],
        };

        return [
            'source_id' => $advance->id,
            'date' => $date,
            'in_month' => $date !== null && $date >= $context['start'] && $date <= $context['end'],
            'employee_id' => $advance->employee_id,
            'employee_number' => $employee?->employee_number,
            'employee_name' => $employee?->name,
            'reason' => $advance->reason,
            'cancelled' => $advance->status === 'cancelled',
            'amount' => $amount,
            'monthly_installment' => round((float) $advance->monthly_installment, 3),
            'total_installments' => (int) $advance->total_installments,
            'paid_installments' => (int) $advance->paid_installments,
            'on_sheet_amount' => $instalment,
            'remaining_now' => $remainingNow,
            'remaining_after' => $remainingAfter,
            'status' => $status,
            'status_label' => $statusLabel,
        ];
    }

    /**
     * Every deduction the sheet takes, in the sheet's own order and amounts, with the record's
     * date, vehicle and reference beside it.
     *
     * @param  array<string, mixed>  $sheet
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  Collection<int, array<string, mixed>>  $advances
     * @return array<int, array<string, mixed>>
     */
    private static function sheetLines(array $sheet, Collection $items, Collection $advances): array
    {
        $byKey = $items->keyBy(fn (array $i) => $i['kind'].':'.$i['source_id']);
        $advanceById = $advances->keyBy('source_id');
        $lines = [];

        foreach ($sheet['drivers'] ?? [] as $driver) {
            foreach ($driver['deduction_items'] ?? [] as $item) {
                $type = $item['source_type'];
                $record = $type === ConsolidatedPayrollDeduction::SOURCE_ADVANCE
                    ? $advanceById->get($item['source_id'])
                    : $byKey->get($type.':'.$item['source_id']);

                $lines[] = [
                    'employee_id' => (int) $driver['employee_id'],
                    'employee_number' => $driver['employee_number'] ?? null,
                    'employee_name' => $driver['employee_name'] ?? null,
                    'kind' => $type,
                    'kind_label' => self::KIND_LABELS[$type] ?? $type,
                    'label' => (string) ($item['label'] ?? ''),
                    'source_id' => $item['source_id'] ?? null,
                    'date' => $record['date'] ?? null,
                    'plate_number' => $record['plate_number'] ?? null,
                    'reference' => $record['reference'] ?? null,
                    'record_amount' => $record['amount'] ?? null,
                    'amount' => round((float) $item['amount'], 3),
                ];
            }
        }

        return $lines;
    }

    /**
     * The bridge between each page and its payroll column: what the month recorded, how much of it
     * fell on the drivers, where that went, and what the sheet brought in from before.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  array<string, float>  $sheetColumns
     * @return array<int, array<string, mixed>>
     */
    private static function reconciliation(Collection $items, array $sheetColumns): array
    {
        $rows = [];
        $kinds = [
            ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE,
            ConsolidatedPayrollDeduction::SOURCE_VIOLATION,
            ConsolidatedPayrollDeduction::SOURCE_MAINTENANCE,
            ConsolidatedPayrollDeduction::SOURCE_CUSTODY,
            self::VEHICLE_EXPENSE,
        ];

        foreach ($kinds as $kind) {
            $ofKind = $items->where('kind', $kind);
            $month = $ofKind->where('in_month', true);
            $driver = fn (Collection $c) => round($c->sum('driver_share'), 3);

            $rows[] = [
                'kind' => $kind,
                'label' => self::KIND_LABELS[$kind],
                'recorded_count' => $month->count(),
                'recorded_amount' => round($month->sum('amount'), 3),
                'company_share' => round($month->sum('company_share'), 3),
                'driver_share' => $driver($month),
                'this_sheet' => round($month->where('status', self::STATUS_THIS_SHEET)->sum('on_sheet_amount'), 3),
                'other_month' => $driver($month->where('status', self::STATUS_OTHER_MONTH)),
                'deferred' => $driver($month->where('status', self::STATUS_DEFERRED)),
                'pending' => $driver($month->where('status', self::STATUS_PENDING)),
                'carried_in' => round($ofKind->where('in_month', false)->sum('on_sheet_amount'), 3),
                'sheet_column' => round($ofKind->sum('on_sheet_amount'), 3),
                'sheet_column_on_sheet' => $kind === self::VEHICLE_EXPENSE ? 0.0 : round((float) ($sheetColumns[$kind] ?? 0), 3),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, int>  $onSheetIds
     * @return Collection<int, array<string, mixed>>
     */
    private static function violations(int $companyId, string $start, string $end, array $onSheetIds): Collection
    {
        return Violation::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->datedWithin($start, $end)->orWhereIn('id', $onSheetIds))
            ->get()
            ->map(fn (Violation $v) => [
                'kind' => ConsolidatedPayrollDeduction::SOURCE_VIOLATION,
                'source_id' => $v->id,
                'date' => substr((string) $v->violation_date, 0, 10),
                'employee_id' => $v->employee_id,
                'vehicle_id' => $v->vehicle_id,
                'description' => $v->violation_type ?: 'مخالفة مرورية',
                'reference' => $v->reference_number,
                'amount' => (float) $v->amount,
                'driver_share' => $v->is_driver_liable && (float) $v->driver_deduction > 0 ? (float) $v->driver_deduction : 0.0,
                'created_at' => $v->created_at?->toDateTimeString(),
            ]);
    }

    /**
     * @param  array<int, int>  $onSheetIds
     * @return Collection<int, array<string, mixed>>
     */
    private static function driverExpenses(int $companyId, string $start, string $end, array $onSheetIds): Collection
    {
        return DriverExpense::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->whereBetween('expense_date', [$start, $end])->orWhereIn('id', $onSheetIds))
            ->get()
            ->map(fn (DriverExpense $e) => [
                'kind' => ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE,
                'source_id' => $e->id,
                'date' => substr((string) $e->getRawOriginal('expense_date'), 0, 10),
                'employee_id' => $e->employee_id,
                'vehicle_id' => $e->vehicle_id,
                'description' => (string) ($e->getRawOriginal('expense_type') ?: 'مصروف'),
                'reference' => $e->vendor,
                'amount' => (float) $e->amount,
                'driver_share' => max(0.0, (float) $e->driver_amount),
                'created_at' => $e->created_at?->toDateTimeString(),
            ]);
    }

    /**
     * Repairs the driver is liable for. Only an approved one is ever taken from him, so a liable
     * repair still waiting for approval is listed as waiting rather than as missed.
     *
     * @param  array<int, int>  $onSheetIds
     * @return Collection<int, array<string, mixed>>
     */
    private static function maintenance(int $companyId, string $start, string $end, array $onSheetIds): Collection
    {
        return MaintenanceRecord::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('status', '!=', 'rejected')
            ->where(fn ($q) => $q
                ->where(fn ($m) => $m->whereBetween('maintenance_date', [$start, $end])
                    ->where('is_driver_liable', true)->where('driver_deduction', '>', 0))
                ->orWhereIn('id', $onSheetIds))
            ->get()
            ->map(fn (MaintenanceRecord $m) => [
                'kind' => ConsolidatedPayrollDeduction::SOURCE_MAINTENANCE,
                'source_id' => $m->id,
                'date' => substr((string) $m->getRawOriginal('maintenance_date'), 0, 10),
                'employee_id' => $m->liable_employee_id,
                'vehicle_id' => $m->vehicle_id,
                'description' => 'صيانة'.($m->maintenance_type ? " ({$m->maintenance_type})" : '').($m->garage_name ? " · {$m->garage_name}" : ''),
                'reference' => null,
                'amount' => (float) $m->actual_cost > 0 ? (float) $m->actual_cost : (float) ($m->estimated_cost ?? 0),
                'driver_share' => max(0.0, (float) $m->driver_deduction),
                'awaiting_approval' => $m->status !== 'approved',
                'created_at' => $m->created_at?->toDateTimeString(),
            ]);
    }

    /**
     * @param  array<int, int>  $onSheetIds
     * @return Collection<int, array<string, mixed>>
     */
    private static function custody(int $companyId, string $start, string $end, array $onSheetIds): Collection
    {
        return CustodyItem::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q
                ->where(fn ($c) => $c->whereBetween('returned_date', [$start, $end])
                    ->where('status', 'returned')
                    ->whereIn('return_condition', ['damaged', 'lost'])
                    ->where('deduction_amount', '>', 0))
                ->orWhereIn('id', $onSheetIds))
            ->get()
            ->map(fn (CustodyItem $c) => [
                'kind' => ConsolidatedPayrollDeduction::SOURCE_CUSTODY,
                'source_id' => $c->id,
                'date' => $c->returned_date ? substr((string) $c->getRawOriginal('returned_date'), 0, 10) : null,
                'employee_id' => $c->employee_id,
                'vehicle_id' => null,
                'description' => 'عهدة '.($c->return_condition === 'lost' ? 'مفقودة' : 'تالفة').($c->item_description ? " ({$c->item_description})" : ''),
                'reference' => $c->serial_number,
                'amount' => (float) $c->deduction_amount,
                'driver_share' => max(0.0, (float) $c->deduction_amount),
                'created_at' => $c->created_at?->toDateTimeString(),
            ]);
    }

    /**
     * The company's own spending on its vehicles. Never a payroll matter, and listed only so the
     * month adds up to what the expenses page shows.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function vehicleExpenses(int $companyId, string $start, string $end): Collection
    {
        return VehicleExpense::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereBetween('expense_date', [$start, $end])
            ->get()
            ->map(fn (VehicleExpense $e) => [
                'kind' => self::VEHICLE_EXPENSE,
                'source_id' => $e->id,
                'date' => substr((string) $e->getRawOriginal('expense_date'), 0, 10),
                'employee_id' => null,
                'vehicle_id' => $e->vehicle_id,
                'description' => VehicleExpense::typeLabel($e->expense_type),
                'reference' => $e->vendor,
                'amount' => (float) $e->amount,
                'driver_share' => 0.0,
                'created_at' => $e->created_at?->toDateTimeString(),
            ]);
    }
}
