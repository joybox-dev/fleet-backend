<?php

namespace App\Observers;

use App\Helpers\ErpSync;
use App\Models\Client;
use App\Services\ErpNext\Jobs\SyncClientJob;

/**
 * ClientObserver
 *
 * Automatically syncs Client changes to ERPNext Customer.
 * Replaces manual ErpSync::dispatch() in ClientController store().
 * Fixes BUG: Client update and delete were never synced.
 */
class ClientObserver
{
    private const ERP_FIELDS = ['erp_id', 'erp_synced_at', 'erp_sync_status'];

    public function created(Client $client): void
    {
        ErpSync::dispatch(SyncClientJob::class, $client->id);
    }

    public function updated(Client $client): void
    {
        // Anti-loop guard
        $changedFields = array_keys($client->getChanges());
        if (empty(array_diff($changedFields, [...self::ERP_FIELDS, 'updated_at']))) {
            return;
        }

        $syncFields = ['name', 'name_ar', 'phone', 'email', 'is_active', 'contact_person'];
        if ($client->wasChanged($syncFields)) {
            ErpSync::dispatch(SyncClientJob::class, $client->id);
        }
    }

    public function deleted(Client $client): void
    {
        // SoftDeletes → disable Customer in ERPNext
        if ($client->erp_id) {
            ErpSync::dispatch(SyncClientJob::class, $client->id);
        }
    }
}
