<?php

namespace App\Services;

use App\Helpers\Iban;
use App\Models\Employee;

/**
 * The list a bank is handed to transfer a month's salaries: who, to which IBAN, how much.
 *
 * It is read off the approved consolidated month and proposes, for each driver, exactly what the
 * payment form proposes for him: the bank takes what is still owed to him, up to what is left of
 * his registered salary this month, and the rest is cash. So the sheet and the form can never
 * suggest two different figures for the same man, and a payment already recorded — bank or cash —
 * has already come off both.
 *
 * It is a proposal to pay, not a record of payment: nothing is written. A driver with no IBAN
 * cannot be on a bank's list, and is named apart with the amount that would have gone by bank.
 */
class BankSheetService
{
    /**
     * @return array{approved: bool, period: array<string, mixed>, company_name: ?string, generated_at: string, rows: array<int, array<string, mixed>>, missing_iban: array<int, array<string, mixed>>, totals: array<string, mixed>, left_out: array<string, int>}
     */
    public static function forMonth(int $companyId, int $year, int $month): array
    {
        $sheet = ConsolidatedSheetService::build($companyId, $year, $month);
        $drivers = $sheet['drivers'] ?? [];

        $employees = Employee::withoutGlobalScopes()->withTrashed()
            ->whereIn('id', array_map(fn (array $d) => (int) ($d['employee_id'] ?? 0), $drivers))
            ->get(['id', 'name', 'name_ar', 'employee_number', 'civil_id', 'iban', 'bank_name'])
            ->keyBy('id');

        $rows = [];
        $missing = [];
        $leftOut = ['paid' => 0, 'nothing_due' => 0, 'no_bank_salary' => 0, 'bank_side_sent' => 0];
        $cashAlongside = 0.0;

        foreach ($drivers as $driver) {
            $employee = $employees->get((int) ($driver['employee_id'] ?? 0));
            $suggested = round((float) ($driver['suggested_disbursement'] ?? 0), 3);
            $bankRoom = round((float) ($driver['bank_transferable'] ?? 0), 3);
            $amount = round(min($suggested, $bankRoom), 3);

            if ($amount <= 0) {
                $leftOut[match (true) {
                    $suggested <= 0 && ($driver['disbursement_status'] ?? '') === 'paid' => 'paid',
                    $suggested <= 0 => 'nothing_due',
                    (float) ($driver['bank_salary'] ?? 0) <= 0 => 'no_bank_salary',
                    default => 'bank_side_sent',
                }]++;

                continue;
            }

            $iban = Iban::normalize($employee?->iban);
            $row = [
                'employee_id' => (int) $driver['employee_id'],
                'employee_number' => $employee?->employee_number ?? ($driver['employee_number'] ?? null),
                'name' => $employee?->name ?? ($driver['employee_name'] ?? ''),
                'name_ar' => $employee?->name_ar,
                'civil_id' => $employee?->civil_id,
                'iban' => $iban ?: null,
                'iban_formatted' => $iban ? Iban::format($iban) : null,
                'bank_name' => $employee?->bank_name ?: Iban::bankName($iban),
                'amount' => $amount,
                // What the same payment leaves for cash, so the two sides are prepared together.
                'cash_part' => round(max(0.0, $suggested - $amount), 3),
                'bank_salary' => round((float) ($driver['bank_salary'] ?? 0), 3),
                'already_transferred' => round((float) ($driver['disbursed_bank'] ?? 0), 3),
            ];

            if ($iban === '' || ! Iban::isValid($iban)) {
                $missing[] = $row + ['problem' => $iban === '' ? 'لا IBAN في ملفه' : 'IBAN في ملفه غير صحيح'];

                continue;
            }

            $cashAlongside += $row['cash_part'];
            $rows[] = $row;
        }

        // Two men on one account is nearly always a typing mistake, and the bank would pay it twice.
        $counts = array_count_values(array_column($rows, 'iban'));
        foreach ($rows as $i => $row) {
            $rows[$i]['duplicate_iban'] = ($counts[$row['iban']] ?? 0) > 1;
        }

        usort($rows, fn (array $a, array $b) => strnatcasecmp((string) $a['name'], (string) $b['name']));
        usort($missing, fn (array $a, array $b) => strnatcasecmp((string) $a['name'], (string) $b['name']));

        return [
            'approved' => (bool) ($sheet['is_approved'] ?? false),
            'period' => ['year' => $year, 'month' => $month, 'label' => sprintf('%02d/%d', $month, $year)],
            'company_name' => $sheet['company_name'] ?? null,
            'generated_at' => now()->toDateTimeString(),
            'rows' => $rows,
            'missing_iban' => $missing,
            'totals' => [
                'count' => count($rows),
                'amount' => round(array_sum(array_column($rows, 'amount')), 3),
                'cash_alongside' => round($cashAlongside, 3),
                'missing_count' => count($missing),
                'missing_amount' => round(array_sum(array_column($missing, 'amount')), 3),
                'duplicate_ibans' => count(array_filter($rows, fn (array $row) => $row['duplicate_iban'])),
            ],
            'left_out' => $leftOut,
        ];
    }
}
