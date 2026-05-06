<?php

namespace App\Services\ErpNext\Jobs;

use App\Models\CashSettlement;
use App\Models\Employee;
use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use Illuminate\Support\Facades\Log;

class SyncSettlementJob extends BaseErpSyncJob
{
    public function __construct(
        private int $settlementId,
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        $settlement = CashSettlement::find($this->settlementId);
        if (!$settlement) return;

        $employee = Employee::find($settlement->employee_id);
        if (!$employee) return;

        try {
            $erpName = $service->syncCashSettlement($settlement->toArray(), $employee->toArray());
            $this->updateSyncStatus('cash_settlements', $this->settlementId, 'synced', $erpName);

            Log::channel('erpnext')->info("Settlement synced", [
                'settlement_id' => $this->settlementId,
                'erp_payment' => $erpName,
            ]);
        } catch (ErpNextCircuitOpenException $e) {
            $this->release(300);
        } catch (\Exception $e) {
            $this->updateSyncStatus('cash_settlements', $this->settlementId, 'failed');
            throw $e;
        }
    }
}
