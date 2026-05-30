<?php

namespace App\Observers;

use App\Helpers\ErpSync;
use App\Models\DailyLog;
use App\Services\ErpNext\Jobs\SyncDailyLogJob;

/**
 * DailyLogObserver
 *
 * Automatically syncs DailyLog changes to ERPNext Sales Invoice.
 * Replaces manual ErpSync::dispatch() in DailyLogController store() and update().
 */
class DailyLogObserver
{
    private const ERP_FIELDS = ['erp_id', 'erp_synced_at', 'erp_sync_status'];

    public function created(DailyLog $log): void
    {
        ErpSync::dispatch(SyncDailyLogJob::class, $log->id);
        $this->recalculatePayrollFor($log);
    }

    public function updated(DailyLog $log): void
    {
        // Anti-loop guard
        $changedFields = array_keys($log->getChanges());
        if (empty(array_diff($changedFields, [...self::ERP_FIELDS, 'updated_at']))) {
            return;
        }

        // Re-sync if order count or income changed (recalculate invoice)
        $syncFields = ['orders_count', 'income_amount', 'rate_per_order', 'cash_collected', 'cash_pending'];
        if ($log->wasChanged($syncFields)) {
            ErpSync::dispatch(SyncDailyLogJob::class, $log->id);
            $this->recalculatePayrollFor($log);
        }
    }

    public function deleted(DailyLog $log): void
    {
        $this->recalculatePayrollFor($log);
    }

    /**
     * Trigger automatic retroactive payroll recalculation if a draft run exists.
     */
    private function recalculatePayrollFor(DailyLog $log): void
    {
        try {
            $date = \Carbon\Carbon::parse($log->log_date);
            $year = $date->year;
            $month = $date->month;

            // Recalculate driver commissions chronologically for the month
            \App\Http\Controllers\Api\PayrollController::recalculateEmployeeCommissions($log->employee_id, $year, $month);

            $run = \App\Models\PayrollRun::where('year', $year)
                ->where('month', $month)
                ->where('status', 'draft')
                ->first();

            if ($run) {
                \App\Http\Controllers\Api\PayrollController::recalculateRun($run);
            }
        } catch (\Throwable $e) {
            \Log::error('Retroactive recalculation in DailyLogObserver failed: ' . $e->getMessage());
        }
    }
}
