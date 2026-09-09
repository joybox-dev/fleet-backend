<?php

namespace App\Services;

use App\Models\ConsolidatedPayrollRun;
use App\Models\PayrollDisbursement;

/**
 * The one running balance each driver has with the company, and the one rule that moves it.
 *
 * A month enters the balance when its consolidated sheet is approved — that is what fixes the net —
 * and money leaves it when a payment is recorded against that month. Nothing else touches it: no
 * month is assumed paid because it is old, and no figure is netted off on screen without a record.
 * A month approved and never paid stays owed to the driver until somebody records the payment; a
 * month that ended as a debt stays against him until a later month absorbs it.
 *
 * Months chain in APPROVAL order (the run id), not calendar order: the owner closes months out of
 * turn, and a month closed late takes whatever balance is standing when it runs. See
 * EmployeeLedgerService::withCarryForward.
 */
class PayrollBalanceService
{
    /** What one closed month leaves on the balance once what was paid against it is taken off. */
    public static function contribution(float $net, float $disbursed): float
    {
        return round($net - $disbursed, 3);
    }

    /**
     * Every driver's balance as it stood before a given approval — or as it stands now, when no
     * run is named. Positive: the company owes him. Negative: he owes the company.
     *
     * @return array<int, array{balance: float, from: ?string, months: array<int, array{run_id: int, label: string, net: float, disbursed: float, remaining: float}>}>
     */
    public static function openingBalances(int $companyId, ?int $beforeRunId = null): array
    {
        $runs = ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->when($beforeRunId !== null, fn ($q) => $q->where('id', '<', $beforeRunId))
            ->orderBy('id')
            ->get(['id', 'year', 'month', 'snapshot_data']);

        $paid = PayrollDisbursement::withoutGlobalScopes()
            ->whereIn('consolidated_run_id', $runs->pluck('id'))
            ->selectRaw('consolidated_run_id, employee_id, SUM(bank_amount + cash_amount) AS total')
            ->groupBy('consolidated_run_id', 'employee_id')
            ->get()
            ->groupBy('consolidated_run_id')
            ->map(fn ($rows) => $rows->pluck('total', 'employee_id'));

        $balances = [];

        foreach ($runs as $run) {
            $label = sprintf('%02d/%d', $run->month, $run->year);
            $paidHere = $paid->get($run->id, collect());

            foreach ($run->snapshot_data['drivers'] ?? [] as $driver) {
                $employeeId = (int) ($driver['employee_id'] ?? 0);
                if ($employeeId === 0) {
                    continue;
                }

                $net = round((float) ($driver['final_net_payout'] ?? 0), 3);
                $disbursed = round((float) ($paidHere[$employeeId] ?? 0), 3);
                $remaining = self::contribution($net, $disbursed);

                $entry = $balances[$employeeId] ?? ['balance' => 0.0, 'from' => null, 'months' => []];
                $entry['balance'] = round($entry['balance'] + $remaining, 3);
                $entry['from'] = $label;
                $entry['months'][] = [
                    'run_id' => (int) $run->id,
                    'label' => $label,
                    'net' => $net,
                    'disbursed' => $disbursed,
                    'remaining' => $remaining,
                ];
                $balances[$employeeId] = $entry;
            }
        }

        return $balances;
    }
}
