<?php

namespace App\Services;

use App\Models\ConsolidatedPayrollRun;
use App\Models\Contract;
use App\Models\ContractPayrollRun;
use App\Models\DailyLog;
use Illuminate\Database\QueryException;

/**
 * The one way a driver's day is written, and the rules every writer has to pass.
 *
 * The guards lived inside DailyLogController, so they held for the screens and for nothing else:
 * the Keeta importer wrote days with `updateOrCreate` beside them — matching on the driver and the
 * date alone, zeroing the cash of a day already handed over, reaching into approved months. The
 * owner's rule on settled cash is only as good as its least-guarded writer, so the writer is here
 * and every path calls it.
 */
class DailyLogWriter
{
    public const STATUSES = ['working', 'absent', 'unexcused_absent', 'paid_leave', 'unpaid_leave', 'sick_leave', 'holiday'];

    /**
     * Why a day in this month may not be written, or null when it may.
     *
     * The consolidated month is the only lock that covers every contract at once, and it is the
     * one that matters: once approved it is frozen and serves a snapshot forever, so a log added
     * afterwards is earnings the driver is never paid for. The contract-level check only sees the
     * one contract named.
     */
    public static function lockedMonth(int $companyId, int $contractId, string $logDate): ?string
    {
        $time = strtotime($logDate);
        $year = (int) date('Y', $time);
        $month = (int) date('n', $time);

        $consolidated = ConsolidatedPayrollRun::where('company_id', $companyId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'approved')
            ->exists();

        if ($consolidated) {
            return 'تم اعتماد كشف الرواتب المجمّع لهذا الشهر ولا يمكن تعديل السجلات اليومية.';
        }

        $contractLocked = ContractPayrollRun::where('company_id', $companyId)
            ->where('contract_id', $contractId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'approved')
            ->exists();

        return $contractLocked
            ? 'تم اعتماد كشف رواتب هذا العقد لهذا الشهر ولا يمكن تعديل السجلات اليومية.'
            : null;
    }

    /**
     * A day with activity on it is a working day whatever the form said; an empty day keeps the
     * status it was given, and is unpaid leave when it was given none.
     *
     * @param  array<string, mixed>  $data
     */
    public static function deriveStatus(array $data): string
    {
        $orders = (int) ($data['orders_count'] ?? 0);
        $rejected = (int) ($data['rejected_orders_count'] ?? 0);
        $cash = (float) ($data['cash_collected'] ?? 0);

        if ($orders > 0 || $rejected > 0 || $cash > 0) {
            return 'working';
        }

        $status = $data['driver_status'] ?? null;

        return $status && in_array($status, self::STATUSES, true) ? $status : 'unpaid_leave';
    }

    /**
     * Why a day's orders may not be written as they stand, or null when they may.
     *
     * Where the client is billed by zone, an order that carries no zone its vehicle type can price
     * is billed at nothing — and nothing said so: the month grid showed an amber figure and saved,
     * the single-day form let the zone stay empty. The question is put to the same reader that
     * prices the month, so what is refused here is exactly what it would have billed at zero.
     *
     * @param  array<string, mixed>  $day  orders_count, zone, notes as they would be saved
     */
    public static function unzonedOrdersBlock(Contract $contract, ?int $vehicleTypeId, array $day): ?string
    {
        $notes = $day['notes'] ?? null;
        $unzoned = ContractRevenueService::ordersWithoutBillableZone(
            $contract,
            $vehicleTypeId,
            (int) ($day['orders_count'] ?? 0),
            isset($day['zone']) && trim((string) $day['zone']) !== '' ? (string) $day['zone'] : null,
            is_array($notes) ? json_encode($notes) : ($notes !== null ? (string) $notes : null)
        );

        return $unzoned > 0
            ? "{$unzoned} طلب في هذا اليوم بلا فئة يعرفها العقد لنوع هذه المركبة — حدِّد الفئة أو وزّع الطلبات على الفئات، وإلا فُوتِرت للعميل بصفر."
            : null;
    }

    /**
     * Cash already handed to the accountant cannot be un-collected. Dropping a day's collection
     * below what was settled on it leaves the books holding money the driver never took in.
     * A day already in that state may still be raised toward its settled figure, so a broken row
     * can be repaired; only making it worse is refused.
     */
    public static function settledCashBlocks(?DailyLog $log, float $cashCollected): ?string
    {
        if (! $log) {
            return null;
        }

        $settled = round((float) $log->cash_settled, 3);
        $current = round((float) $log->cash_collected, 3);
        $next = round($cashCollected, 3);

        if ($settled <= 0 || $next >= $settled || $next >= $current) {
            return null;
        }

        return sprintf(
            'هذا اليوم مُسوّى بمبلغ %s د.ك — لا يمكن تخفيض الكاش المجمّع تحت هذا المبلغ. عدّل التسوية أولاً.',
            number_format($settled, 3)
        );
    }

    /**
     * Write one driver's day on one contract, replacing whatever row that day already has.
     *
     * The day is matched on employee AND contract: matching on employee and date alone reached
     * across to whatever other contract the driver had worked that day and overwrote it. A
     * duplicate row left behind by an older bug is removed on the way. Cash already handed over
     * cannot be un-collected, so a day that has been settled refuses a lower collection.
     *
     * @param  array<string, mixed>  $attributes
     * @return DailyLog|string the row, or the message refusing it
     */
    public static function write(int $companyId, int $userId, array $attributes): DailyLog|string
    {
        $matching = DailyLog::withTrashed()->withoutGlobalScopes()
            ->where('employee_id', $attributes['employee_id'])
            ->where('contract_id', $attributes['contract_id'])
            ->where('log_date', $attributes['log_date'])
            ->orderBy('id')
            ->get();

        foreach ($matching->slice(1) as $duplicate) {
            $duplicate->forceDelete();
        }

        $cashCollected = (float) ($attributes['cash_collected'] ?? 0);
        $existing = $matching->first();

        if (! $existing) {
            try {
                return DailyLog::create(array_merge($attributes, [
                    'company_id' => $companyId,
                    'created_by' => $userId,
                    'cash_settled' => 0,
                    'cash_pending' => $cashCollected,
                ]));
            } catch (QueryException $e) {
                // Written by a concurrent request in the meantime: only this contract's row can be
                // the one that clashed, so take it and update it.
                $existing = DailyLog::withTrashed()->withoutGlobalScopes()
                    ->where('employee_id', $attributes['employee_id'])
                    ->where('contract_id', $attributes['contract_id'])
                    ->where('log_date', $attributes['log_date'])
                    ->first();
                if (! $existing) {
                    throw $e;
                }
            }
        }

        if ($existing->trashed()) {
            $existing->restore();
        }

        if ($blocked = self::settledCashBlocks($existing, $cashCollected)) {
            return $blocked;
        }

        $existing->update(array_merge($attributes, [
            'company_id' => $companyId,
            'cash_pending' => max(0, $cashCollected - (float) ($existing->cash_settled ?? 0)),
        ]));

        return $existing;
    }
}
