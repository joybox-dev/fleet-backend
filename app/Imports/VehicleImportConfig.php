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
            ['key' => 'insurance_expiry',      'label' => 'انتهاء التأمين الإلزامي', 'required' => false, 'type' => 'date'],
            ['key' => 'comprehensive_insurance_expiry', 'label' => 'انتهاء التأمين الشامل', 'required' => false, 'type' => 'date'],
            ['key' => 'food_authority_license_expiry', 'label' => 'انتهاء رخصة هيئة الغذاء', 'required' => false, 'type' => 'date'],
            ['key' => 'next_service_due',      'label' => 'تاريخ الخدمة القادمة',  'required' => false, 'type' => 'date'],
            ['key' => 'ownership_type',        'label' => 'نوع الملكية',       'required' => false, 'type' => 'enum:owned,rented,installment,asset'],
            ['key' => 'last_oil_change_km',    'label' => 'عداد آخر غيار زيت',   'required' => false, 'type' => 'integer'],
            ['key' => 'oil_change_interval_km', 'label' => 'مسافة غيار الزيت كم', 'required' => false, 'type' => 'integer'],
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
            'comprehensive_insurance_expiry' => 'nullable|date',
            'food_authority_license_expiry' => 'nullable|date',
            'next_service_due'      => 'nullable|date',
            'ownership_type'        => 'nullable|in:owned,rented,installment,asset',
            'last_oil_change_km'    => 'nullable|integer|min:0',
            'oil_change_interval_km' => 'nullable|integer|min:0',
        ];
    }

    public static function defaults(): array
    {
        return [
            'status'      => 'available',
            'odometer_km' => 0,
            'monthly_fuel_allowance' => 0,
            'ownership_type' => 'owned',
            'last_oil_change_km' => null,
            'oil_change_interval_km' => null,
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
