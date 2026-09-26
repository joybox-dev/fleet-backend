<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\OperationalAdvance;
use App\Models\OperationalAdvanceFund;
use App\Models\User;

/**
 * The cash float («رصيد») an administrative employee gives custodies out of.
 *
 * What he holds is what was handed to him, minus every custody he has given, plus what came
 * back to him on those custodies. A custody counts from the moment it exists — pending or
 * active — and keeps counting once completed, because what its holder spent is gone; only a
 * return on it puts cash back in the giver's hands. A rejected custody never counts. A take-back
 * by the company is a negative hand-over on the same ledger.
 */
class OperationalFundService
{
    /** Custody statuses that hold money out of a float. */
    public const COUNTED_STATUSES = ['pending', 'active', 'completed'];

    /**
     * The employee record behind a login in the current company, or null when there is none —
     * the owner's and the platform's logins have none, and their custodies come from the company.
     */
    public static function employeeFor(?User $user, ?int $companyId = null): ?Employee
    {
        if (! $user) {
            return null;
        }

        $companyId ??= (int) app('current_company_id');

        return Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id);
                if (! empty($user->email)) {
                    $q->orWhereHas('user', fn ($uq) => $uq->where('email', $user->email));
                }
            })
            ->first();
    }

    /** Whether a login sees every custody and every float in the company, or only its own. */
    public static function seesAll(?User $user): bool
    {
        return $user !== null && ($user->isSuperAdmin() || $user->can('op_advances.fund'));
    }

    /**
     * One employee's float.
     *
     * @return array{has_float: bool, funded: float, distributed: float, pending: float, active: float, returned: float, available: float, custodies: int}
     */
    public static function balanceFor(int $companyId, int $employeeId): array
    {
        $funds = OperationalAdvanceFund::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId);
        $hasFloat = (clone $funds)->exists();
        $funded = round((float) $funds->sum('amount'), 3);

        $given = OperationalAdvance::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('funded_by_employee_id', $employeeId)
            ->whereIn('status', self::COUNTED_STATUSES)
            ->withSum('returns', 'amount')
            ->get(['id', 'amount', 'status']);

        $distributed = round((float) $given->sum('amount'), 3);
        $returned = round((float) $given->sum('returns_sum_amount'), 3);

        return [
            'has_float' => $hasFloat,
            'funded' => $funded,
            'distributed' => $distributed,
            'pending' => round((float) $given->where('status', 'pending')->sum('amount'), 3),
            'active' => round((float) $given->where('status', 'active')->sum('amount'), 3),
            'returned' => $returned,
            'available' => round($funded - $distributed + $returned, 3),
            'custodies' => $given->count(),
        ];
    }

    /**
     * Every administrative employee of the company with his float, plus anyone who holds one
     * although he is no longer administrative, each with the hand-over history behind it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function balances(int $companyId): array
    {
        $fundedIds = OperationalAdvanceFund::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->distinct()
            ->pluck('employee_id');

        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('role_category', 'admin')->orWhereIn('id', $fundedIds))
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'role_category']);

        $history = OperationalAdvanceFund::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with('creator:id,name')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('employee_id');

        return $employees->map(function (Employee $employee) use ($companyId, $history) {
            $rows = $history->get($employee->id, collect());

            return array_merge([
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'employee_status' => $employee->status,
                'fundings' => $rows->map(fn (OperationalAdvanceFund $f) => [
                    'id' => $f->id,
                    'amount' => (float) $f->amount,
                    'date' => $f->date?->toDateString(),
                    'notes' => $f->notes,
                    'created_by' => $f->creator?->name,
                    'created_at' => $f->created_at?->toDateTimeString(),
                ])->values()->all(),
            ], self::balanceFor($companyId, $employee->id));
        })->values()->all();
    }
}
