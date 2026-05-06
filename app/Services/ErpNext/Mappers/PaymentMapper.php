<?php

namespace App\Services\ErpNext\Mappers;

/**
 * PaymentMapper
 *
 * Maps FleetOps CashSettlement → ERPNext Payment Entry.
 *
 * CashSettlement fields: id, employee_id, daily_log_id, received_by,
 *                        settlement_date, amount, receipt_photo_path, erp_id
 */
class PaymentMapper
{
    public static function toErpNext(array $settlement, array $employee): array
    {
        return [
            'doctype'          => 'Payment Entry',
            'payment_type'     => 'Receive',
            'posting_date'     => $settlement['settlement_date'],
            'company'          => config('erpnext.company'),
            'mode_of_payment'  => 'Cash',
            'party_type'       => 'Employee',
            'party'            => $employee['erp_id'] ?? '',
            'party_name'       => $employee['name_ar'] ?? $employee['name'],
            'paid_amount'      => $settlement['amount'],
            'received_amount'  => $settlement['amount'],
            'target_exchange_rate' => 1,
            'source_exchange_rate' => 1,

            'paid_from'        => config('erpnext.accounts.pending_cash'),
            'paid_to'          => config('erpnext.accounts.cash_in_hand'),

            'remarks'          => "تسوية كاش - FleetOps #{$settlement['id']} - "
                . "سائق: {$employee['name_ar'] ?? $employee['name']} - "
                . "المبلغ: {$settlement['amount']} KWD - "
                . "تاريخ: {$settlement['settlement_date']}",

            'fleetops_settlement_id' => $settlement['id'],
            'docstatus' => 1,
        ];
    }

    public static function maintenancePaymentToErpNext(
        array $maintenanceRequest,
        array $approvedQuote
    ): array {
        return [
            'doctype'          => 'Payment Entry',
            'payment_type'     => 'Pay',
            'posting_date'     => now()->toDateString(),
            'company'          => config('erpnext.company'),
            'mode_of_payment'  => 'Cash',
            'party_type'       => 'Supplier',
            'party'            => $approvedQuote['garage_name'] ?? 'Unknown',
            'paid_amount'      => $approvedQuote['amount'] ?? $approvedQuote['quote_amount_kwd'] ?? 0,
            'received_amount'  => $approvedQuote['amount'] ?? $approvedQuote['quote_amount_kwd'] ?? 0,
            'target_exchange_rate' => 1,
            'source_exchange_rate' => 1,

            'paid_from'        => config('erpnext.accounts.cash_in_hand'),
            'paid_to'          => config('erpnext.accounts.accounts_payable'),

            'remarks'          => "دفعة صيانة - طلب #{$maintenanceRequest['id']}",

            'fleetops_maintenance_id' => $maintenanceRequest['id'],
            'docstatus' => 1,
        ];
    }
}
