<?php

namespace App\Services\ErpNext\Mappers;

use App\Services\ErpNext\CompanyErpContext;

/**
 * JournalMapper
 *
 * Maps FleetOps financial events → ERPNext Journal Entry.
 *
 * Violation fields: id, employee_id, vehicle_id, violation_date, violation_type,
 *                   reference_number, amount, is_driver_liable, erp_id
 */
class JournalMapper
{
    public static function violationToJournalEntry(array $violation, array $employee, ?CompanyErpContext $ctx = null): array
    {
        $ctx = $ctx ?? CompanyErpContext::fromGlobalConfig();
        return [
            'doctype'      => 'Journal Entry',
            'voucher_type' => 'Journal Entry',
            'posting_date' => $violation['violation_date'],
            'company'      => $ctx->company,
            'user_remark'  => "مخالفة مرورية #{$violation['reference_number']} - سائق " . ($employee['name_ar'] ?? $employee['name']) . " - "
                . "سيارة {$violation['vehicle_id']} - {$violation['violation_type']} - {$violation['amount']} KWD",

            'accounts' => [
                [
                    'account'    => $ctx->account('violation_receivable'),
                    'party_type' => 'Employee',
                    'party'      => $employee['erp_id'] ?? '',
                    'debit_in_account_currency' => $violation['amount'],
                    'cost_center' => $ctx->costCenter,
                ],
                [
                    'account'    => $ctx->account('cash_in_hand'),
                    'credit_in_account_currency' => $violation['amount'],
                    'cost_center' => $ctx->costCenter,
                ],
            ],

            'fleetops_violation_id' => $violation['id'],
            'docstatus' => 1,
        ];
    }

    public static function maintenanceChargeToJournalEntry(
        array $maintenanceRequest,
        array $employee,
        float $chargeAmount,
        ?CompanyErpContext $ctx = null,
    ): array {
        $ctx = $ctx ?? CompanyErpContext::fromGlobalConfig();
        return [
            'doctype'      => 'Journal Entry',
            'voucher_type' => 'Journal Entry',
            'posting_date' => now()->toDateString(),
            'company'      => $ctx->company,
            'user_remark'  => "خصم صيانة على السائق " . ($employee['name_ar'] ?? $employee['name']) . " - "
                . "طلب صيانة #{$maintenanceRequest['id']} - {$chargeAmount} KWD",

            'accounts' => [
                [
                    'account'    => $ctx->account('violation_receivable'),
                    'party_type' => 'Employee',
                    'party'      => $employee['erp_id'] ?? '',
                    'debit_in_account_currency' => $chargeAmount,
                    'cost_center' => $ctx->costCenter,
                ],
                [
                    'account'    => $ctx->account('maintenance_expense'),
                    'credit_in_account_currency' => $chargeAmount,
                    'cost_center' => $ctx->costCenter,
                ],
            ],

            'fleetops_maintenance_id' => $maintenanceRequest['id'],
            'docstatus' => 1,
        ];
    }
}
