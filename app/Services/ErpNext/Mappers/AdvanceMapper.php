<?php

namespace App\Services\ErpNext\Mappers;

use App\Models\SalaryAdvance;
use App\Services\ErpNext\CompanyErpContext;

/**
 * AdvanceMapper
 *
 * Maps a salary advance → ERPNext Journal Entry.
 *
 * Accounting Logic:
 * When FleetOps issues a salary advance (cash loan to employee):
 *   Debit:  Employee Advance Receivable (Asset ↑) — company is owed money
 *   Credit: Cash In Hand (Asset ↓) — company gave out cash
 *
 * When payroll deducts the installment (handled by PayrollDeductionMapper):
 *   Debit:  Cash In Hand (Asset ↑) — company recovered cash
 *   Credit: Employee Advance Receivable (Asset ↓) — receivable cleared
 */
class AdvanceMapper
{
    /**
     * Build a Journal Entry for a new salary advance.
     */
    public static function toJournalEntry(SalaryAdvance $advance, ?CompanyErpContext $ctx = null): array
    {
        $ctx = $ctx ?? CompanyErpContext::fromGlobalConfig();

        $employeeName = $advance->employee->name ?? "Employee #{$advance->employee_id}";
        $amount       = round((float) $advance->amount, 3);

        return [
            'doctype'      => 'Journal Entry',
            'voucher_type' => 'Journal Entry',
            'posting_date' => $advance->advance_date->toDateString(),
            'company'      => $ctx->company,
            'user_remark'  => "سلفة للموظف {$employeeName} — {$amount} د.ك"
                . ($advance->reason ? " — السبب: {$advance->reason}" : ''),
            'accounts' => [
                // Debit: Employee Advance Receivable (company is owed)
                [
                    'account'    => $ctx->account('advance_receivable'),
                    'debit_in_account_currency'  => $amount,
                    'credit_in_account_currency' => 0,
                    'cost_center' => $ctx->costCenter,
                    'user_remark' => "سلفة — {$employeeName} — {$amount} KWD",
                ],
                // Credit: Cash In Hand (company gave out cash)
                [
                    'account'    => $ctx->account('cash_in_hand'),
                    'debit_in_account_currency'  => 0,
                    'credit_in_account_currency' => $amount,
                    'cost_center' => $ctx->costCenter,
                    'user_remark' => "صرف سلفة نقدية — {$employeeName}",
                ],
            ],
            'docstatus' => 1, // Submit immediately
        ];
    }
}
