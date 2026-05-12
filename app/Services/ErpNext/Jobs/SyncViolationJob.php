<?php

namespace App\Services\ErpNext\Jobs;

use App\Models\Violation;
use App\Models\Employee;
use App\Services\ErpNext\CompanyErpContext;
use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use Illuminate\Support\Facades\Log;

class SyncViolationJob extends BaseErpSyncJob
{
    public function __construct(
        private int $violationId,
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        $violation = Violation::find($this->violationId);
        if (!$violation) return;

        $employee = Employee::find($violation->employee_id);
        if (!$employee) return;

        $ctx = CompanyErpContext::forCompany($violation->company_id);

        try {
            $erpName = $service->syncViolation($violation->toArray(), $employee->toArray(), $ctx);
            $this->updateSyncStatus('violations', $this->violationId, 'synced', $erpName);

            Log::channel('erpnext')->info("Violation synced", [
                'violation_id' => $this->violationId,
                'erp_journal' => $erpName,
                'erp_company' => $ctx->company,
            ]);
        } catch (ErpNextCircuitOpenException $e) {
            $this->release(300);
        } catch (\Exception $e) {
            $this->updateSyncStatus('violations', $this->violationId, 'failed');
            throw $e;
        }
    }
}
