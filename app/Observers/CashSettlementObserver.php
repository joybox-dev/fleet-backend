<?php

namespace App\Observers;

use App\Helpers\ErpSync;
use App\Models\CashSettlement;
use App\Services\ErpNext\Jobs\SyncSettlementJob;

/**
 * CashSettlementObserver
 *
 * Automatically syncs CashSettlement creation to ERPNext Payment Entry.
 * Replaces manual ErpSync::dispatch() in CashSettlementController store().
 */
class CashSettlementObserver
{
    public function created(CashSettlement $settlement): void
    {
        ErpSync::dispatch(SyncSettlementJob::class, $settlement->id);
    }
}
