<?php

namespace App\Services\ErpNext\Jobs;

use App\Models\MaintenanceRequest;
use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use Illuminate\Support\Facades\Log;

class SyncMaintenanceJob extends BaseErpSyncJob
{
    public function __construct(
        private int $maintenanceId,
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        $maintenance = MaintenanceRequest::find($this->maintenanceId);
        if (!$maintenance) return;

        try {
            // Only sync approved maintenance with actual cost
            if ($maintenance->status === 'approved' && $maintenance->actual_cost) {
                $erpName = $service->syncMaintenancePayment(
                    $maintenance->toArray(),
                    ['amount' => $maintenance->actual_cost]
                );

                Log::channel('erpnext')->info("Maintenance synced", [
                    'maintenance_id' => $this->maintenanceId,
                    'erp_payment' => $erpName,
                ]);
            }
        } catch (ErpNextCircuitOpenException $e) {
            $this->release(300);
        } catch (\Exception $e) {
            Log::channel('erpnext')->error("Maintenance sync failed", [
                'maintenance_id' => $this->maintenanceId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
