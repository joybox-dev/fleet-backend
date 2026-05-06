<?php

namespace App\Services\ErpNext\Jobs;

use App\Models\Vehicle;
use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use Illuminate\Support\Facades\Log;

class SyncVehicleJob extends BaseErpSyncJob
{
    public function __construct(
        private int $vehicleId,
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        $vehicle = Vehicle::find($this->vehicleId);
        if (!$vehicle) return;

        try {
            $erpName = $service->syncVehicle($vehicle->toArray());
            $this->updateSyncStatus('vehicles', $this->vehicleId, 'synced', $erpName);

            Log::channel('erpnext')->info("Vehicle synced", [
                'vehicle_id' => $this->vehicleId,
                'erp_asset' => $erpName,
            ]);
        } catch (ErpNextCircuitOpenException $e) {
            $this->release(300);
        } catch (\Exception $e) {
            $this->updateSyncStatus('vehicles', $this->vehicleId, 'failed');
            throw $e;
        }
    }
}
