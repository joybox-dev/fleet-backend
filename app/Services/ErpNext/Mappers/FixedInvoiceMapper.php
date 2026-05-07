<?php

namespace App\Services\ErpNext\Mappers;

/**
 * FixedInvoiceMapper
 *
 * Maps a fixed-monthly FleetOps Contract → ERPNext Sales Invoice.
 *
 * Unlike per-order contracts (handled by InvoiceMapper from DailyLogs),
 * fixed contracts bill a flat `fixed_monthly` amount regardless of orders.
 * This mapper creates a single monthly Sales Invoice for the contract's
 * fixed rate, billed to the associated ERPNext Customer.
 */
class FixedInvoiceMapper
{
    /**
     * Map a fixed-monthly contract → ERPNext Sales Invoice payload.
     *
     * @param array  $contract  Contract model as array (with client relation)
     * @param array  $client    Client model as array (with erp_id)
     * @param string $year      Billing year
     * @param string $month     Billing month (zero-padded)
     * @return array ERPNext Sales Invoice payload
     */
    public static function toErpNext(array $contract, array $client, string $year, string $month): array
    {
        $amount   = (float) ($contract['fixed_monthly'] ?? 0);
        $period   = "{$year}-{$month}";
        $lastDay  = \Carbon\Carbon::createFromDate($year, (int) $month, 1)->endOfMonth()->toDateString();
        $customer = $client['erp_id'] ?? $client['name'] ?? "Client-{$contract['client_id']}";

        return [
            'doctype'           => 'Sales Invoice',
            'customer'          => $customer,
            'posting_date'      => $lastDay,
            'due_date'          => $lastDay,
            'company'           => config('erpnext.company'),
            'currency'          => config('erpnext.default_currency'),
            'selling_price_list' => 'Standard Selling',

            'items' => [
                [
                    'item_code'      => 'FLEET-MONTHLY-SERVICE',
                    'item_name'      => 'خدمة أسطول شهرية (عقد ثابت)',
                    'description'    => "عقد {$contract['name']} — {$contract['contract_number']} — "
                        . "خدمة أسطول شهرية لفترة {$period}",
                    'qty'            => 1,
                    'rate'           => $amount,
                    'amount'         => $amount,
                    'income_account' => config('erpnext.accounts.delivery_income'),
                    'cost_center'    => config('erpnext.cost_center'),
                ],
            ],

            'remarks' => "FleetOps Fixed Contract Invoice | "
                . "Contract: {$contract['contract_number']} ({$contract['name']}) | "
                . "Client: {$client['name']} | "
                . "Period: {$period} | "
                . "Amount: {$amount} KWD",

            'fleetops_contract_id' => $contract['id'],

            'docstatus' => 1, // Submit immediately
        ];
    }
}
