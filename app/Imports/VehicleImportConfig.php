<?php

namespace App\Imports;

use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Support\Collection;

/**
 * Vehicle Import Configuration
 */
class VehicleImportConfig
{
    public static function fields(): array
    {
        // The company's own vehicle types, offered by name in the template's dropdown. A name that
        // holds a comma would split the list in two, so it is left out of the dropdown (and still
        // accepted when typed).
        $typeNames = self::vehicleTypes()
            ->map(fn (VehicleType $type) => $type->name_ar ?: $type->name)
            ->reject(fn (string $name) => str_contains($name, ','))
            ->values();

        return [
            ['key' => 'plate_number',          'label' => 'رقم اللوحة',        'required' => true,  'type' => 'string'],
            ['key' => 'vehicle_type',          'label' => 'نوع المركبة',       'required' => false, 'type' => $typeNames->isEmpty() ? 'string' : 'enum:'.$typeNames->implode(',')],
            ['key' => 'make',                  'label' => 'الشركة المصنعة',    'required' => false, 'type' => 'string'],
            ['key' => 'model',                 'label' => 'الموديل',           'required' => false, 'type' => 'string'],
            ['key' => 'year',                  'label' => 'سنة الصنع',         'required' => false, 'type' => 'integer'],
            ['key' => 'color',                 'label' => 'اللون',             'required' => false, 'type' => 'string'],
            ['key' => 'vin',                   'label' => 'رقم الشاصي',        'required' => false, 'type' => 'string'],
            ['key' => 'status',                'label' => 'الحالة',            'required' => false, 'type' => 'enum:working,available,maintenance,idle'],
            ['key' => 'odometer_km',           'label' => 'عداد الكيلومتر',    'required' => false, 'type' => 'integer'],
            ['key' => 'monthly_fuel_allowance', 'label' => 'بدل الوقود الشهري', 'required' => false, 'type' => 'numeric'],
            ['key' => 'insurance_expiry',      'label' => 'انتهاء التأمين الإلزامي', 'required' => false, 'type' => 'date'],
            ['key' => 'comprehensive_insurance_expiry', 'label' => 'انتهاء التأمين الشامل', 'required' => false, 'type' => 'date'],
            ['key' => 'food_authority_license_expiry', 'label' => 'انتهاء رخصة هيئة الغذاء', 'required' => false, 'type' => 'date'],
            ['key' => 'next_service_due',      'label' => 'تاريخ الخدمة القادمة',  'required' => false, 'type' => 'date'],
            ['key' => 'ownership_type',        'label' => 'نوع الملكية',       'required' => false, 'type' => 'enum:owned,rented,installment,asset'],
            ['key' => 'rental_price',          'label' => 'الإيجار الشهري',    'required' => false, 'type' => 'numeric'],
            ['key' => 'installment_price',     'label' => 'القسط الشهري',      'required' => false, 'type' => 'numeric'],
            ['key' => 'last_oil_change_km',    'label' => 'عداد آخر غيار زيت',   'required' => false, 'type' => 'integer'],
            ['key' => 'oil_change_interval_km', 'label' => 'مسافة غيار الزيت كم', 'required' => false, 'type' => 'integer'],
            ['key' => 'notes',                 'label' => 'ملاحظات',           'required' => false, 'type' => 'string'],
        ];
    }

    public static function validationRules(): array
    {
        return [
            'plate_number' => 'required|string|max:20',
            // Matched against the company's types by resolve(), in Arabic or in English.
            'vehicle_type' => 'nullable|string|max:100',
            'make' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'year' => 'nullable|integer|min:1990|max:2030',
            'color' => 'nullable|string|max:50',
            'vin' => 'nullable|string|max:50',
            'status' => 'nullable|in:working,available,maintenance,idle',
            'odometer_km' => 'nullable|integer|min:0',
            'monthly_fuel_allowance' => 'nullable|numeric|min:0',
            'insurance_expiry' => 'nullable|date',
            'comprehensive_insurance_expiry' => 'nullable|date',
            'food_authority_license_expiry' => 'nullable|date',
            'next_service_due' => 'nullable|date',
            'ownership_type' => 'nullable|in:owned,rented,installment,asset',
            'rental_price' => 'nullable|numeric|min:0',
            'installment_price' => 'nullable|numeric|min:0',
            'last_oil_change_km' => 'nullable|integer|min:0',
            'oil_change_interval_km' => 'nullable|integer|min:0',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    /**
     * They belong to a NEW record only: an existing vehicle being updated never receives one, or
     * a file that says nothing about status would put a vehicle in the shop back on the road.
     */
    public static function defaults(): array
    {
        return [
            'status' => 'available',
            'odometer_km' => 0,
            'monthly_fuel_allowance' => 0,
            'ownership_type' => 'owned',
            'last_oil_change_km' => null,
            'oil_change_interval_km' => null,
        ];
    }

    public static function modelClass(): string
    {
        return Vehicle::class;
    }

    public static function uniqueKeys(): array
    {
        return ['plate_number'];
    }

    /**
     * Fields that may not belong to two vehicles of the same company — the same rule the vehicle
     * form enforces.
     *
     * @return array<int, string>
     */
    public static function uniqueWithinCompany(): array
    {
        return ['vin'];
    }

    /** The column that names a record in a message about it. */
    public static function displayField(): string
    {
        return 'plate_number';
    }

    /**
     * How a stored value reads to a person, where the stored form is not readable: a vehicle type
     * is kept as an id and shown by its name. Null leaves the value as it is.
     */
    public static function display(string $key, mixed $value, int $companyId): ?string
    {
        if ($key !== 'vehicle_type_id') {
            return null;
        }

        $type = self::vehicleTypes($companyId)->firstWhere('id', (int) $value);

        return $type ? ($type->name_ar ?: $type->name) : null;
    }

    /**
     * The file names a vehicle type in words; the table stores its id.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, array<int, string>>}
     */
    public static function resolve(array $data, int $companyId): array
    {
        $given = trim((string) ($data['vehicle_type'] ?? ''));
        unset($data['vehicle_type']);

        if ($given === '') {
            return [$data, []];
        }

        $types = self::vehicleTypes($companyId);
        $match = $types->first(fn (VehicleType $type) => in_array(
            mb_strtolower($given),
            [mb_strtolower((string) $type->name_ar), mb_strtolower((string) $type->name)],
            true
        ));

        if (! $match) {
            $known = $types->map(fn (VehicleType $type) => $type->name_ar ?: $type->name)->implode('، ');

            return [$data, ['vehicle_type' => ["نوع المركبة «{$given}» غير معرَّف في النظام. الأنواع المتاحة: {$known}"]]];
        }

        $data['vehicle_type_id'] = (int) $match->id;

        return [$data, []];
    }

    /**
     * @return Collection<int, VehicleType>
     */
    private static function vehicleTypes(?int $companyId = null): Collection
    {
        $companyId ??= app()->bound('current_company_id') ? (int) app('current_company_id') : null;

        if (! $companyId) {
            return collect();
        }

        return VehicleType::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->get();
    }
}
