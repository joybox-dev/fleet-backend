<?php

namespace App\Observers;

use App\Helpers\ErpSync;
use App\Models\DailyLog;
use App\Services\ErpNext\Jobs\SyncDailyLogJob;

/**
 * Keeps the vehicle's odometer in step with what the day recorded, and hands the row to the
 * ERPNext bridge.
 *
 * It used to also rebuild the retired legacy payroll run on every save — 3,500 slip computations
 * inside one bulk request — and stamp `daily_logs.driver_commission`, a column no live screen
 * reads except two reports that then trusted it over the payroll sheet. Both are gone: the
 * contract sheet prices a month when it is asked to, from the logs as they stand.
 */
class DailyLogObserver
{
    private const ERP_FIELDS = ['erp_id', 'erp_synced_at', 'erp_sync_status'];

    public function created(DailyLog $log): void
    {
        ErpSync::dispatch(SyncDailyLogJob::class, $log->id);
        $this->updateVehicleOdometer($log);
    }

    public function updated(DailyLog $log): void
    {
        // Anti-loop guard
        $changedFields = array_keys($log->getChanges());
        if (empty(array_diff($changedFields, [...self::ERP_FIELDS, 'updated_at']))) {
            return;
        }

        if ($log->wasChanged(['orders_count', 'cash_collected', 'cash_pending'])) {
            ErpSync::dispatch(SyncDailyLogJob::class, $log->id);
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
}
