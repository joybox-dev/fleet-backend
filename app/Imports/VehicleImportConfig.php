<?php

namespace App\Imports;

/**
 * Vehicle Import Configuration
 */
class VehicleImportConfig
{
    public static function fields(): array
    {
        return [
            ['key' => 'plate_number',          'label' => 'رقم اللوحة',        'required' => true,  'type' => 'string'],
            ['key' => 'make',                  'label' => 'الشركة المصنعة',    'required' => false, 'type' => 'string'],
            ['key' => 'model',                 'label' => 'الموديل',           'required' => false, 'type' => 'string'],
            ['key' => 'year',                  'label' => 'سنة الصنع',         'required' => false, 'type' => 'integer'],
            ['key' => 'color',                 'label' => 'اللون',             'required' => false, 'type' => 'string'],
            ['key' => 'vin',                   'label' => 'رقم الشاصي',        'required' => false, 'type' => 'string'],
            ['key' => 'status',                'label' => 'الحالة',            'required' => false, 'type' => 'enum:working,available,maintenance,idle'],
            ['key' => 'odometer_km',           'label' => 'عداد الكيلومتر',    'required' => false, 'type' => 'integer'],
            ['key' => 'monthly_fuel_allowance','label' => 'بدل الوقود الشهري', 'required' => false, 'type' => 'numeric'],
            ['key' => 'insurance_expiry',      'label' => 'انتهاء التأمين',    'required' => false, 'type' => 'date'],
        ];
    }

    public static function validationRules(): array
    {
        return [
            'plate_number'          => 'required|string|max:20',
            'make'                  => 'nullable|string|max:100',
            'model'                 => 'nullable|string|max:100',
            'year'                  => 'nullable|integer|min:1990|max:2030',
            'color'                 => 'nullable|string|max:50',
            'vin'                   => 'nullable|string|max:50',
            'status'                => 'nullable|in:working,available,maintenance,idle',
            'odometer_km'           => 'nullable|integer|min:0',
            'monthly_fuel_allowance'=> 'nullable|numeric|min:0',
            'insurance_expiry'      => 'nullable|date',
        ];
    }

    public static function defaults(): array
    {
        return [
            'status'      => 'available',
            'odometer_km' => 0,
            'monthly_fuel_allowance' => 0,
        ];
    }

    public static function modelClass(): string
    {
        return \App\Models\Vehicle::class;
    }

    public static function uniqueKeys(): array
    {
        return ['plate_number'];
    }
}
