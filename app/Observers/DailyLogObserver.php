<?php

namespace App\Observers;

use App\Helpers\ErpSync;
use App\Http\Controllers\Api\PayrollController;
use App\Models\DailyLog;
use App\Services\ErpNext\Jobs\SyncDailyLogJob;
use Carbon\Carbon;

/**
 * DailyLogObserver
 *
 * Automatically syncs DailyLog changes to ERPNext Sales Invoice.
 * Replaces manual ErpSync::dispatch() in DailyLogController store() and update().
 */
class DailyLogObserver
{
    private const ERP_FIELDS = ['erp_id', 'erp_synced_at', 'erp_sync_status'];

    /** True while a bulk write is running: recalculations are collected instead of run per row. */
    private static bool $deferring = false;

    /** @var array<string, array{int, int, int}> pending [employeeId, year, month] */
    private static array $pending = [];

    public function created(DailyLog $log): void
    {
        ErpSync::dispatch(SyncDailyLogJob::class, $log->id);
        $this->updateVehicleOdometer($log);
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
     * Collect recalculations instead of running one per saved row.
     *
     * A 31-row bulk save fired this observer 31 times, and every call rebuilt each slip in the
     * month's draft payroll run (113 of them) — roughly 3,500 full slip computations inside a
     * single HTTP request, which is why saving one month took 23 seconds. The work is identical
     * for every row of the same (employee, month), so the caller defers it and flushes once.
     */
    public static function deferRecalculations(): void
    {
        self::$deferring = true;
        self::$pending = [];

        // Safety net: a caller that dies mid-loop must not leave the flag set for whatever
        // else this request still writes.
        app()->terminating(function () {
            self::$deferring = false;
            self::$pending = [];
        });
    }

    /**
     * Run every recalculation collected since deferRecalculations(), each one only once.
     */
    public static function flushRecalculations(): void
    {
        $pending = self::$pending;
        self::$deferring = false;
        self::$pending = [];

        foreach ($pending as [$employeeId, $year, $month]) {
            self::recalculateCommissions($employeeId, $year, $month);
        }
    }

    /**
     * Trigger automatic retroactive payroll recalculation if a draft run exists.
     */
    private function recalculatePayrollFor(DailyLog $log): void
    {
        try {
            $date = Carbon::parse($log->log_date);
        } catch (\Throwable $e) {
            \Log::error('Retroactive recalculation in DailyLogObserver failed: '.$e->getMessage());

            return;
        }

        if (self::$deferring) {
            self::$pending[$log->employee_id.'|'.$date->year.'|'.$date->month] = [
                (int) $log->employee_id, $date->year, $date->month,
            ];

            return;
        }

        self::recalculateCommissions((int) $log->employee_id, $date->year, $date->month);
    }

    /**
     * Recalculate driver commissions chronologically for the month.
     */
    private static function recalculateCommissions(int $employeeId, int $year, int $month): void
    {
        try {
            PayrollController::recalculateEmployeeCommissions($employeeId, $year, $month);
        } catch (\Throwable $e) {
            \Log::error('Retroactive recalculation in DailyLogObserver failed: '.$e->getMessage());
        }
    }
}
