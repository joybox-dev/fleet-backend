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
        if (app()->bound('suppress_daily_log_observer') && app('suppress_daily_log_observer')) {
            return;
        }
        ErpSync::dispatch(SyncDailyLogJob::class, $log->id);
        $this->updateVehicleOdometer($log);
        $this->recalculatePayrollFor($log);
    }

    public function updated(DailyLog $log): void
    {
        if (app()->bound('suppress_daily_log_observer') && app('suppress_daily_log_observer')) {
            return;
        }

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

        if ($log->wasChanged(['odometer_end', 'vehicle_id'])) {
            $this->updateVehicleOdometer($log);
        }
    }

    private function updateVehicleOdometer(DailyLog $log): void
    {
        if ($log->odometer_end && $log->vehicle) {
            $vehicle = $log->vehicle;
            if ($log->odometer_end > $vehicle->odometer_km) {
                $vehicle->update(['odometer_km' => $log->odometer_end]);
            }
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
