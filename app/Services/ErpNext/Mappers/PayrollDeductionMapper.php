<?php

namespace App\Services\ErpNext\Mappers;

/**
 * PayrollDeductionMapper
 *
 * Maps aggregated payroll deductions → ERPNext Journal Entry.
 *
 * Accounting Logic:
 * When FleetOps approves a payroll batch, deductions (violations, maintenance,
 * custody) were internally absorbed from the driver's cash envelope.
 * This means the company effectively "recovered" that cash.
 *
 * To reflect this in ERPNext's books:
 *   Debit:  Cash In Hand (the money the company kept)
 *   Credit: Violation Receivable (clears the outstanding receivable)
 *   Credit: Maintenance Expense (reimburses the expense account)
 *
 * The Journal Entry zeroes-out the receivables and expenses in ERPNext's
 * ledger without touching the Salary Slip (which shows only bank salary).
 */
class PayrollDeductionMapper
{
    /**
     * Build a single multi-row Journal Entry for all batch deductions.
     *
     * @param string $year       Payroll year (e.g., "2026")
     * @param string $month      Payroll month (e.g., "04")
     * @param float  $violations Total driver-liable violations absorbed
     * @param float  $maintenance Total driver-charged maintenance absorbed
     * @param float  $custody    Total custody damage/loss charges absorbed
     * @return array ERPNext Journal Entry payload
     */
    public static function toJournalEntry(
        string $year,
        string $month,
        float $violations,
        float $maintenance,
        float $custody = 0
    ): array {
        $totalDeductions = $violations + $maintenance + $custody;
        $period = "{$year}-{$month}";

        // Build line items — only include accounts with actual amounts
        $accounts = [];

        // ── DEBIT: Cash In Hand ──────────────────────────────
        // The company retained this cash from the driver's envelope
        $accounts[] = [
            'account'    => config('erpnext.accounts.cash_in_hand'),
            'debit_in_account_currency' => round($totalDeductions, 3),
            'credit_in_account_currency' => 0,
            'cost_center' => config('erpnext.cost_center'),
            'user_remark' => "استرداد خصومات رواتب {$period} — إجمالي: {$totalDeductions} KWD",
        ];

        // ── CREDIT: Violation Receivable ─────────────────────
        if ($violations > 0) {
            $accounts[] = [
                'account'    => config('erpnext.accounts.violation_receivable'),
                'debit_in_account_currency' => 0,
                'credit_in_account_currency' => round($violations, 3),
                'cost_center' => config('erpnext.cost_center'),
                'user_remark' => "تصفية مخالفات مرورية — {$period}",
            ];
        }

        // ── CREDIT: Maintenance Expense ──────────────────────
        if ($maintenance > 0) {
            $accounts[] = [
                'account'    => config('erpnext.accounts.maintenance_expense'),
                'debit_in_account_currency' => 0,
                'credit_in_account_currency' => round($maintenance, 3),
                'cost_center' => config('erpnext.cost_center'),
                'user_remark' => "استرداد تكاليف صيانة من السائقين — {$period}",
            ];
        }

        // ── CREDIT: Custody Deduction (if applicable) ────────
        if ($custody > 0) {
            $accounts[] = [
                'account'    => config('erpnext.accounts.violation_receivable'), // Reuse receivable for custody
                'debit_in_account_currency' => 0,
                'credit_in_account_currency' => round($custody, 3),
                'cost_center' => config('erpnext.cost_center'),
                'user_remark' => "خصم عُهد تالفة/مفقودة — {$period}",
            ];
        }

        return [
            'doctype'      => 'Journal Entry',
            'voucher_type' => 'Journal Entry',
            'posting_date' => now()->toDateString(),
            'company'      => config('erpnext.company'),
            'user_remark'  => "تسوية خصومات رواتب شهر {$month}/{$year} — "
                . "مخالفات: {$violations} KWD, صيانة: {$maintenance} KWD"
                . ($custody > 0 ? ", عُهد: {$custody} KWD" : '')
                . " — إجمالي: {$totalDeductions} KWD",
            'accounts'     => $accounts,
            'docstatus'    => 1, // Submit immediately
        ];
    }
}
