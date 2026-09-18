<?php

namespace App\Imports;

use App\Helpers\Iban;
use App\Models\Employee;
use App\Rules\Iban as IbanRule;

/**
 * Employee Import Configuration
 *
 * Defines required/optional fields, validation rules, and display labels
 * for the flexible column mapping UI.
 */
class EmployeeImportConfig
{
    /**
     * Get the field definitions for employee import.
     * Each field has: key, label (ar), required, rules, type
     */
    public static function fields(): array
    {
        return [
            ['key' => 'name',              'label' => 'الاسم (إنجليزي)',  'required' => true,  'type' => 'string'],
            ['key' => 'name_ar',           'label' => 'الاسم (عربي)',     'required' => false, 'type' => 'string'],
            ['key' => 'employee_number',   'label' => 'رقم الموظف',       'required' => true,  'type' => 'string'],
            ['key' => 'role_category',     'label' => 'الفئة (سائق / إداري)', 'required' => false, 'type' => 'enum:driver,admin'],
            ['key' => 'status',            'label' => 'الحالة',           'required' => false, 'type' => 'enum:active,probation,on_leave,inactive'],
            ['key' => 'nationality',       'label' => 'الجنسية',          'required' => false, 'type' => 'string'],
            ['key' => 'civil_id',          'label' => 'الرقم المدني',     'required' => false, 'type' => 'string'],
            ['key' => 'phone',             'label' => 'الهاتف',           'required' => false, 'type' => 'string'],
            ['key' => 'gender',            'label' => 'الجنس',            'required' => false, 'type' => 'enum:male,female'],
            ['key' => 'date_of_birth',     'label' => 'تاريخ الميلاد',    'required' => false, 'type' => 'date'],
            ['key' => 'date_of_joining',   'label' => 'تاريخ الالتحاق',   'required' => false, 'type' => 'date'],
            ['key' => 'employee_type',     'label' => 'نوع الموظف',       'required' => true,  'type' => 'enum:overseas,local_transfer'],
            ['key' => 'pay_type',          'label' => 'نظام الدفع',       'required' => true,  'type' => 'enum:fixed,per_order,hybrid'],
            ['key' => 'official_salary',   'label' => 'الراتب الرسمي',    'required' => true,  'type' => 'numeric'],
            ['key' => 'iban',              'label' => 'رقم IBAN',         'required' => false, 'type' => 'iban'],
            ['key' => 'bank_name',         'label' => 'البنك',            'required' => false, 'type' => 'string'],
            ['key' => 'actual_salary',     'label' => 'الراتب الفعلي',    'required' => false, 'type' => 'numeric'],
            ['key' => 'rate_per_order',    'label' => 'عمولة الطلب',      'required' => false, 'type' => 'numeric'],
            ['key' => 'target_orders_monthly', 'label' => 'تارغت الطلبات الشهري', 'required' => false, 'type' => 'integer'],
            ['key' => 'base_commission_rate', 'label' => 'العمولة الأساسية للطلب', 'required' => false, 'type' => 'numeric'],
            ['key' => 'premium_commission_rate', 'label' => 'العمولة الإضافية المميزة', 'required' => false, 'type' => 'numeric'],
            ['key' => 'residence_expiry',        'label' => 'انتهاء الإقامة',       'required' => false, 'type' => 'date'],
            ['key' => 'driving_license_expiry',  'label' => 'انتهاء رخصة القيادة', 'required' => false, 'type' => 'date'],
            ['key' => 'work_permit_expiry',      'label' => 'انتهاء إذن العمل',     'required' => false, 'type' => 'date'],
            ['key' => 'health_card_expiry',      'label' => 'انتهاء الكرت الصحي',   'required' => false, 'type' => 'date'],
            ['key' => 'notes',             'label' => 'ملاحظات',          'required' => false, 'type' => 'string'],
        ];
    }

    /**
     * Get Laravel validation rules for each field.
     */
    public static function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'employee_number' => 'required|string|max:50',
            'role_category' => 'nullable|in:driver,admin',
            'status' => 'nullable|in:active,probation,on_leave,inactive',
            'nationality' => 'nullable|string|max:100',
            'civil_id' => 'nullable|string|max:30',
            'phone' => 'nullable|string|max:30',
            'gender' => 'nullable|in:male,female',
            'date_of_birth' => 'nullable|date',
            'date_of_joining' => 'nullable|date',
            'employee_type' => 'required|in:overseas,local_transfer',
            'pay_type' => 'required|in:fixed,per_order,hybrid',
            'official_salary' => 'required|numeric|min:0',
            'iban' => ['nullable', 'string', new IbanRule],
            'bank_name' => 'nullable|string|max:100',
            'actual_salary' => 'nullable|numeric|min:0',
            'rate_per_order' => 'nullable|numeric|min:0',
            'target_orders_monthly' => 'nullable|integer|min:0',
            'base_commission_rate' => 'nullable|numeric|min:0',
            'premium_commission_rate' => 'nullable|numeric|min:0',
            'residence_expiry' => 'nullable|date',
            'driving_license_expiry' => 'nullable|date',
            'work_permit_expiry' => 'nullable|date',
            'health_card_expiry' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    /**
     * Default values for fields not mapped or empty.
     * They belong to a NEW record only: an existing employee being updated never receives one,
     * or a file that says nothing about status would reactivate everybody it touches.
     */
    public static function defaults(): array
    {
        return [
            'status' => 'active',
            'role_category' => 'driver',
            'actual_salary' => 0,
            'rate_per_order' => 0,
            'gender' => 'male',
            'employee_type' => 'overseas',
            'target_orders_monthly' => null,
            'base_commission_rate' => 0.000,
            'premium_commission_rate' => 0.000,
        ];
    }

    /**
     * The Eloquent model class.
     */
    public static function modelClass(): string
    {
        return Employee::class;
    }

    /**
     * Unique key(s) for duplicate detection.
     */
    public static function uniqueKeys(): array
    {
        return ['employee_number'];
    }

    /**
     * Fields that may not belong to two employees of the same company — the same rule the
     * employee form enforces, so the importer cannot create a record the form would refuse.
     *
     * @return array<int, string>
     */
    public static function uniqueWithinCompany(): array
    {
        return ['civil_id', 'phone', 'iban'];
    }

    /** The column that names a record in a message about it. */
    public static function displayField(): string
    {
        return 'name';
    }

    /**
     * How a stored value reads to a person, where the stored form is not readable. Every employee
     * column is stored as it is written, so nothing is translated.
     */
    public static function display(string $key, mixed $value, int $companyId): ?string
    {
        return null;
    }

    /**
     * Turn what the file says into what the table stores. A Kuwaiti IBAN names its own bank, so a
     * file that carries the IBAN column alone still fills in the bank.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, array<int, string>>}
     */
    public static function resolve(array $data, int $companyId): array
    {
        if (! empty($data['iban']) && empty($data['bank_name']) && Iban::isValid($data['iban'])) {
            $bank = Iban::bankName($data['iban']);
            if ($bank !== null) {
                $data['bank_name'] = $bank;
            }
        }

        return [$data, []];
    }
}
