<?php

namespace App\Observers;

use App\Helpers\ErpSync;
use App\Models\Employee;
use App\Services\ErpNext\Jobs\SyncEmployeeJob;

/**
 * EmployeeObserver
 *
 * Automatically syncs Employee changes to ERPNext.
 * Replaces manual ErpSync::dispatch() calls in EmployeeController store() and update().
 * Also handles soft-deletes → marks employee as "Left" in ERPNext.
 */
class EmployeeObserver
{
    /**
     * ERP-only fields that should NOT trigger a re-sync.
     */
    private const ERP_FIELDS = ['erp_id', 'erp_synced_at', 'erp_sync_status'];

    public function created(Employee $employee): void
    {
        ErpSync::dispatch(SyncEmployeeJob::class, $employee->id);
    }

    public function updated(Employee $employee): void
    {
        // Anti-loop guard: skip if ONLY ERP fields changed
        $changedFields = array_keys($employee->getChanges());
        if (empty(array_diff($changedFields, [...self::ERP_FIELDS, 'updated_at']))) {
            return;
        }

        // Only sync on meaningful field changes
        $syncFields = [
            'name', 'name_ar', 'phone', 'status', 'gender',
            'official_salary', 'actual_salary', 'date_of_joining',
            'nationality', 'civil_id', 'employee_type', 'pay_type',
        ];
        if ($employee->wasChanged($syncFields)) {
            ErpSync::dispatch(SyncEmployeeJob::class, $employee->id);
        }
    }

    public function deleted(Employee $employee): void
    {
        // SoftDeletes → mark as "Left" in ERPNext
        if ($employee->erp_id) {
            ErpSync::dispatch(SyncEmployeeJob::class, $employee->id);
        }
    }
}
