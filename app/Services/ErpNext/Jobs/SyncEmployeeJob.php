<?php

namespace App\Services\ErpNext\Jobs;

use App\Models\Employee;
use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use Illuminate\Support\Facades\Log;

class SyncEmployeeJob extends BaseErpSyncJob
{
    public function __construct(
        private int $employeeId,
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        $employee = Employee::find($this->employeeId);
        if (!$employee) return;

        try {
            $erpName = $service->syncEmployee($employee->toArray());
            $this->updateSyncStatus('employees', $this->employeeId, 'synced', $erpName);

            Log::channel('erpnext')->info("Employee synced", [
                'employee_id' => $this->employeeId,
                'erp_employee' => $erpName,
            ]);
        } catch (ErpNextCircuitOpenException $e) {
            $this->release(300);
        } catch (\Exception $e) {
            $this->updateSyncStatus('employees', $this->employeeId, 'failed');
            throw $e;
        }
    }
}
