<?php

namespace App\Services\ErpNext\Mappers;

use App\Services\ErpNext\CompanyErpContext;

/**
 * PayrollMapper
 *
 * Maps FleetOps salary calculation → ERPNext Payroll.
 *
 * Only the OFFICIAL salary (الراتب الأساسي) is synced to ERPNext.
 * The INTERNAL salary stays in FleetOps.
 */
class PayrollMapper
{
    /**
     * Create a Salary Slip in ERPNext for OFFICIAL salary only.
     */
    public static function toOfficialSalarySlip(array $employee, string $year, string $month, ?CompanyErpContext $ctx = null): array
    {
        $ctx = $ctx ?? CompanyErpContext::fromGlobalConfig();
        $startDate = "{$year}-{$month}-01";
        $endDate = date('Y-m-t', strtotime($startDate));

        return [
            'doctype'          => 'Salary Slip',
            'employee'         => $employee['erp_id'] ?? '',
            'company'          => $ctx->company,
            'posting_date'     => $endDate,
            'start_date'       => $startDate,
            'end_date'         => $endDate,
            'payroll_frequency' => config('erpnext.payroll.payroll_frequency'),

            'fleetops_payroll_month' => "{$year}-{$month}",
        ];
    }

    /**
     * Build the INTERNAL salary breakdown (FleetOps only, NOT synced).
     */
    public static function calculateInternalSalary(
        array $employee,
        int $totalOrders,
        array $ledgerEntries
    ): array {
        $base = match ($employee['pay_type']) {
            'fixed'     => (float)$employee['official_salary'],
            'per_order' => $totalOrders * (float)($employee['rate_per_order'] ?? 0),
            'mixed'     => (float)$employee['official_salary'] + ($totalOrders * (float)($employee['rate_per_order'] ?? 0)),
            default     => 0,
        };

        $additions = 0;
        $deductions = 0;
        $breakdown = [];

        foreach ($ledgerEntries as $entry) {
            $amount = $entry['amount'] ?? 0;
            $type = $entry['entry_type'] ?? 'unknown';

            if ($amount > 0) {
                $additions += $amount;
                $breakdown['additions'][] = [
                    'type' => $type,
                    'amount' => $amount,
                    'description' => $entry['description'] ?? '',
                ];
            } else {
                $deductions += abs($amount);
                $breakdown['deductions'][] = [
                    'type' => $type,
                    'amount' => abs($amount),
                    'description' => $entry['description'] ?? '',
                ];
            }
        }

        $internalTotal = $base + $additions - $deductions;
        $officialSalary = (float)$employee['official_salary'];
        $cashComponent = max(0, $internalTotal - $officialSalary);

        return [
            'employee_id'       => $employee['id'],
            'employee_name'     => $employee['name_ar'] ?? $employee['name'],
            'pay_type'          => $employee['pay_type'],

            'base_salary'       => $base,
            'total_orders'      => $totalOrders,
            'rate_per_order'    => $employee['rate_per_order'] ?? null,

            'total_additions'   => $additions,
            'total_deductions'  => $deductions,

            'official_salary'   => $officialSalary,
            'internal_total'    => $internalTotal,
            'cash_component'    => $cashComponent,
            'bank_component'    => $officialSalary,

            'breakdown'         => $breakdown,
            'has_end_of_service' => $employee['has_end_of_service'],
        ];
    }
}
