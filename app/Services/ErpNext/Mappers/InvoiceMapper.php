<?php

namespace App\Services\ErpNext\Mappers;

/**
 * InvoiceMapper
 *
 * Maps FleetOps DailyLog → ERPNext Sales Invoice.
 *
 * DailyLog fields: id, employee_id, vehicle_id, contract_id, log_date,
 *                  orders_count, orders_online, orders_cash, cash_collected,
 *                  rate_per_order, income_amount, erp_id, erp_sync_status
 *
 * Contract fields: id, client_id, contract_number, name, payment_type,
 *                  rate_per_order, fixed_monthly
 */
class InvoiceMapper
{
    public static function toErpNext(array $dailyLog, array $contract, array $vehicle): array
    {
        $orderCount = $dailyLog['orders_count'];
        $ratePerOrder = $dailyLog['rate_per_order'] ?? $contract['rate_per_order'] ?? 0;
        $totalIncome = $dailyLog['income_amount'] ?? ($orderCount * $ratePerOrder);

        return [
            'doctype'           => 'Sales Invoice',
            'customer'          => $contract['erp_id'] ?? "Client-{$contract['client_id']}",
            'posting_date'      => $dailyLog['log_date'],
            'due_date'          => $dailyLog['log_date'],
            'company'           => config('erpnext.company'),
            'currency'          => config('erpnext.default_currency'),
            'selling_price_list' => 'Standard Selling',

            'items' => [
                [
                    'item_code'     => 'DELIVERY-SERVICE',
                    'item_name'     => 'خدمة توصيل طلبات',
                    'description'   => "توصيل {$orderCount} طلب - سيارة {$vehicle['plate_number']} - تاريخ {$dailyLog['log_date']}",
                    'qty'           => $orderCount,
                    'rate'          => $ratePerOrder,
                    'amount'        => $totalIncome,
                    'income_account' => config('erpnext.accounts.delivery_income'),
                    'cost_center'   => config('erpnext.cost_center'),
                ],
            ],

            'remarks' => "FleetOps Daily Log #{$dailyLog['id']} | "
                . "Driver: {$dailyLog['employee_id']} | "
                . "Vehicle: {$vehicle['plate_number']} | "
                . "Contract: {$contract['id']} | "
                . "Orders: {$orderCount} × {$ratePerOrder} KWD = {$totalIncome} KWD",

            'fleetops_daily_log_id' => $dailyLog['id'],
            'fleetops_vehicle_id'   => $vehicle['id'],
            'fleetops_contract_id'  => $contract['id'],

            'docstatus' => 1,
        ];
    }
}
