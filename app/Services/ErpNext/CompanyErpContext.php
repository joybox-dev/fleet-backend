<?php

namespace App\Services\ErpNext;

use App\Models\Company;
use Illuminate\Support\Facades\Log;

/**
 * CompanyErpContext
 *
 * Resolves ERPNext configuration for a specific FleetOps company.
 * Each tenant maps to a separate ERPNext Company within the same Frappe instance.
 *
 * Resolution priority:
 *   1. company.erp_company_name (stored after SyncCompanyJob provisions ERPNext)
 *   2. Global config('erpnext.company') fallback (backward compatible)
 *
 * Usage:
 *   $ctx = CompanyErpContext::forCompany($companyId);
 *   $ctx->company;     // "First Fleet Co"
 *   $ctx->costCenter;  // "Main - FF"
 *   $ctx->account('cash_in_hand'); // resolved per-company
 */
class CompanyErpContext
{
    public readonly string $company;
    public readonly string $costCenter;
    public readonly string $currency;
    public readonly string $abbreviation;
    public readonly ?int   $companyId;
    private array $accountOverrides;

    private function __construct(
        string $company,
        string $costCenter,
        string $currency,
        string $abbreviation,
        ?int $companyId,
        array $accountOverrides = [],
    ) {
        $this->company          = $company;
        $this->costCenter       = $costCenter;
        $this->currency         = $currency;
        $this->abbreviation     = $abbreviation;
        $this->companyId        = $companyId;
        $this->accountOverrides = $accountOverrides;
    }

    /**
     * Resolve context for a specific FleetOps company.
     */
    public static function forCompany(int $companyId): self
    {
        $company = Company::find($companyId);

        if (!$company || !$company->erp_company_name) {
            // Not yet provisioned → fall back to global config
            return self::fromGlobalConfig($companyId);
        }

        $erpConfig = $company->erp_config ?? [];

        return new self(
            company:          $company->erp_company_name,
            costCenter:       $company->erp_cost_center ?: "Main - {$company->erp_abbreviation}",
            currency:         $company->currency ?: config('erpnext.default_currency', 'KWD'),
            abbreviation:     $company->erp_abbreviation ?: 'FO',
            companyId:        $companyId,
            accountOverrides: $erpConfig['accounts'] ?? [],
        );
    }

    /**
     * Resolve context from the current authenticated user's company.
     */
    public static function fromCurrent(): self
    {
        $companyId = app('current_company_id') ?? auth()->user()?->company_id;

        if ($companyId) {
            return self::forCompany($companyId);
        }

        return self::fromGlobalConfig(null);
    }

    /**
     * Fallback: use global .env / config values (single-tenant mode).
     */
    public static function fromGlobalConfig(?int $companyId = null): self
    {
        return new self(
            company:          config('erpnext.company', 'FleetOps'),
            costCenter:       config('erpnext.cost_center', 'Main - FO'),
            currency:         config('erpnext.default_currency', 'KWD'),
            abbreviation:     'FO',
            companyId:        $companyId,
            accountOverrides: [],
        );
    }

    /**
     * Get an ERPNext account for this company.
     *
     * Priority: per-company override → global config
     */
    public function account(string $key): string
    {
        // 1. Check per-company override
        if (!empty($this->accountOverrides[$key])) {
            return $this->accountOverrides[$key];
        }

        // 2. Fall back to global config
        return config("erpnext.accounts.{$key}", '');
    }
}
