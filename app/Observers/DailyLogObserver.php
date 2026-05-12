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
    }

    public function updated(DailyLog $log): void
    {
        // Anti-loop guard
        $changedFields = array_keys($log->getChanges());
        if (empty(array_diff($changedFields, [...self::ERP_FIELDS, 'updated_at']))) {
            return;
        }

        // Re-sync if order count or income changed (recalculate invoice)
        $syncFields = ['orders_count', 'income_amount', 'rate_per_order'];
        if ($log->wasChanged($syncFields)) {
            ErpSync::dispatch(SyncDailyLogJob::class, $log->id);
        }
    }
}
