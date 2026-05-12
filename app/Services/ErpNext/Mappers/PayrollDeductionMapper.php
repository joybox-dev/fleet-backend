<?php

namespace App\Services\ErpNext\Mappers;

use App\Services\ErpNext\CompanyErpContext;

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
        float $custody = 0,
        float $advances = 0,
        ?CompanyErpContext $ctx = null,
    ): array {
        $ctx = $ctx ?? CompanyErpContext::fromGlobalConfig();
        $totalDeductions = $violations + $maintenance + $custody + $advances;
        $period = "{$year}-{$month}";

        // Build line items — only include accounts with actual amounts
        $accounts = [];

        // ── DEBIT: Cash In Hand ──────────────────────────────
        // The company retained this cash from the driver's envelope
        $accounts[] = [
            'account'    => $ctx->account('cash_in_hand'),
            'debit_in_account_currency' => round($totalDeductions, 3),
            'credit_in_account_currency' => 0,
            'cost_center' => $ctx->costCenter,
            'user_remark' => "استرداد خصومات رواتب {$period} — إجمالي: {$totalDeductions} KWD",
        ];

        // ── CREDIT: Violation Receivable ─────────────────────
        if ($violations > 0) {
            $accounts[] = [
                'account'    => $ctx->account('violation_receivable'),
                'debit_in_account_currency' => 0,
                'credit_in_account_currency' => round($violations, 3),
                'cost_center' => $ctx->costCenter,
                'user_remark' => "تصفية مخالفات مرورية — {$period}",
            ];
        }

        // ── CREDIT: Maintenance Expense ──────────────────────
        if ($maintenance > 0) {
            $accounts[] = [
                'account'    => $ctx->account('maintenance_expense'),
                'debit_in_account_currency' => 0,
                'credit_in_account_currency' => round($maintenance, 3),
                'cost_center' => $ctx->costCenter,
                'user_remark' => "استرداد تكاليف صيانة من السائقين — {$period}",
            ];
        }

        // ── CREDIT: Custody Deduction (if applicable) ────────
        if ($custody > 0) {
            $accounts[] = [
                'account'    => $ctx->account('violation_receivable'), // Reuse receivable for custody
                'debit_in_account_currency' => 0,
                'credit_in_account_currency' => round($custody, 3),
                'cost_center' => $ctx->costCenter,
                'user_remark' => "خصم عُهد تالفة/مفقودة — {$period}",
            ];
        }

        // ── CREDIT: Advance Receivable (salary advance recovery) ──
        if ($advances > 0) {
            $accounts[] = [
                'account'    => $ctx->account('advance_receivable'),
                'debit_in_account_currency' => 0,
                'credit_in_account_currency' => round($advances, 3),
                'cost_center' => $ctx->costCenter,
                'user_remark' => "استرداد أقساط سلف موظفين — {$period}",
            ];
        }

        return [
            'doctype'      => 'Journal Entry',
            'voucher_type' => 'Journal Entry',
            'posting_date' => now()->toDateString(),
            'company'      => $ctx->company,
            'user_remark'  => "تسوية خصومات رواتب شهر {$month}/{$year} — "
                . "مخالفات: {$violations} KWD, صيانة: {$maintenance} KWD"
                . ($custody > 0 ? ", عُهد: {$custody} KWD" : '')
                . ($advances > 0 ? ", سلف: {$advances} KWD" : '')
                . " — إجمالي: {$totalDeductions} KWD",
            'accounts'     => $accounts,
            'docstatus'    => 1, // Submit immediately
        ];
    }
}
