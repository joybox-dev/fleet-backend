<?php

namespace App\Services\ErpNext\Jobs;

use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use Illuminate\Support\Facades\Log;

/**
 * SyncPayrollDeductionsJob
 *
 * Dispatched when a payroll batch is APPROVED.
 * Creates a single Journal Entry in ERPNext that records
 * the company's recovery of deductions from driver cash envelopes.
 *
 * This bridges the gap where:
 * - ERPNext Salary Slip shows only official bank salary (untouched)
 * - FleetOps internally deducted violations/maintenance from cash
 * - ERPNext needs a Journal Entry to balance its books
 */
class SyncPayrollDeductionsJob extends BaseErpSyncJob
{
    public function __construct(
        private string $year,
        private string $month,
        private float  $totalViolations,
        private float  $totalMaintenance,
        private float  $totalCustody = 0,
        private float  $totalAdvances = 0,
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        $totalDeductions = $this->totalViolations + $this->totalMaintenance + $this->totalCustody + $this->totalAdvances;

        // Skip if no deductions to sync
        if ($totalDeductions <= 0) {
            Log::channel('erpnext')->info("Payroll deductions sync skipped — no deductions", [
                'period' => "{$this->year}-{$this->month}",
            ]);
            return;
        }

        try {
            $erpName = $service->syncPayrollDeductions(
                $this->year,
                $this->month,
                $this->totalViolations,
                $this->totalMaintenance,
                $this->totalCustody,
                $this->totalAdvances
            );

            Log::channel('erpnext')->info("Payroll deductions Journal Entry created", [
                'erp_journal_entry' => $erpName,
                'period'            => "{$this->year}-{$this->month}",
                'violations'        => $this->totalViolations,
                'maintenance'       => $this->totalMaintenance,
                'custody'           => $this->totalCustody,
                'advances'          => $this->totalAdvances,
                'total'             => $totalDeductions,
            ]);
        } catch (ErpNextCircuitOpenException $e) {
            // Circuit is open — release job for retry when ERPNext is back
            $this->release(300);
        } catch (\Exception $e) {
            Log::channel('erpnext')->error("Payroll deductions sync failed", [
                'period' => "{$this->year}-{$this->month}",
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
