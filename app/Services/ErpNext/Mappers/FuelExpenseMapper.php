<?php

namespace App\Services\ErpNext\Mappers;

/**
 * FuelExpenseMapper
 *
 * Maps consolidated monthly fuel allowance → ERPNext Journal Entry.
 *
 * Accounting Logic:
 * When FleetOps approves payroll, drivers with assigned vehicles receive
 * a fuel_allowance as part of their internal cash envelope.
 * This is a company EXPENSE that must be recorded in ERPNext.
 *
 * Journal Entry:
 *   Debit:  Fuel / Transportation Expense (company spent on fuel)
 *   Credit: Cash In Hand (paid out from company cash)
 */
class FuelExpenseMapper
{
    /**
     * Build a Journal Entry for the month's total fuel allowance.
     *
     * @param string $year   Payroll year
     * @param string $month  Payroll month (zero-padded)
     * @param float  $amount Total fuel allowance for all drivers
     * @return array ERPNext Journal Entry payload
     */
    public static function toJournalEntry(string $year, string $month, float $amount): array
    {
        $period = "{$year}-{$month}";

        return [
            'doctype'      => 'Journal Entry',
            'voucher_type' => 'Journal Entry',
            'posting_date' => now()->toDateString(),
            'company'      => config('erpnext.company'),
            'user_remark'  => "بدل وقود شهر {$month}/{$year} — إجمالي: {$amount} KWD"
                . " — مُصرف ضمن رواتب الموظفين (كاش)",

            'accounts' => [
                // ── DEBIT: Fuel Expense ──────────────────────
                // Company incurred this expense for driver transportation
                [
                    'account'    => config('erpnext.accounts.fuel_expense'),
                    'debit_in_account_currency'  => round($amount, 3),
                    'credit_in_account_currency' => 0,
                    'cost_center' => config('erpnext.cost_center'),
                    'user_remark' => "بدل وقود — {$period}",
                ],
                // ── CREDIT: Cash In Hand ─────────────────────
                // Paid out from company cash as part of the salary envelope
                [
                    'account'    => config('erpnext.accounts.cash_in_hand'),
                    'debit_in_account_currency'  => 0,
                    'credit_in_account_currency' => round($amount, 3),
                    'cost_center' => config('erpnext.cost_center'),
                    'user_remark' => "صرف بدل وقود — {$period}",
                ],
            ],

            'docstatus' => 1, // Submit immediately
        ];
    }
}
