<?php

namespace App\Observers;

use App\Helpers\ErpSync;
use App\Models\SalaryAdvance;
use App\Services\ErpNext\Jobs\SyncAdvanceJob;

/**
 * SalaryAdvanceObserver
 *
 * Automatically syncs SalaryAdvance creation to ERPNext Journal Entry.
 * Replaces manual ErpSync::dispatch() in SalaryAdvanceController store().
 */
class SalaryAdvanceObserver
{
    public function created(SalaryAdvance $advance): void
    {
        ErpSync::dispatch(SyncAdvanceJob::class, $advance->id);
    }
}
