<?php

namespace App\Observers;

use App\Helpers\ErpSync;
use App\Models\Vehicle;
use App\Services\ErpNext\Jobs\SyncVehicleJob;

/**
 * VehicleObserver
 *
 * Automatically syncs Vehicle changes to ERPNext.
 * Replaces manual ErpSync::dispatch() in VehicleController store().
 * Fixes BUG: Vehicle update and delete were never synced.
 */
class VehicleObserver
{
    private const ERP_FIELDS = ['erp_id', 'erp_synced_at', 'erp_sync_status'];

    public function created(Vehicle $vehicle): void
    {
        ErpSync::dispatch(SyncVehicleJob::class, $vehicle->id);
    }

    public function updated(Vehicle $vehicle): void
    {
        // Anti-loop guard
        $changedFields = array_keys($vehicle->getChanges());
        if (empty(array_diff($changedFields, [...self::ERP_FIELDS, 'updated_at']))) {
            return;
        }

        $syncFields = ['plate_number', 'make', 'model', 'year', 'status', 'color'];
        if ($vehicle->wasChanged($syncFields)) {
            ErpSync::dispatch(SyncVehicleJob::class, $vehicle->id);
        }
    }

    public function deleted(Vehicle $vehicle): void
    {
        // SoftDeletes → depreciate/scrap in ERPNext
        if ($vehicle->erp_id) {
            ErpSync::dispatch(SyncVehicleJob::class, $vehicle->id);
        }
    }
}
