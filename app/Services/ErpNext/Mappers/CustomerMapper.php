<?php

namespace App\Services\ErpNext\Mappers;

use App\Services\ErpNext\CompanyErpContext;

/**
 * CustomerMapper
 *
 * Maps FleetOps Client → ERPNext Customer.
 * ERPNext Coverage: ✅ Direct use (CRM module).
 *
 * FleetOps clients are the delivery companies (Yalla Go, Keeta, etc.)
 * that the fleet company has contracts with.
 */
class CustomerMapper
{
    /**
     * Map a FleetOps Client model to ERPNext Customer fields.
     *
     * Client fields: id, name, name_ar, contact_person, phone, email,
     *                tax_number, is_active, erp_id, erp_synced_at, erp_sync_status
     */
    public static function toErpNext(array $client, ?CompanyErpContext $ctx = null): array
    {
        $ctx = $ctx ?? CompanyErpContext::fromGlobalConfig();

        return [
            'doctype'          => 'Customer',
            'customer_name'    => $client['name'],
            'customer_type'    => 'Company',
            'customer_group'   => 'Commercial',
            'territory'        => 'Kuwait',
            'default_currency' => $ctx->currency,
            'company'          => $ctx->company,

            // Custom fields (to link back to FleetOps)
            'fleetops_client_id' => $client['id'],
        ];
    }

    /**
     * Map ERPNext Customer back to FleetOps fields.
     */
    public static function fromErpNext(array $erpCustomer): array
    {
        return [
            'erp_id' => $erpCustomer['name'],
        ];
    }
}
