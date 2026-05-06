<?php

namespace App\Services\ErpNext\Jobs;

use App\Models\CustodyItem;
use App\Models\Employee;
use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use Illuminate\Support\Facades\Log;

class SyncCustodyJob extends BaseErpSyncJob
{
    public function __construct(
        private int $custodyItemId,
        private string $action = 'issue', // 'issue' or 'return'
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        $item = CustodyItem::find($this->custodyItemId);
        if (!$item) return;

        $employee = Employee::find($item->employee_id);
        if (!$employee) return;

        try {
            if ($this->action === 'return') {
                $erpName = $service->syncCustodyReturn($item->toArray(), $employee->toArray());
            } else {
                $erpName = $service->syncCustodyIssue($item->toArray(), $employee->toArray());
            }

            $this->updateSyncStatus('custody_items', $this->custodyItemId, 'synced', $erpName);

            Log::channel('erpnext')->info("Custody {$this->action} synced", [
                'custody_id' => $this->custodyItemId,
                'erp_stock_entry' => $erpName,
            ]);
        } catch (ErpNextCircuitOpenException $e) {
            $this->release(300);
        } catch (\Exception $e) {
            $this->updateSyncStatus('custody_items', $this->custodyItemId, 'failed');
            throw $e;
        }
    }
}
