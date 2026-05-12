<?php

namespace App\Services\ErpNext\Mappers;

use App\Services\ErpNext\CompanyErpContext;

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
    public static function toErpNext(array $settlement, array $employee, ?CompanyErpContext $ctx = null): array
    {
        $ctx = $ctx ?? CompanyErpContext::fromGlobalConfig();
        return [
            'doctype'          => 'Payment Entry',
            'payment_type'     => 'Receive',
            'posting_date'     => $settlement['settlement_date'],
            'company'          => $ctx->company,
            'mode_of_payment'  => 'Cash',
            'party_type'       => 'Employee',
            'party'            => $employee['erp_id'] ?? '',
            'party_name'       => $employee['name_ar'] ?? $employee['name'],
            'paid_amount'      => $settlement['amount'],
            'received_amount'  => $settlement['amount'],
            'target_exchange_rate' => 1,
            'source_exchange_rate' => 1,

            'paid_from'        => $ctx->account('pending_cash'),
            'paid_to'          => $ctx->account('cash_in_hand'),

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
        array $approvedQuote,
        ?CompanyErpContext $ctx = null,
    ): array {
        $ctx = $ctx ?? CompanyErpContext::fromGlobalConfig();
        return [
            'doctype'          => 'Payment Entry',
            'payment_type'     => 'Pay',
            'posting_date'     => now()->toDateString(),
            'company'          => $ctx->company,
            'mode_of_payment'  => 'Cash',
            'party_type'       => 'Supplier',
            'party'            => $approvedQuote['garage_name'] ?? 'Unknown',
            'paid_amount'      => $approvedQuote['amount'] ?? $approvedQuote['quote_amount_kwd'] ?? 0,
            'received_amount'  => $approvedQuote['amount'] ?? $approvedQuote['quote_amount_kwd'] ?? 0,
            'target_exchange_rate' => 1,
            'source_exchange_rate' => 1,

            'paid_from'        => $ctx->account('cash_in_hand'),
            'paid_to'          => $ctx->account('accounts_payable'),

            'remarks'          => "دفعة صيانة - طلب #{$maintenanceRequest['id']}",

            'fleetops_maintenance_id' => $maintenanceRequest['id'],
            'docstatus' => 1,
        ];
    }
}
