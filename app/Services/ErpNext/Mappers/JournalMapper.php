<?php

namespace App\Services\ErpNext\Mappers;

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
    public static function violationToJournalEntry(array $violation, array $employee): array
    {
        return [
            'doctype'      => 'Journal Entry',
            'voucher_type' => 'Journal Entry',
            'posting_date' => $violation['violation_date'],
            'company'      => config('erpnext.company'),
            'user_remark'  => "مخالفة مرورية #{$violation['reference_number']} - سائق {$employee['name_ar'] ?? $employee['name']} - "
                . "سيارة {$violation['vehicle_id']} - {$violation['violation_type']} - {$violation['amount']} KWD",

            'accounts' => [
                [
                    'account'    => config('erpnext.accounts.violation_receivable'),
                    'party_type' => 'Employee',
                    'party'      => $employee['erp_id'] ?? '',
                    'debit_in_account_currency' => $violation['amount'],
                    'cost_center' => config('erpnext.cost_center'),
                ],
                [
                    'account'    => config('erpnext.accounts.cash_in_hand'),
                    'credit_in_account_currency' => $violation['amount'],
                    'cost_center' => config('erpnext.cost_center'),
                ],
            ],

            'fleetops_violation_id' => $violation['id'],
            'docstatus' => 1,
        ];
    }

    public static function maintenanceChargeToJournalEntry(
        array $maintenanceRequest,
        array $employee,
        float $chargeAmount
    ): array {
        return [
            'doctype'      => 'Journal Entry',
            'voucher_type' => 'Journal Entry',
            'posting_date' => now()->toDateString(),
            'company'      => config('erpnext.company'),
            'user_remark'  => "خصم صيانة على السائق {$employee['name_ar'] ?? $employee['name']} - "
                . "طلب صيانة #{$maintenanceRequest['id']} - {$chargeAmount} KWD",

            'accounts' => [
                [
                    'account'    => config('erpnext.accounts.violation_receivable'),
                    'party_type' => 'Employee',
                    'party'      => $employee['erp_id'] ?? '',
                    'debit_in_account_currency' => $chargeAmount,
                    'cost_center' => config('erpnext.cost_center'),
                ],
                [
                    'account'    => config('erpnext.accounts.maintenance_expense'),
                    'credit_in_account_currency' => $chargeAmount,
                    'cost_center' => config('erpnext.cost_center'),
                ],
            ],

            'fleetops_maintenance_id' => $maintenanceRequest['id'],
            'docstatus' => 1,
        ];
    }
}
