<?php

namespace App\Services\ErpNext\Jobs;

use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use Illuminate\Support\Facades\Log;

/**
 * SyncFuelExpenseJob
 *
 * Dispatched when a payroll batch is APPROVED and fuel_allowance > 0.
 * Creates a Journal Entry in ERPNext recording the company's
 * consolidated fuel expense for the month.
 *
 * Fuel allowances are part of the internal cash envelope —
 * ERPNext's Salary Slip doesn't include them, so we need
 * a separate JE to keep the books balanced.
 */
class SyncFuelExpenseJob extends BaseErpSyncJob
{
    public function __construct(
        private string $year,
        private string $month,
        private float  $totalAmount,
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        if ($this->totalAmount <= 0) {
            Log::channel('erpnext')->info("Fuel expense sync skipped — no fuel allowances", [
                'period' => "{$this->year}-{$this->month}",
            ]);
            return;
        }

        try {
            $erpName = $service->syncFuelExpense(
                $this->year,
                $this->month,
                $this->totalAmount
            );

            Log::channel('erpnext')->info("Fuel expense Journal Entry created", [
                'erp_journal_entry' => $erpName,
                'period'            => "{$this->year}-{$this->month}",
                'amount'            => $this->totalAmount,
            ]);
        } catch (ErpNextCircuitOpenException $e) {
            $this->release(300);
        } catch (\Exception $e) {
            Log::channel('erpnext')->error("Fuel expense sync failed", [
                'period' => "{$this->year}-{$this->month}",
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
