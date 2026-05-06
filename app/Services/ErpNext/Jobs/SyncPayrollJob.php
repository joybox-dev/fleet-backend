<?php

namespace App\Services\ErpNext\Jobs;

use App\Models\Employee;
use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use Illuminate\Support\Facades\Log;

class SyncPayrollJob extends BaseErpSyncJob
{
    public function __construct(
        private array $employeeIds,
        private string $year,
        private string $month,
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        $employees = Employee::whereIn('id', $this->employeeIds)->get();

        foreach ($employees as $employee) {
            try {
                $erpName = $service->syncPayroll($employee->toArray(), $this->year, $this->month);

                if ($erpName) {
                    Log::channel('erpnext')->info("Payroll synced", [
                        'employee_id' => $employee->id,
                        'erp_salary_slip' => $erpName,
                        'period' => "{$this->year}-{$this->month}",
                    ]);
                }
            } catch (ErpNextCircuitOpenException $e) {
                $this->release(300);
                return; // Stop processing, will retry all
            } catch (\Exception $e) {
                Log::channel('erpnext')->error("Payroll sync failed for employee {$employee->id}", [
                    'error' => $e->getMessage(),
                ]);
                // Continue with other employees
            }
        }
    }
}
