<?php

namespace App\Services\ErpNext\Jobs;

use App\Models\Contract;
use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use Illuminate\Support\Facades\Log;

/**
 * SyncFixedContractInvoiceJob
 *
 * Creates a Sales Invoice in ERPNext for a fixed-monthly contract.
 * Dispatched by the `fleetops:invoice-fixed-contracts` command
 * at the end of each month.
 *
 * Unlike per-order contracts (synced via SyncDailyLogJob on each log entry),
 * fixed contracts have no daily logs — they bill a flat monthly rate.
 * This job fills that gap.
 */
class SyncFixedContractInvoiceJob extends BaseErpSyncJob
{
    public function __construct(
        private int    $contractId,
        private string $year,
        private string $month,
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        $contract = Contract::with('client')->find($this->contractId);
        if (!$contract || !$contract->client) {
            Log::channel('erpnext')->warning("Fixed contract invoice skipped — contract or client missing", [
                'contract_id' => $this->contractId,
            ]);
            return;
        }

        // Skip if amount is zero or negative
        if (($contract->fixed_monthly ?? 0) <= 0) {
            Log::channel('erpnext')->info("Fixed contract invoice skipped — zero amount", [
                'contract_id' => $this->contractId,
            ]);
            return;
        }

        try {
            $erpName = $service->syncFixedContractInvoice(
                $contract->toArray(),
                $contract->client->toArray(),
                $this->year,
                $this->month
            );

            Log::channel('erpnext')->info("Fixed contract invoice created in ERPNext", [
                'erp_invoice'   => $erpName,
                'contract_id'   => $this->contractId,
                'contract_name' => $contract->name,
                'amount'        => $contract->fixed_monthly,
                'period'        => "{$this->year}-{$this->month}",
            ]);
        } catch (ErpNextCircuitOpenException $e) {
            $this->release(300);
        } catch (\Exception $e) {
            Log::channel('erpnext')->error("Fixed contract invoice sync failed", [
                'contract_id' => $this->contractId,
                'period'      => "{$this->year}-{$this->month}",
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
