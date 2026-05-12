<?php

namespace App\Services\ErpNext\Jobs;

use App\Models\SalaryAdvance;
use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use App\Services\ErpNext\Mappers\AdvanceMapper;
use Illuminate\Support\Facades\Log;

/**
 * SyncAdvanceJob
 *
 * Dispatched when a salary advance is created.
 * Creates a Journal Entry in ERPNext:
 *   Debit:  Employee Advance Receivable (Asset ↑)
 *   Credit: Cash In Hand (Asset ↓)
 */
class SyncAdvanceJob extends BaseErpSyncJob
{
    public function __construct(
        private int $advanceId,
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        $advance = SalaryAdvance::withoutGlobalScope('company')
            ->with('employee:id,name')
            ->find($this->advanceId);

        if (!$advance) {
            Log::channel('erpnext')->warning("SyncAdvanceJob: advance #{$this->advanceId} not found");
            return;
        }

        try {
            $payload = AdvanceMapper::toJournalEntry($advance);
            $result  = $service->createDocument('Journal Entry', $payload);
            $erpName = $result['name'] ?? $result['data']['name'] ?? null;

            $this->updateSyncStatus('salary_advances', $advance->id, 'synced', $erpName);

            Log::channel('erpnext')->info("Salary advance synced to ERPNext", [
                'advance_id'        => $advance->id,
                'employee'          => $advance->employee->name ?? '',
                'amount'            => $advance->amount,
                'erp_journal_entry' => $erpName,
            ]);
        } catch (ErpNextCircuitOpenException $e) {
            $this->release(300);
        } catch (\Exception $e) {
            $this->updateSyncStatus('salary_advances', $advance->id, 'failed');
            Log::channel('erpnext')->error("Salary advance sync failed", [
                'advance_id' => $advance->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
