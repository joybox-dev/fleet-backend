<?php

namespace App\Services\ErpNext\Jobs;

use App\Models\DailyLog;
use App\Models\Contract;
use App\Models\Vehicle;
use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use Illuminate\Support\Facades\Log;

class SyncDailyLogJob extends BaseErpSyncJob
{
    public function __construct(
        private int $dailyLogId,
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        $log = DailyLog::find($this->dailyLogId);
        if (!$log) return;

        $contract = Contract::find($log->contract_id);
        $vehicle = Vehicle::find($log->vehicle_id);
        if (!$contract || !$vehicle) return;

        try {
            $erpName = $service->syncDailyLog($log->toArray(), $contract->toArray(), $vehicle->toArray());
            $this->updateSyncStatus('daily_logs', $this->dailyLogId, 'synced', $erpName);

            Log::channel('erpnext')->info("DailyLog synced", [
                'daily_log_id' => $this->dailyLogId,
                'erp_invoice' => $erpName,
            ]);
        } catch (ErpNextCircuitOpenException $e) {
            $this->release(300);
        } catch (\Exception $e) {
            $this->updateSyncStatus('daily_logs', $this->dailyLogId, 'failed');
            throw $e;
        }
    }
}
