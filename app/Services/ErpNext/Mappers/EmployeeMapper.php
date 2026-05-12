<?php

namespace App\Services\ErpNext\Mappers;

use App\Services\ErpNext\CompanyErpContext;

/**
 * EmployeeMapper
 *
 * Maps FleetOps Employee → ERPNext Employee.
 *
 * Employee fields: id, name, name_ar, employee_number, nationality, civil_id,
 *                  phone, gender, date_of_birth, date_of_joining, employee_type,
 *                  status, pay_type, official_salary, actual_salary, rate_per_order,
 *                  has_end_of_service, erp_id, erp_synced_at, erp_sync_status
 */
class EmployeeMapper
{
    public static function toErpNext(array $employee, ?CompanyErpContext $ctx = null): array
    {
        $ctx = $ctx ?? CompanyErpContext::fromGlobalConfig();
        return [
            'doctype'             => 'Employee',
            'employee_name'       => $employee['name_ar'] ?? $employee['name'],
            'first_name'          => self::extractFirstName($employee['name_ar'] ?? $employee['name']),
            'last_name'           => self::extractLastName($employee['name_ar'] ?? $employee['name']),
            'company'             => $ctx->company,
            'status'              => self::mapStatus($employee['status']),
            'gender'              => ucfirst($employee['gender'] ?? 'Male'),
            'date_of_birth'       => $employee['date_of_birth'] ?? null,
            'date_of_joining'     => $employee['date_of_joining'],
            'cell_phone'          => $employee['phone'] ?? '',
            'nationality'         => $employee['nationality'] ?? '',

            'payroll_cost_center' => $ctx->costCenter,
            'mode_of_payment'     => 'Bank',

            'fleetops_employee_id' => $employee['id'],
            'fleetops_employee_number' => $employee['employee_number'] ?? '',
        ];
    }

    public static function toSalaryStructureAssignment(array $employee, string $erpEmployeeName, ?CompanyErpContext $ctx = null): array
    {
        $ctx = $ctx ?? CompanyErpContext::fromGlobalConfig();
        return [
            'doctype'           => 'Salary Structure Assignment',
            'employee'          => $erpEmployeeName,
            'salary_structure'  => 'FleetOps Basic Structure',
            'from_date'         => $employee['date_of_joining'],
            'base'              => $employee['official_salary'],
            'company'           => $ctx->company,
        ];
    }

    private static function mapStatus(string $fleetOpsStatus): string
    {
        return match ($fleetOpsStatus) {
            'active'      => 'Active',
            'on_leave'    => 'Active',
            'probation'   => 'Active',
            'onboarding'  => 'Active',
            'inactive'    => 'Inactive',
            'terminated'  => 'Left',
            'clearance'   => 'Left',
            default       => 'Active',
        };
    }

    public static function fromErpNext(array $erpEmployee): array
    {
        return [
            'erp_id' => $erpEmployee['name'],
        ];
    }

    private static function extractFirstName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName), 2);
        return $parts[0];
    }

    private static function extractLastName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName), 2);
        return $parts[1] ?? '';
    }
}
