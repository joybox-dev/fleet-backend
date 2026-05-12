<?php

namespace App\Services\ErpNext\Mappers;

use App\Services\ErpNext\CompanyErpContext;

/**
 * VehicleMapper
 *
 * Maps FleetOps Vehicle → ERPNext Asset.
 *
 * Vehicle fields: id, plate_number, make, model, year, color, vin,
 *                 status, odometer_km, erp_id, erp_synced_at, erp_sync_status
 */
class VehicleMapper
{
    public static function toErpNext(array $vehicle, ?CompanyErpContext $ctx = null): array
    {
        $ctx = $ctx ?? CompanyErpContext::fromGlobalConfig();
        return [
            'doctype'               => 'Asset',
            'asset_name'            => "Vehicle {$vehicle['plate_number']}",
            'asset_category'        => 'Vehicles',
            'company'               => $ctx->company,
            'location'              => 'Kuwait',
            'purchase_date'         => $vehicle['created_at'] ?? now()->toDateString(),
            'is_existing_asset'     => 1,
            'available_for_use_date' => $vehicle['created_at'] ?? now()->toDateString(),
            'item_code'             => 'VEHICLE-SEDAN',

            'asset_owner'           => 'Company',

            'fleetops_vehicle_id'   => $vehicle['id'],
            'fleetops_plate_number' => $vehicle['plate_number'],
            'fleetops_make'         => $vehicle['make'] ?? '',
            'fleetops_model'        => $vehicle['model'] ?? '',
            'fleetops_year'         => $vehicle['year'] ?? '',
            'fleetops_color'        => $vehicle['color'] ?? '',
            'fleetops_vin'          => $vehicle['vin'] ?? '',
        ];
    }

    public static function fromErpNext(array $erpAsset): array
    {
        return [
            'erp_id' => $erpAsset['name'],
        ];
    }
}
