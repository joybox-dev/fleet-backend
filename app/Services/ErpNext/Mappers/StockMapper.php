<?php

namespace App\Services\ErpNext\Mappers;

use App\Services\ErpNext\CompanyErpContext;

/**
 * StockMapper
 *
 * Maps FleetOps Custody items → ERPNext Stock Entry.
 *
 * CustodyItem fields: id, employee_id, issued_by, item_type, item_description,
 *                     serial_number, value, issued_date, returned_date,
 *                     is_returned, return_condition, erp_id
 */
class StockMapper
{
    public static function custodyIssueToStockEntry(array $custodyItem, array $employee, ?CompanyErpContext $ctx = null): array
    {
        $ctx = $ctx ?? CompanyErpContext::fromGlobalConfig();
        return [
            'doctype'        => 'Stock Entry',
            'stock_entry_type' => 'Material Issue',
            'posting_date'   => $custodyItem['issued_date'],
            'company'        => $ctx->company,
            'remarks'        => "تسليم عهدة إلى " . ($employee['name_ar'] ?? $employee['name']) . " - "
                . "{$custodyItem['item_description']} - FleetOps #{$custodyItem['id']}",

            'items' => [
                [
                    'item_code'    => self::mapItemCode($custodyItem['item_type']),
                    'qty'          => 1,
                    's_warehouse'  => config('erpnext.warehouse'),
                    'serial_no'    => $custodyItem['serial_number'] ?? '',
                    'basic_rate'   => $custodyItem['value'] ?? 0,
                    'cost_center'  => $ctx->costCenter,
                ],
            ],

            'fleetops_custody_id' => $custodyItem['id'],
            'fleetops_employee_id' => $employee['id'],
        ];
    }

    public static function custodyReturnToStockEntry(array $custodyItem, array $employee, ?CompanyErpContext $ctx = null): array
    {
        $ctx = $ctx ?? CompanyErpContext::fromGlobalConfig();
        return [
            'doctype'        => 'Stock Entry',
            'stock_entry_type' => 'Material Receipt',
            'posting_date'   => $custodyItem['returned_date'] ?? now()->toDateString(),
            'company'        => $ctx->company,
            'remarks'        => "استرجاع عهدة من " . ($employee['name_ar'] ?? $employee['name']) . " - "
                . "{$custodyItem['item_description']} - FleetOps #{$custodyItem['id']}",

            'items' => [
                [
                    'item_code'    => self::mapItemCode($custodyItem['item_type']),
                    'qty'          => 1,
                    't_warehouse'  => config('erpnext.warehouse'),
                    'serial_no'    => $custodyItem['serial_number'] ?? '',
                    'basic_rate'   => $custodyItem['value'] ?? 0,
                    'cost_center'  => $ctx->costCenter,
                ],
            ],

            'fleetops_custody_id' => $custodyItem['id'],
            'fleetops_employee_id' => $employee['id'],
        ];
    }

    private static function mapItemCode(string $type): string
    {
        return match ($type) {
            'cash'     => 'CUSTODY-CASH',
            'clothing' => 'CUSTODY-CLOTHING',
            'phone'    => 'CUSTODY-PHONE',
            'sim_card' => 'CUSTODY-SIM',
            default    => 'CUSTODY-OTHER',
        };
    }
}
