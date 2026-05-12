<?php

namespace App\Observers;

use App\Helpers\ErpSync;
use App\Models\Company;
use App\Services\ErpNext\Jobs\SyncCompanyJob;

/**
 * CompanyObserver
 *
 * Automatically syncs Company changes to ERPNext.
 * Replaces manual ErpSync::dispatch() calls in SuperAdminCompanyController.
 */
class CompanyObserver
{
    /**
     * ERP-only fields that should NOT trigger a re-sync.
     * Prevents infinite loops when sync jobs update these fields.
     */
    private const ERP_FIELDS = [
        'erp_company_name', 'erp_cost_center', 'erp_abbreviation',
        'erp_config', 'erp_sync_status',
    ];

    public function created(Company $company): void
    {
        ErpSync::dispatch(SyncCompanyJob::class, $company->id);
    }

    public function updated(Company $company): void
    {
        // Anti-loop guard: skip if ONLY ERP fields changed
        $changedFields = array_keys($company->getChanges());
        if (empty(array_diff($changedFields, [...self::ERP_FIELDS, 'updated_at']))) {
            return;
        }

        // Only re-sync if business-critical fields changed
        $syncFields = ['name', 'name_ar', 'currency', 'is_active'];
        if ($company->wasChanged($syncFields)) {
            ErpSync::dispatch(SyncCompanyJob::class, $company->id);
        }
    }
}
