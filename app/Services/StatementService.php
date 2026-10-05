<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientCollection;
use App\Models\ConsolidatedPayrollRun;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\DriverOpeningBalance;
use App\Models\Employee;
use App\Models\KeetaInvoice;
use App\Models\OperationalAdvance;
use App\Models\OperationalAdvanceFund;
use App\Models\PayrollDisbursement;
use Carbon\Carbon;

/**
 * One statement of account for any party — a driver's pay, an employee's custody, a client — as
 * the accountant reads one: an opening balance, every movement dated with its details in a debit
 * or a credit column, a running balance, and the closing figure.
 *
 * Nothing here decides a balance. Each account is read from the rule that already keeps it:
 * - Pay (drivers): PayrollBalanceService — the declared opening, then every approved consolidated
 *   month's net, less what was paid against it. The statement shows the month as its parts (what
 *   was earned, each deduction, the hand adjustments) and each payment on its own date, so its
 *   closing balance is the balance the payroll screens show, to the fils.
 * - Custody (administrative employees): what he was handed (float top-ups, custodies received) less
 *   what he accounted for (custodies he gave from his float, expenses, returns) — OperationalFundService.
 * - Client: each month's bill per contract (Keeta's own statement where imported, the contract's
 *   price list otherwise, computed from the daily log — not a frozen invoice) less the collections.
 *
 * Signs: debit raises what the party owes the company, credit raises what the company owes the
 * party. The balance is shown from the party's side: «له» when the company owes, «عليه» otherwise.
 */
class StatementService
{
    /**
     * @return array<string, mixed>
     */
    public static function forEmployeePay(Employee $employee, ?string $from, ?string $to): array
    {
        $companyId = (int) $employee->company_id;
        $lines = [];

        $opening = DriverOpeningBalance::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_id', $employee->id)->first();
        if ($opening && abs((float) $opening->amount) >= 0.0005) {
            $amount = round((float) $opening->amount, 3);
            $lines[] = self::line($opening->balance_date?->toDateString() ?? '1970-01-01', DriverOpeningBalance::LABEL, $amount < 0 ? -$amount : 0.0, $amount > 0 ? $amount : 0.0, 'opening');
        }

        $runs = ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('status', 'approved')->orderBy('id')
            ->get(['id', 'year', 'month', 'snapshot_data']);
        $payments = PayrollDisbursement::withoutGlobalScopes()
            ->where('employee_id', $employee->id)->whereIn('consolidated_run_id', $runs->pluck('id'))
            ->get();

        foreach ($runs as $run) {
            $row = collect($run->snapshot_data['drivers'] ?? [])->firstWhere('employee_id', $employee->id);
            if (! $row) {
                continue;
            }
            $label = sprintf('%02d/%d', $run->month, $run->year);
            $date = Carbon::create($run->year, $run->month, 1)->endOfMonth()->toDateString();
            $gross = round((float) ($row['gross_contract_earnings'] ?? 0), 3);
            $adjustments = round((float) ($row['manual_adjustments_total'] ?? $row['manual_adjustments'] ?? 0), 3);
            $net = round((float) ($row['final_net_payout'] ?? 0), 3);
            $contracts = collect($row['contracts_worked'] ?? [])->pluck('contract_name')->filter()->implode(' + ');

            $lines[] = self::line($date, "مستحق شهر {$label}".($contracts ? " — {$contracts}" : ''), 0.0, $gross, 'earning', $label);
            if (abs($adjustments) >= 0.0005) {
                $lines[] = self::line($date, "تسويات يدوية شهر {$label}", $adjustments < 0 ? -$adjustments : 0.0, $adjustments > 0 ? $adjustments : 0.0, 'adjustment', $label);
            }
            $taken = 0.0;
            foreach ($row['deduction_items'] ?? [] as $item) {
                $amount = round((float) ($item['amount'] ?? 0), 3);
                if ($amount <= 0) {
                    continue;
                }
                $taken = round($taken + $amount, 3);
                $lines[] = self::line($date, 'خصم: '.($item['label'] ?? 'خصم')." ({$label})", $amount, 0.0, 'deduction', $label);
            }
            // The month's lines add up to its net, whatever the snapshot itemised.
            $rest = round($gross + $adjustments - $taken - $net, 3);
            if (abs($rest) >= 0.0005) {
                $lines[] = self::line($date, "خصومات أخرى شهر {$label}", max(0.0, $rest), max(0.0, -$rest), 'deduction', $label);
            }

            foreach ($payments->where('consolidated_run_id', $run->id) as $payment) {
                $parts = array_filter([
                    (float) $payment->bank_amount > 0 ? 'بنكي '.number_format((float) $payment->bank_amount, 3) : null,
                    (float) $payment->cash_amount > 0 ? 'نقدي '.number_format((float) $payment->cash_amount, 3) : null,
                ]);
                $lines[] = self::line($payment->paid_at?->toDateString() ?? $date, "صرف راتب شهر {$label} (".implode(' + ', $parts).')', round($payment->total(), 3), 0.0, 'payment', $label);
            }
        }

        $notes = ['الأشهر التي لم يُعتمد كشفها المجمّع بعد لا تدخل الرصيد.'];
        $pendingCash = round((float) DailyLog::withoutGlobalScopes()->whereNull('deleted_at')->where('employee_id', $employee->id)->sum('cash_pending'), 3);
        if ($pendingCash > 0) {
            $notes[] = 'كاش معلّق بذمته '.number_format($pendingCash, 3).' د.ك — للعرض فقط، لا يدخل الرصيد ويُسوّى من شاشة تسوية الكاش.';
        }

        return self::assemble(['type' => 'employee', 'account' => 'pay', 'id' => $employee->id, 'name' => $employee->name, 'number' => $employee->employee_number], $lines, $from, $to, $notes, 'credit');
    }

    /**
     * @return array<string, mixed>
     */
    public static function forEmployeeCustody(Employee $employee, ?string $from, ?string $to): array
    {
        $companyId = (int) $employee->company_id;
        $lines = [];

        OperationalAdvanceFund::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_id', $employee->id)->get()
            ->each(function ($fund) use (&$lines) {
                $amount = round((float) $fund->amount, 3);
                $lines[] = $amount >= 0
                    ? self::line($fund->date?->toDateString() ?? (string) $fund->date, 'رصيد مُسلَّم له'.($fund->notes ? " — {$fund->notes}" : ''), $amount, 0.0, 'float')
                    : self::line($fund->date?->toDateString() ?? (string) $fund->date, 'سحب من رصيده'.($fund->notes ? " — {$fund->notes}" : ''), 0.0, -$amount, 'float');
            });

        // Custodies he received and what he accounted for against them.
        OperationalAdvance::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_id', $employee->id)
            ->whereIn('status', ['active', 'completed'])->with(['expenses', 'returns'])->get()
            ->each(function (OperationalAdvance $advance) use (&$lines) {
                $date = Carbon::parse($advance->date)->toDateString();
                $lines[] = self::line($date, 'عهدة مستلمة: '.$advance->reason, round((float) $advance->amount, 3), 0.0, 'custody');
                foreach ($advance->expenses as $expense) {
                    $lines[] = self::line(Carbon::parse($expense->date)->toDateString(), 'مصروف من العهدة: '.$expense->description, 0.0, round((float) $expense->amount, 3), 'custody_expense');
                }
                foreach ($advance->returns as $return) {
                    $lines[] = self::line(Carbon::parse($return->date)->toDateString(), 'إرجاع من العهدة: '.$advance->reason, 0.0, round((float) $return->amount, 3), 'custody_return');
                }
            });

        // Custodies he gave out of his float, and what came back to it.
        OperationalAdvance::withoutGlobalScopes()->where('company_id', $companyId)->where('funded_by_employee_id', $employee->id)
            ->whereIn('status', OperationalFundService::COUNTED_STATUSES)->with(['returns', 'employee:id,name'])->get()
            ->each(function (OperationalAdvance $advance) use (&$lines) {
                $date = Carbon::parse($advance->date)->toDateString();
                $lines[] = self::line($date, 'عهدة سلّمها من رصيده لـ '.($advance->employee?->name ?? '—').': '.$advance->reason, 0.0, round((float) $advance->amount, 3), 'float_out');
                foreach ($advance->returns as $return) {
                    $lines[] = self::line(Carbon::parse($return->date)->toDateString(), 'عاد إلى رصيده من عهدة '.($advance->employee?->name ?? '—'), round((float) $return->amount, 3), 0.0, 'float_in');
                }
            });

        return self::assemble(['type' => 'employee', 'account' => 'custody', 'id' => $employee->id, 'name' => $employee->name, 'number' => $employee->employee_number], $lines, $from, $to,
            ['«عليه» = ما يحمله من نقد الشركة: رصيده التشغيلي وعهده المفتوحة.'], 'debit');
    }

    /**
     * @return array<string, mixed>
     */
    public static function forClient(Client $client, ?string $from, ?string $to): array
    {
        $companyId = (int) $client->company_id;
        app()->instance('current_company_id', $companyId);
        $lines = [];

        $contracts = Contract::withoutGlobalScopes()->whereNull('deleted_at')->where('company_id', $companyId)->where('client_id', $client->id)->get()->keyBy('id');
        $today = Carbon::now()->startOfMonth();

        // The month's bill is read exactly as the profitability report reads it.
        DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereIn('contract_id', $contracts->keys())
            ->with('vehicle:id,vehicle_type_id')
            ->get(['id', 'employee_id', 'vehicle_id', 'contract_id', 'log_date', 'orders_count', 'zone', 'notes'])
            ->groupBy(fn ($log) => $log->contract_id.'|'.substr((string) $log->log_date, 0, 7))
            ->each(function ($logs, $key) use (&$lines, $contracts, $today) {
                [$contractId, $ym] = explode('|', $key);
                $contract = $contracts[(int) $contractId];
                [$y, $m] = array_map('intval', explode('-', $ym));
                $amount = round((float) ContractRevenueService::forContractMonth($contract, $logs)['revenue'], 3);
                if (abs($amount) < 0.0005) {
                    return;
                }
                // A month Keeta settles is its statement once imported, and until then an estimate.
                $keetaMonth = KeetaRevenueService::appliesTo($contract, $y, $m);
                $statement = $keetaMonth
                    && KeetaInvoice::withoutGlobalScopes()->where('contract_id', $contract->id)->where('year', $y)->where('month', $m)->exists();
                $open = Carbon::create($y, $m, 1)->gte($today);
                $note = match (true) {
                    $statement => ' (كشف كيتا)',
                    $keetaMonth => ' (تقدير — كشف كيتا للشهر لم يُستورد بعد)',
                    $open => ' (الشهر جارٍ — تقديري)',
                    default => '',
                };
                $lines[] = self::line(Carbon::create($y, $m, 1)->endOfMonth()->toDateString(), sprintf('مطالبة شهر %02d/%d — %s%s', $m, $y, $contract->name, $note), $amount, 0.0, 'invoice');
            });

        ClientCollection::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('contract_id', $contracts->pluck('id'))->get()
            ->each(function ($c) use (&$lines, $contracts) {
                $contract = $contracts->firstWhere('id', $c->contract_id);
                $lines[] = self::line(Carbon::parse($c->date)->toDateString(), 'تحصيل — '.($contract?->name ?? '').($c->notes ? " — {$c->notes}" : ''), 0.0, round((float) $c->amount, 3), 'collection');
            });

        return self::assemble(['type' => 'client', 'account' => 'receivable', 'id' => $client->id, 'name' => $client->name_ar ?: $client->name, 'number' => null], $lines, $from, $to, [
            'المطالبة الشهرية محسوبة من السجل اليومي وأسعار العقد (أو من كشف كيتا المستورد)، وليست فاتورة مجمّدة: تعديل السجل أو الأسعار يغيّرها.',
            'لا رصيد افتتاحي للعميل في النظام: يبدأ الكشف من أول شهر عمل مسجّل.',
        ], 'debit');
    }

    /**
     * @param  array<string, mixed>  $party
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, string>  $notes
     * @param  string  $positive  the side a positive balance means: 'credit' (the company owes) or 'debit' (the party owes)
     * @return array<string, mixed>
     */
    private static function assemble(array $party, array $lines, ?string $from, ?string $to, array $notes, string $positive): array
    {
        usort($lines, fn ($a, $b) => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);

        $opening = 0.0;
        $shown = [];
        $balance = 0.0;
        foreach ($lines as $line) {
            $delta = $positive === 'credit' ? $line['credit'] - $line['debit'] : $line['debit'] - $line['credit'];
            if ($from && $line['date'] < $from) {
                $opening = round($opening + $delta, 3);
                $balance = $opening;

                continue;
            }
            if ($to && $line['date'] > $to) {
                continue;
            }
            $balance = round($balance + $delta, 3);
            unset($line['order']);
            $shown[] = $line + ['balance' => $balance, 'side' => self::side($balance, $positive)];
        }

        $closing = $shown === [] ? $opening : end($shown)['balance'];

        return [
            'party' => $party,
            'period' => ['from' => $from, 'to' => $to],
            'balance_meaning' => $positive === 'credit' ? 'له = مستحق له على الشركة · عليه = مستحق عليه للشركة' : 'عليه = مستحق عليه للشركة · له = مستحق له',
            'opening' => ['amount' => $opening, 'side' => self::side($opening, $positive)],
            'lines' => $shown,
            'totals' => [
                'debit' => round(array_sum(array_column($shown, 'debit')), 3),
                'credit' => round(array_sum(array_column($shown, 'credit')), 3),
            ],
            'closing' => ['amount' => $closing, 'side' => self::side($closing, $positive)],
            'notes' => $notes,
        ];
    }

    private static function side(float $balance, string $positive): string
    {
        if (abs($balance) < 0.0005) {
            return '—';
        }

        return ($balance > 0) === ($positive === 'credit') ? 'له' : 'عليه';
    }

    private const ORDER = [
        'opening' => 0, 'float' => 1, 'invoice' => 1, 'custody' => 2, 'earning' => 2, 'adjustment' => 3,
        'deduction' => 4, 'custody_expense' => 5, 'float_out' => 5, 'custody_return' => 6, 'float_in' => 6,
        'collection' => 7, 'payment' => 8,
    ];

    /** @return array<string, mixed> */
    private static function line(string $date, string $details, float $debit, float $credit, string $kind, ?string $ref = null): array
    {
        return [
            'date' => substr($date, 0, 10),
            'details' => $details,
            'debit' => round($debit, 3),
            'credit' => round($credit, 3),
            'kind' => $kind,
            'ref' => $ref,
            'order' => self::ORDER[$kind] ?? 9,
        ];
    }
}
