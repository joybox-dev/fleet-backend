<?php

namespace App\Imports;

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
            ['key' => 'nationality',       'label' => 'الجنسية',          'required' => false, 'type' => 'string'],
            ['key' => 'civil_id',          'label' => 'الرقم المدني',     'required' => false, 'type' => 'string'],
            ['key' => 'phone',             'label' => 'الهاتف',           'required' => false, 'type' => 'string'],
            ['key' => 'gender',            'label' => 'الجنس',            'required' => false, 'type' => 'enum:male,female'],
            ['key' => 'date_of_birth',     'label' => 'تاريخ الميلاد',    'required' => false, 'type' => 'date'],
            ['key' => 'date_of_joining',   'label' => 'تاريخ الالتحاق',   'required' => false, 'type' => 'date'],
            ['key' => 'employee_type',     'label' => 'نوع الموظف',       'required' => true,  'type' => 'enum:overseas,local_transfer'],
            ['key' => 'pay_type',          'label' => 'نظام الدفع',       'required' => true,  'type' => 'enum:fixed,per_order,hybrid'],
            ['key' => 'official_salary',   'label' => 'الراتب الرسمي',    'required' => true,  'type' => 'numeric'],
            ['key' => 'actual_salary',     'label' => 'الراتب الفعلي',    'required' => false, 'type' => 'numeric'],
            ['key' => 'rate_per_order',    'label' => 'عمولة الطلب',      'required' => false, 'type' => 'numeric'],
            ['key' => 'target_orders_monthly', 'label' => 'تارغت الطلبات الشهري', 'required' => false, 'type' => 'integer'],
            ['key' => 'base_commission_rate', 'label' => 'العمولة الأساسية للطلب', 'required' => false, 'type' => 'numeric'],
            ['key' => 'premium_commission_rate', 'label' => 'العمولة الإضافية المميزة', 'required' => false, 'type' => 'numeric'],
        ];
     }
 
     /**
      * Get Laravel validation rules for each field.
      */
     public static function validationRules(): array
     {
         return [
             'name'            => 'required|string|max:255',
             'name_ar'         => 'nullable|string|max:255',
             'employee_number' => 'required|string|max:50',
             'nationality'     => 'nullable|string|max:100',
             'civil_id'        => 'nullable|string|max:30',
             'phone'           => 'nullable|string|max:30',
             'gender'          => 'nullable|in:male,female',
             'date_of_birth'   => 'nullable|date',
             'date_of_joining' => 'nullable|date',
             'employee_type'   => 'required|in:overseas,local_transfer',
             'pay_type'        => 'required|in:fixed,per_order,hybrid',
             'official_salary' => 'required|numeric|min:0',
             'actual_salary'   => 'nullable|numeric|min:0',
             'rate_per_order'  => 'nullable|numeric|min:0',
             'target_orders_monthly'  => 'nullable|integer|min:0',
             'base_commission_rate'   => 'nullable|numeric|min:0',
             'premium_commission_rate'=> 'nullable|numeric|min:0',
         ];
     }
 
     /**
      * Default values for fields not mapped or empty.
      */
     public static function defaults(): array
     {
         return [
             'status'        => 'active',
             'actual_salary' => 0,
             'rate_per_order' => 0,
             'gender'        => 'male',
             'employee_type' => 'overseas',
             'target_orders_monthly'  => null,
             'base_commission_rate'   => 0.000,
             'premium_commission_rate'=> 0.000,
         ];
     }

    /**
     * The Eloquent model class.
     */
    public static function modelClass(): string
    {
        return \App\Models\Employee::class;
    }

    /**
     * Unique key(s) for duplicate detection.
     */
    public static function uniqueKeys(): array
    {
        return ['employee_number'];
    }
}
