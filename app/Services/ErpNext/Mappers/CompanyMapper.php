<?php

namespace App\Services\ErpNext\Mappers;

use App\Models\Company;

/**
 * CompanyMapper
 *
 * Maps a FleetOps Company → ERPNext Company doctype.
 * ERPNext auto-creates Chart of Accounts when a Company is created.
 */
class CompanyMapper
{
    /**
     * Build ERPNext Company payload for creation.
     */
    public static function toErpNext(Company $company): array
    {
        $abbr = self::generateAbbreviation($company);

        return [
            'doctype'            => 'Company',
            'company_name'       => $company->name,
            'abbr'               => $abbr,
            'default_currency'   => $company->currency ?: 'KWD',
            'country'            => 'Kuwait',
            'chart_of_accounts'  => 'Standard',
            'enable_perpetual_inventory' => 1,
        ];
    }

    /**
     * Generate a unique abbreviation from company code.
     * ERPNext uses this as suffix for all accounts: "Cash - FF"
     */
    public static function generateAbbreviation(Company $company): string
    {
        // Use code if short enough, otherwise take first letters
        $code = strtoupper($company->code ?? '');

        if (strlen($code) <= 5 && strlen($code) >= 2) {
            return $code;
        }

        // Take first letter of each word
        $words = preg_split('/[\s\-_]+/', $company->name);
        $abbr  = '';
        foreach ($words as $word) {
            if (!empty($word)) {
                $abbr .= strtoupper(mb_substr($word, 0, 1));
            }
        }

        return substr($abbr, 0, 5) ?: 'CO';
    }
}
