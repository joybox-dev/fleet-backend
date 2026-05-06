<?php

namespace App\Services\ErpNext\Jobs;

use App\Models\Client;
use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use Illuminate\Support\Facades\Log;

/**
 * Sync a FleetOps Client → ERPNext Customer.
 * Triggered when: owner creates a new client/brand.
 */
class SyncClientJob extends BaseErpSyncJob
{
    public function __construct(
        private int $clientId,
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        $client = Client::find($this->clientId);
        if (!$client) return;

        try {
            $erpName = $service->syncClient($client->toArray());
            $this->updateSyncStatus('clients', $this->clientId, 'synced', $erpName);

            Log::channel('erpnext')->info("Client synced", [
                'client_id' => $this->clientId,
                'erp_customer' => $erpName,
            ]);
        } catch (ErpNextCircuitOpenException $e) {
            $this->release(300);
        } catch (\Exception $e) {
            $this->updateSyncStatus('clients', $this->clientId, 'failed');
            throw $e;
        }
    }
}
