<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\KeetaInvoice;
use App\Models\KeetaLevelSnapshot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Brings Keeta's two exports onto a contract, in two steps like every import here: the file is read
 * and shown (who is who, what the month comes to, what does not add up), then saved as shown.
 *
 * A rider is our driver when his Keeta courier id is on that driver's assignment to the contract —
 * the id the daily Keeta import already matches on — or, failing that, on any of the driver's
 * assignments in the company. A rider no driver carries is kept and listed, never guessed.
 *
 * Saving a statement for a month replaces the one saved before it, and makes the contract read the
 * Keeta way from that month on (`keeta_settlement_from` moves back to it when it is earlier).
 */
class KeetaImportService
{
    private const CACHE_MINUTES = 30;

    /**
     * @return array<string, mixed>
     */
    public static function previewStatement(Contract $contract, UploadedFile $file): array
    {
        @set_time_limit(180);
        @ini_set('memory_limit', '768M');

        $parsed = KeetaWorkbookReader::readStatement($file->getRealPath());
        // Kept as uploaded, so the month's revenue can always be traced back to Keeta's own file.
        $stored = $file->storeAs('keeta/'.$contract->id, now()->format('Ymd_His').'_'.Str::random(6).'.'.($file->getClientOriginalExtension() ?: 'xlsx'), 'local');

        $couriers = self::courierMap($contract);
        $ours = self::ourOrders($contract, $parsed['year'], $parsed['month']);
        $names = self::employeeNames(array_merge(array_values($couriers), array_keys($ours)));

        $riders = [];
        foreach ($parsed['riders'] as $rider) {
            $empId = $couriers[$rider['courier_id']] ?? null;
            $riders[] = $rider + [
                'employee_id' => $empId,
                'employee_name' => $empId ? ($names[$empId] ?? "#{$empId}") : null,
                'orders_ours' => $empId ? ($ours[$empId] ?? 0) : null,
                'revenue' => round($rider['total_payable'] - $rider['tips'], 3),
            ];
        }
        foreach ($parsed['lines'] as $i => $line) {
            $parsed['lines'][$i]['employee_id'] = $line['courier_id'] ? ($couriers[$line['courier_id']] ?? null) : null;
        }

        $partner = $parsed['partner'];
        $riderTotal = round(array_sum(array_column($parsed['riders'], 'total_payable')), 3);
        $riderOrders = array_sum(array_column($parsed['riders'], 'orders'));
        $matchedIds = array_filter(array_column($riders, 'employee_id'));
        $loggedNotOnStatement = array_values(array_diff(array_keys($ours), $matchedIds));

        $checks = [
            [
                'ok' => abs($riderTotal - $partner['total_payable']) < 0.01,
                'text' => 'مجموع صفوف السائقين '.number_format($riderTotal, 3).' مقابل إجمالي الكشف '.number_format($partner['total_payable'], 3),
            ],
            [
                'ok' => abs(round($partner['total_payable'] - $partner['tips'], 3) - $partner['invoice_amount']) < 0.01,
                'text' => 'مبلغ الفاتورة '.number_format($partner['invoice_amount'], 3).' = الإجمالي ناقص البقشيش '.number_format($partner['tips'], 3),
            ],
            [
                'ok' => count($matchedIds) === count($riders),
                'text' => count($matchedIds).' من '.count($riders).' سائقاً مربوطون برقمهم في كيتا',
            ],
            [
                'ok' => $loggedNotOnStatement === [],
                'text' => $loggedNotOnStatement === []
                    ? 'كل سائق له طلبات في السجل اليومي موجود في الكشف'
                    : count($loggedNotOnStatement).' سائقاً لهم طلبات في السجل اليومي وليسوا في الكشف: '.implode('، ', array_map(fn ($id) => $names[$id] ?? "#{$id}", $loggedNotOnStatement)),
            ],
        ];

        $existing = KeetaInvoice::withoutGlobalScopes()->where('contract_id', $contract->id)
            ->where('year', $parsed['year'])->where('month', $parsed['month'])->first();
        $from = self::settlementFromAfter($contract, $parsed['year'], $parsed['month']);

        $token = (string) Str::uuid();
        Cache::put(self::cacheKey('statement', $token), [
            'contract_id' => $contract->id,
            'parsed' => $parsed,
            'riders' => $riders,
            'file_path' => $stored,
            'original_filename' => $file->getClientOriginalName(),
        ], now()->addMinutes(self::CACHE_MINUTES));

        return [
            'token' => $token,
            'billing_cycle' => $parsed['billing_cycle'],
            'year' => $parsed['year'],
            'month' => $parsed['month'],
            'replaces_existing' => (bool) $existing,
            'settlement_from_after' => $from->format('Y-m'),
            'totals' => [
                'order_pricing' => $partner['order_pricing'],
                'experience_incentive' => $partner['experience_incentive'],
                'capacity_incentive' => $partner['capacity_incentive'],
                'other_income' => $partner['other_income'],
                'tips' => $partner['tips'],
                'deduction' => $partner['deduction'],
                'food_compensation' => $partner['food_compensation'],
                'other_adjustment' => $partner['other_adjustment'],
                'withholding' => $partner['withholding'],
                'invoice_amount' => $partner['invoice_amount'],
                'total_payable' => $partner['total_payable'],
                'riders' => count($riders),
                'valid_riders' => count(array_filter($riders, fn ($r) => $r['is_valid'])),
                'orders_keeta' => $riderOrders,
                'orders_ours' => array_sum($ours),
                'adjustment_lines' => count($parsed['lines']),
            ],
            'checks' => $checks,
            'riders' => $riders,
            'lines' => $parsed['lines'],
        ];
    }

    /**
     * @return array{invoice: KeetaInvoice, settlement_from: string}
     */
    public static function confirmStatement(Contract $contract, string $token, ?User $user): array
    {
        $payload = Cache::get(self::cacheKey('statement', $token));
        if (! $payload || (int) $payload['contract_id'] !== (int) $contract->id) {
            throw ValidationException::withMessages(['token' => 'انتهت صلاحية المعاينة أو تخص عقداً آخر. ارفع الملف من جديد.']);
        }
        $parsed = $payload['parsed'];
        $partner = $parsed['partner'];

        $invoice = DB::transaction(function () use ($contract, $parsed, $partner, $payload, $user) {
            $replaced = KeetaInvoice::withoutGlobalScopes()->where('contract_id', $contract->id)
                ->where('year', $parsed['year'])->where('month', $parsed['month'])->get();
            // Invalid days the owner set by hand survive the statement being imported again.
            $invalidDaysByCourier = [];
            foreach ($replaced as $old) {
                foreach ($old->riders()->whereNotNull('invalid_days_override')->get(['courier_id', 'invalid_days_override']) as $kept) {
                    $invalidDaysByCourier[(string) $kept->courier_id] = $kept->invalid_days_override;
                }
            }
            foreach ($replaced as $old) {
                $old->riders()->delete();
                $old->lines()->delete();
                $old->delete();
            }

            $invoice = KeetaInvoice::withoutGlobalScopes()->create([
                'company_id' => $contract->company_id,
                'contract_id' => $contract->id,
                'year' => $parsed['year'],
                'month' => $parsed['month'],
                'billing_cycle' => $parsed['billing_cycle'],
                'partner_id' => $partner['partner_id'],
                'partner_name' => $partner['partner_name'],
                'order_pricing' => $partner['order_pricing'],
                'experience_incentive' => $partner['experience_incentive'],
                'capacity_incentive' => $partner['capacity_incentive'],
                'other_income' => $partner['other_income'],
                'tips' => $partner['tips'],
                'deduction' => $partner['deduction'],
                'food_compensation' => $partner['food_compensation'],
                'other_adjustment' => $partner['other_adjustment'],
                'withholding' => $partner['withholding'],
                'invoice_amount' => $partner['invoice_amount'],
                'total_payable' => $partner['total_payable'],
                'riders_count' => count($payload['riders']),
                'valid_riders' => count(array_filter($payload['riders'], fn ($r) => $r['is_valid'])),
                'orders_count' => array_sum(array_column($payload['riders'], 'orders')),
                'original_filename' => $payload['original_filename'],
                'file_path' => $payload['file_path'],
                'imported_by' => $user?->id,
            ]);

            foreach ($payload['riders'] as $rider) {
                $invoice->riders()->create([
                    'courier_id' => $rider['courier_id'],
                    'employee_id' => $rider['employee_id'],
                    'name' => $rider['name'],
                    'phone' => $rider['phone'],
                    'is_valid' => $rider['is_valid'],
                    'reason' => $rider['reason'],
                    'valid_days' => $rider['valid_days'],
                    'invalid_days_override' => $invalidDaysByCourier[(string) $rider['courier_id']] ?? null,
                    'daily_hours' => $rider['daily_hours'],
                    'peak_hours' => $rider['peak_hours'],
                    'orders' => $rider['orders'],
                    'order_pricing' => $rider['order_pricing'],
                    'experience_incentive' => $rider['experience_incentive'],
                    'capacity_incentive' => $rider['capacity_incentive'],
                    'other_income' => $rider['other_income'],
                    'tips' => $rider['tips'],
                    'deduction' => $rider['deduction'],
                    'food_compensation' => $rider['food_compensation'],
                    'other_adjustment' => $rider['other_adjustment'],
                    'withholding' => $rider['withholding'],
                    'total_payable' => $rider['total_payable'],
                ]);
            }

            foreach ($parsed['lines'] as $line) {
                $invoice->lines()->create([
                    'courier_id' => $line['courier_id'],
                    'employee_id' => $line['employee_id'] ?? null,
                    'transaction_type' => Str::limit((string) $line['transaction_type'], 97, '...') ?: null,
                    'label' => $line['label'] !== null ? Str::limit($line['label'], 250, '...') : null,
                    'amount' => $line['amount'],
                    'note' => $line['note'] !== null ? Str::limit($line['note'], 250, '...') : null,
                    'ticket_id' => $line['ticket_id'],
                    'violation_id' => $line['violation_id'],
                    'violation_type' => $line['violation_type'] !== null ? Str::limit($line['violation_type'], 250, '...') : null,
                    'punishment' => $line['punishment'] !== null ? Str::limit($line['punishment'], 250, '...') : null,
                ]);
            }

            self::moveSettlementStart($contract, $parsed['year'], $parsed['month']);

            return $invoice;
        });

        Cache::forget(self::cacheKey('statement', $token));
        KeetaRevenueService::forget();

        return ['invoice' => $invoice, 'settlement_from' => Carbon::parse($contract->fresh()->keeta_settlement_from)->format('Y-m')];
    }

    /**
     * @return array<string, mixed>
     */
    public static function previewLevels(Contract $contract, UploadedFile $file, int $year, int $month, string $takenOn): array
    {
        $rows = KeetaWorkbookReader::readLevels($file->getRealPath());
        $couriers = self::courierMap($contract);
        $names = self::employeeNames(array_values($couriers));

        $counts = ['S' => 0, 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'none' => 0];
        $rewardMatched = 0.0;
        $rewardUnmatched = 0.0;
        foreach ($rows as $i => $row) {
            $empId = $couriers[$row['courier_id']] ?? null;
            $rows[$i]['employee_id'] = $empId;
            $rows[$i]['employee_name'] = $empId ? ($names[$empId] ?? "#{$empId}") : null;
            $counts[$row['level'] ?? 'none']++;
            if ($row['level'] !== null) {
                $empId ? $rewardMatched += $row['reward'] : $rewardUnmatched += $row['reward'];
            }
        }

        $token = (string) Str::uuid();
        Cache::put(self::cacheKey('levels', $token), [
            'contract_id' => $contract->id,
            'year' => $year,
            'month' => $month,
            'taken_on' => $takenOn,
            'rows' => $rows,
            'original_filename' => $file->getClientOriginalName(),
        ], now()->addMinutes(self::CACHE_MINUTES));

        return [
            'token' => $token,
            'year' => $year,
            'month' => $month,
            'taken_on' => $takenOn,
            'counts' => $counts,
            'riders' => count($rows),
            'matched' => count(array_filter($rows, fn ($r) => $r['employee_id'])),
            'reward_matched' => round($rewardMatched, 3),
            'reward_unmatched' => round($rewardUnmatched, 3),
            'settlement_from_after' => self::settlementFromAfter($contract, $year, $month)->format('Y-m'),
            'rows' => $rows,
        ];
    }

    public static function confirmLevels(Contract $contract, string $token, ?User $user): KeetaLevelSnapshot
    {
        $payload = Cache::get(self::cacheKey('levels', $token));
        if (! $payload || (int) $payload['contract_id'] !== (int) $contract->id) {
            throw ValidationException::withMessages(['token' => 'انتهت صلاحية المعاينة أو تخص عقداً آخر. ارفع الملف من جديد.']);
        }

        $snapshot = DB::transaction(function () use ($contract, $payload, $user) {
            $snapshot = KeetaLevelSnapshot::withoutGlobalScopes()->create([
                'company_id' => $contract->company_id,
                'contract_id' => $contract->id,
                'year' => $payload['year'],
                'month' => $payload['month'],
                'taken_on' => $payload['taken_on'],
                'original_filename' => $payload['original_filename'],
                'imported_by' => $user?->id,
            ]);
            foreach ($payload['rows'] as $row) {
                $snapshot->rows()->create([
                    'courier_id' => $row['courier_id'],
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'level' => $row['level'],
                    'reward' => $row['reward'],
                    'ontime_rate' => $row['ontime_rate'],
                    'completion_rate' => $row['completion_rate'],
                    'utr' => $row['utr'],
                    'orders' => $row['orders'],
                    'acceptance_rate' => $row['acceptance_rate'],
                ]);
            }
            self::moveSettlementStart($contract, $payload['year'], $payload['month']);

            return $snapshot;
        });

        Cache::forget(self::cacheKey('levels', $token));
        KeetaRevenueService::forget();

        return $snapshot;
    }

    /**
     * Keeta courier id → our driver: the id on his assignment to this contract first, then on any
     * other assignment of his in the company.
     *
     * @return array<string, int>
     */
    public static function courierMap(Contract $contract): array
    {
        $rows = ContractAssignment::withoutGlobalScopes()
            ->join('employees', 'employees.id', '=', 'contract_assignments.employee_id')
            ->where('employees.company_id', $contract->company_id)
            ->whereNotNull('contract_assignments.courier_id')->where('contract_assignments.courier_id', '<>', '')
            ->orderByRaw('CASE WHEN contract_assignments.contract_id = ? THEN 0 ELSE 1 END', [$contract->id])
            ->get(['contract_assignments.courier_id', 'contract_assignments.employee_id']);

        $map = [];
        foreach ($rows as $row) {
            $map[trim((string) $row->courier_id)] ??= (int) $row->employee_id;
        }

        return $map;
    }

    /** @return array<int, int> orders per driver logged on the contract in the month */
    private static function ourOrders(Contract $contract, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1);

        return DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('contract_id', $contract->id)
            ->whereBetween('log_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->where('orders_count', '>', 0)
            ->selectRaw('employee_id, SUM(orders_count) AS orders')
            ->groupBy('employee_id')
            ->pluck('orders', 'employee_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * @param  list<int|string|null>  $ids
     * @return array<int, string>
     */
    private static function employeeNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : Employee::withoutGlobalScopes()->withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private static function settlementFromAfter(Contract $contract, int $year, int $month): Carbon
    {
        $candidate = Carbon::create($year, $month, 1)->startOfDay();
        $current = $contract->keeta_settlement_from ? Carbon::parse($contract->keeta_settlement_from)->startOfMonth() : null;

        return $current && $current->lt($candidate) ? $current : $candidate;
    }

    private static function moveSettlementStart(Contract $contract, int $year, int $month): void
    {
        $from = self::settlementFromAfter($contract, $year, $month);
        $contract->forceFill(['keeta_settlement_from' => $from->toDateString()])->saveQuietly();
    }

    private static function cacheKey(string $kind, string $token): string
    {
        return "keeta-import:{$kind}:{$token}";
    }
}
