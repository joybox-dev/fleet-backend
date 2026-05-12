<?php

namespace App\Observers;

use App\Helpers\ErpSync;
use App\Models\Violation;
use App\Services\ErpNext\Jobs\SyncViolationJob;

/**
 * ViolationObserver
 *
 * Automatically syncs Violation changes to ERPNext Journal Entry.
 * Replaces manual ErpSync::dispatch() in ViolationController store().
 * Fixes BUG: Violation update was never synced.
 */
class ViolationObserver
{
    private const ERP_FIELDS = ['erp_id', 'erp_synced_at', 'erp_sync_status'];

    public function created(Violation $violation): void
    {
        ErpSync::dispatch(SyncViolationJob::class, $violation->id);
    }

    public function updated(Violation $violation): void
    {
        // Anti-loop guard
        $changedFields = array_keys($violation->getChanges());
        if (empty(array_diff($changedFields, [...self::ERP_FIELDS, 'updated_at']))) {
            return;
        }

        $syncFields = ['amount', 'is_driver_liable', 'violation_type'];
        if ($violation->wasChanged($syncFields)) {
            ErpSync::dispatch(SyncViolationJob::class, $violation->id);
        }
    }
}
