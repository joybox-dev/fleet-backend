<?php

namespace App\Services;

use App\Models\OperationalAdvance;
use App\Models\User;
use App\Models\VehicleExpense;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paying out of an operational custody (owner, 2026-10-26): every driver expense, vehicle expense,
 * company expense, fine paid and maintenance approved names the custody its cash came from, and
 * comes off what is left in it. A custody is no longer spent on free text — what it paid for is a
 * real record, and that record lands where it always did: the driver's deduction, the contract, the
 * vehicle, the profit and loss.
 *
 * Who may pay from a custody: the employee who holds it, or a login with «إعطاء رصيد». More than
 * what is left is refused, and so is a payment dated before the custody was handed over.
 *
 * What is left = the custody − the free-text expenses recorded on it before this − the cash returned
 * − every record paid from it. Nothing is stored: deleting or editing a record frees its amount.
 */
class CustodySpendingService
{
    /** kind => [table, the column holding what was paid]. */
    public const SOURCES = [
        'driver_expense' => ['driver_expenses', 'amount'],
        'vehicle_expense' => ['vehicle_expenses', 'amount'],
        'company_expense' => ['company_expenses', 'amount'],
        'violation' => ['violations', 'amount'],
        'maintenance' => ['maintenance_records', 'actual_cost'],
    ];

    /** The day custodies began paying for everything; fines and repairs recorded before were paid the old way. */
    public const SINCE = '2026-10-26';

    /** Below this, what is left is nothing. */
    private const EPSILON = 0.0005;

    /**
     * Everything paid from these custodies, newest first.
     *
     * @param  array<int>  $advanceIds
     * @return Collection<int, object{operational_advance_id: int, kind: string, id: int, date: string, amount: float, label: ?string}>
     */
    public static function spendsOf(array $advanceIds): Collection
    {
        if ($advanceIds === []) {
            return collect();
        }

        $label = [
            'driver_expense' => 'expense_type',
            'vehicle_expense' => 'expense_type',
            'company_expense' => 'description',
            'violation' => 'violation_type',
            'maintenance' => 'garage_name',
        ];
        $date = [
            'driver_expense' => 'expense_date',
            'vehicle_expense' => 'expense_date',
            'company_expense' => 'expense_date',
            'violation' => 'paid_at',
            'maintenance' => 'paid_at',
        ];

        $rows = collect();
        foreach (self::SOURCES as $kind => [$table, $amount]) {
            $rows = $rows->concat(DB::table($table)
                ->whereIn('operational_advance_id', $advanceIds)
                ->whereNull('deleted_at')
                ->get(['id', 'operational_advance_id', DB::raw("{$date[$kind]} as date"), DB::raw("{$amount} as amount"), DB::raw("{$label[$kind]} as label")])
                ->map(fn ($r) => (object) [
                    'operational_advance_id' => (int) $r->operational_advance_id,
                    'kind' => $kind,
                    'id' => (int) $r->id,
                    'date' => (string) $r->date,
                    'amount' => round((float) $r->amount, 3),
                    // A vehicle expense stores a code ('fuel'); the screens show its word.
                    'label' => $kind === 'vehicle_expense' ? VehicleExpense::typeLabel($r->label) : $r->label,
                ]));
        }

        return $rows->sortByDesc('date')->values();
    }

    /**
     * What is left in a custody. $except leaves one record out — the one being edited, whose own
     * amount is about to be replaced.
     *
     * @param  array{0: string, 1: int}|null  $except  [kind, id]
     */
    public static function remaining(OperationalAdvance $advance, ?array $except = null): float
    {
        $linked = 0.0;
        foreach (self::SOURCES as $kind => [$table, $amount]) {
            $linked += (float) DB::table($table)
                ->where('operational_advance_id', $advance->id)
                ->whereNull('deleted_at')
                ->when($except && $except[0] === $kind, fn ($q) => $q->where('id', '!=', $except[1]))
                ->sum($amount);
        }

        $legacy = (float) DB::table('operational_advance_expenses')->where('operational_advance_id', $advance->id)->sum('amount');
        $returned = (float) DB::table('operational_advance_returns')->where('operational_advance_id', $advance->id)->sum('amount');

        return round((float) $advance->amount - $legacy - $returned - $linked, 3);
    }

    /** The employee who holds the custody, or a login with «إعطاء رصيد». */
    public static function maySpend(?User $user, OperationalAdvance $advance): bool
    {
        if (OperationalFundService::seesAll($user)) {
            return true;
        }
        $own = OperationalFundService::employeeFor($user, (int) $advance->company_id);

        return $own !== null && $own->id === (int) $advance->employee_id;
    }

    /**
     * The request pays from a custody this login may pay from — the one it holds, or any with
     * «إعطاء رصيد». That alone lets it record the payment, without the expense, fine or maintenance
     * permission: whoever holds the cash records what it went on («عهدتي», owner 2026-10-26).
     */
    public static function paysFromOwn(Request $request): bool
    {
        $advanceId = (int) $request->input('operational_advance_id');
        if (! $advanceId) {
            return false;
        }
        $advance = OperationalAdvance::where('company_id', (int) app('current_company_id'))->find($advanceId);

        return $advance !== null && self::maySpend($request->user(), $advance);
    }

    /**
     * The custody a payment comes from, once it is known the payment may come from it. Refuses —
     * on $field — a missing custody, another company's, one not in use, one the login may not
     * spend from, a payment dated before the custody was handed over, and more than is left.
     *
     * @param  array{0: string, 1: int}|null  $except  the record being edited, [kind, id]
     *
     * @throws ValidationException
     */
    public static function guard(?int $advanceId, float $amount, ?string $date, ?User $user, ?array $except = null, string $field = 'operational_advance_id'): OperationalAdvance
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$field => [$message]]);

        if (! $advanceId) {
            $fail('اختر العهدة التي انصرف منها المبلغ.');
        }

        $advance = OperationalAdvance::where('company_id', (int) app('current_company_id'))->find($advanceId);
        if (! $advance) {
            $fail('العهدة غير موجودة.');
        }

        // A custody that is used up stays payable for the record that used it up, being edited.
        $alreadyOnIt = $except && self::isPaidFrom($except, $advance->id);
        if ($advance->status !== 'active' && ! ($advance->status === 'completed' && $alreadyOnIt)) {
            $fail('العهدة ليست نشطة — لا يُصرف منها.');
        }

        if (! self::maySpend($user, $advance)) {
            $fail('لا يُسجَّل الصرف إلا على عهدتك، أو بصلاحية «إعطاء رصيد».');
        }

        if ($date && substr($date, 0, 10) < $advance->date->toDateString()) {
            $fail(sprintf('تاريخ الصرف قبل تاريخ استلام العهدة (%s).', $advance->date->toDateString()));
        }

        $left = self::remaining($advance, $except);
        if (round($amount, 3) > $left + self::EPSILON) {
            $fail(sprintf('الباقي في العهدة %s د.ك ولا يكفي لهذا المبلغ (%s د.ك).', number_format(max(0, $left), 3), number_format($amount, 3)));
        }

        return $advance;
    }

    /**
     * The custody a record being saved is paid from: the one the request names, else the one it was
     * already paid from. A record from before custodies paid for everything, edited without naming
     * one, stays without — history is not rewritten. Returns null only in that case.
     *
     * @throws ValidationException
     */
    public static function resolve(Request $request, float $amount, ?string $date, string $kind, ?Model $record = null): ?int
    {
        $named = $request->filled('operational_advance_id') ? (int) $request->input('operational_advance_id') : null;
        $current = $record?->operational_advance_id ? (int) $record->operational_advance_id : null;

        if (! $named && $record && ! $current) {
            return null;
        }

        return self::guard($named ?? $current, $amount, $date, $request->user(), $record ? [$kind, (int) $record->getKey()] : null)->id;
    }

    /**
     * A custody that is used up is closed; one that has money again after a record was edited or
     * deleted is open again.
     */
    public static function refreshStatus(?int $advanceId): void
    {
        if (! $advanceId) {
            return;
        }
        $advance = OperationalAdvance::withoutGlobalScopes()->find($advanceId);
        if (! $advance || ! in_array($advance->status, ['active', 'completed'], true)) {
            return;
        }

        $left = self::remaining($advance);
        if ($advance->status === 'active' && $left <= self::EPSILON) {
            $advance->update(['status' => 'completed']);
        } elseif ($advance->status === 'completed' && $left > self::EPSILON) {
            $advance->update(['status' => 'active']);
        }
    }

    /** @param  array{0: string, 1: int}  $record */
    private static function isPaidFrom(array $record, int $advanceId): bool
    {
        [$table] = self::SOURCES[$record[0]];

        return DB::table($table)->where('id', $record[1])->where('operational_advance_id', $advanceId)->exists();
    }
}
