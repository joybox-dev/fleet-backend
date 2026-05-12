<?php

namespace App\Services\ErpNext\Jobs;

use App\Models\Company;
use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use App\Services\ErpNext\Mappers\CompanyMapper;
use Illuminate\Support\Facades\Log;

/**
 * SyncCompanyJob
 *
 * Dispatched when a super admin creates a new FleetOps company.
 * Provisions the ERPNext side:
 *   1. Creates ERPNext Company (with auto Chart of Accounts)
 *   2. Reads back the abbreviation + default cost center
 *   3. Seeds Salary Structure for the new company
 *   4. Updates companies table with erp_company_name, erp_cost_center, etc.
 */
class SyncCompanyJob extends BaseErpSyncJob
{
    public function __construct(
        private int $companyId,
    ) {}

    public function handle(ErpNextService $service): void
    {
        if (!config('erpnext.sync.enabled')) return;

        $company = Company::find($this->companyId);

        if (!$company) {
            Log::channel('erpnext')->warning("SyncCompanyJob: company #{$this->companyId} not found");
            return;
        }

        // Skip if already synced
        if ($company->erp_sync_status === 'synced' && $company->erp_company_name) {
            Log::channel('erpnext')->info("SyncCompanyJob: company already synced", [
                'company_id'       => $company->id,
                'erp_company_name' => $company->erp_company_name,
            ]);
            return;
        }

        try {
            $payload = CompanyMapper::toErpNext($company);
            $abbr    = $payload['abbr'];

            // 1. Create ERPNext Company
            $result  = $service->createDocument('Company', $payload);
            $erpName = $result['name'] ?? $result['data']['name'] ?? $company->name;

            // 2. Resolve the default cost center (ERPNext auto-creates "Main - {ABBR}")
            $costCenter = "Main - {$abbr}";

            // 3. Seed Salary Structure for this company
            $this->seedSalaryStructure($service, $erpName);

            // 4. Update FleetOps company record
            $company->update([
                'erp_company_name' => $erpName,
                'erp_cost_center'  => $costCenter,
                'erp_abbreviation' => $abbr,
                'erp_sync_status'  => 'synced',
            ]);

            Log::channel('erpnext')->info("Company provisioned in ERPNext", [
                'company_id'       => $company->id,
                'erp_company_name' => $erpName,
                'erp_abbreviation' => $abbr,
                'erp_cost_center'  => $costCenter,
            ]);

        } catch (ErpNextCircuitOpenException $e) {
            $this->release(300);
        } catch (\Exception $e) {
            // Check if it's a "already exists" error — try to recover
            if (str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), 'DuplicateEntry')) {
                $this->handleAlreadyExists($company, $service);
                return;
            }

            $company->update(['erp_sync_status' => 'failed']);

            Log::channel('erpnext')->error("Company provisioning failed", [
                'company_id' => $company->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * If the ERPNext company already exists (e.g. re-run), read it and update our record.
     */
    private function handleAlreadyExists(Company $company, ErpNextService $service): void
    {
        try {
            $erpDoc = $service->getDocument('Company', $company->name);

            if ($erpDoc) {
                $abbr = $erpDoc['abbr'] ?? CompanyMapper::generateAbbreviation($company);
                $company->update([
                    'erp_company_name' => $erpDoc['name'] ?? $company->name,
                    'erp_cost_center'  => $erpDoc['cost_center'] ?? "Main - {$abbr}",
                    'erp_abbreviation' => $abbr,
                    'erp_sync_status'  => 'synced',
                ]);

                Log::channel('erpnext')->info("Company already existed in ERPNext — linked", [
                    'company_id'       => $company->id,
                    'erp_company_name' => $erpDoc['name'] ?? $company->name,
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('erpnext')->warning("Could not recover existing ERPNext company", [
                'company_id' => $company->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Seed a Salary Structure for the new ERPNext company.
     */
    private function seedSalaryStructure(ErpNextService $service, string $erpCompanyName): void
    {
        try {
            $structureName = "{$erpCompanyName} Official Salary";
            $payload = [
                'doctype'           => 'Salary Structure',
                'name'              => $structureName,
                'company'           => $erpCompanyName,
                'payroll_frequency' => config('erpnext.payroll.payroll_frequency', 'Monthly'),
                'is_active'         => 'Yes',
                'earnings'          => [
                    [
                        'salary_component'        => 'Basic',
                        'formula'                 => 'base',
                        'amount_based_on_formula'=> 1,
                    ],
                ],
            ];

            $service->createDocument('Salary Structure', $payload);

            Log::channel('erpnext')->info("Salary Structure seeded for company", [
                'company' => $erpCompanyName,
                'structure' => $structureName,
            ]);
        } catch (\Exception $e) {
            // Non-critical — log but don't fail the job
            Log::channel('erpnext')->warning("Salary Structure seeding failed (non-critical)", [
                'company' => $erpCompanyName,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
