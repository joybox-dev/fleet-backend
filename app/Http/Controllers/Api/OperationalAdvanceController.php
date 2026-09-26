<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OperationalAdvance;
use App\Models\OperationalAdvanceFund;
use App\Services\OperationalFundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Operational custodies («العهد التشغيلية»): cash handed to an administrative employee to spend
 * and account for. Since 2026-09-22 the cash can come from a float («رصيد») another
 * administrative employee holds, and each employee sees only the custodies that are his — the
 * ones he received and the ones he gave — unless he holds the «إعطاء رصيد» permission, which
 * makes him the custodian of all of them.
 */
class OperationalAdvanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = (int) app('current_company_id');
        $user = $request->user();

        $own = null;
        if (! OperationalFundService::seesAll($user)) {
            $own = OperationalFundService::employeeFor($user, $companyId);
            if (! $own) {
                return response()->json([]);
            }
        }

        $employeeId = $request->employee_id;

        $advances = OperationalAdvance::with(['employee:id,name', 'fundedBy:id,name', 'approver:id,name', 'expenses.contract:id,name', 'returns'])
            ->where('company_id', $companyId)
            ->when($own, fn ($q) => $q->where(fn ($scope) => $scope->where('employee_id', $own->id)->orWhere('funded_by_employee_id', $own->id)))
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(function (OperationalAdvance $advance) {
                $totalExpenses = $advance->expenses->sum('amount');
                $totalReturns = $advance->returns->sum('amount');
                $advance->remaining_balance = max(0, $advance->amount - $totalExpenses - $totalReturns);

                return $advance;
            });

        return response()->json($advances);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = (int) app('current_company_id');
        $user = $request->user();

        $canCreateActive = $user && ($user->isSuperAdmin() || $user->role === 'admin' || $user->can('op_advances.create') || $user->can('op_advances.edit'));
        $canRequestPending = $user && ($user->role === 'operator' || $user->can('op_advances.view'));

        if (! $canCreateActive && ! $canRequestPending) {
            return response()->json(['message' => 'غير مصرح لك بإضافة عهدة تشغيلية جديدة.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'amount' => 'required|numeric|min:0.001',
            'date' => 'required|date',
            'reason' => 'required|string|max:255',
        ]);

        $validated['company_id'] = $companyId;
        $validated['created_by'] = $user->id;

        // The giver's own float funds the custody when he holds one; the company funds it
        // otherwise, as every custody was funded before floats existed.
        $giver = OperationalFundService::employeeFor($user, $companyId);
        $float = $giver ? OperationalFundService::balanceFor($companyId, $giver->id) : null;

        if ($float && $float['has_float']) {
            if ((int) $validated['employee_id'] === $giver->id) {
                return response()->json([
                    'message' => 'لا تُعطى عهدة لنفسك من رصيدك.',
                    'errors' => ['employee_id' => ['لا تُعطى عهدة لنفسك من رصيدك.']],
                ], 422);
            }

            if (round((float) $validated['amount'], 3) > $float['available'] + 0.0005) {
                $message = sprintf('رصيدك المتاح %s د.ك ولا يكفي لهذه العهدة.', number_format($float['available'], 3));

                return response()->json(['message' => $message, 'errors' => ['amount' => [$message]]], 422);
            }

            $validated['funded_by_employee_id'] = $giver->id;
        }

        if ($canCreateActive) {
            $validated['status'] = 'active';
            $validated['approved_by'] = $user->id;
        } else {
            $validated['status'] = 'pending';
        }

        $advance = OperationalAdvance::create($validated);

        return response()->json($advance->load(['employee:id,name', 'fundedBy:id,name']), 201);
    }

    public function approve(Request $request, $id): JsonResponse
    {
        // Who may approve is `permission:op_advances.edit,op_advances.fund` on the route. Matching
        // the role NAME «admin» here refused every company-defined role, whatever it was granted.
        $advance = OperationalAdvance::findOrFail($id);
        if ($advance->status !== 'pending') {
            return response()->json(['message' => 'هذه السلفة ليست في حالة معلقة.'], 422);
        }

        $advance->update([
            'status' => 'active',
            'approved_by' => $request->user()->id,
        ]);

        return response()->json($advance);
    }

    public function reject(Request $request, $id): JsonResponse
    {
        $advance = OperationalAdvance::findOrFail($id);
        if ($advance->status !== 'pending') {
            return response()->json(['message' => 'هذه السلفة ليست في حالة معلقة.'], 422);
        }

        $advance->update(['status' => 'rejected']);

        return response()->json($advance);
    }

    public function registerExpense(Request $request, $id): JsonResponse
    {
        $advance = OperationalAdvance::where('company_id', app('current_company_id'))
            ->with(['expenses', 'returns'])
            ->findOrFail($id);

        if ($advance->status !== 'active') {
            return response()->json(['message' => 'لا يمكن تسجيل مصروفات على سلفة غير نشطة.'], 422);
        }

        $totalExpenses = $advance->expenses->sum('amount');
        $totalReturns = $advance->returns->sum('amount');
        $remaining = $advance->amount - $totalExpenses - $totalReturns;

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.001|max:'.$remaining,
            'date' => 'required|date',
            'description' => 'required|string|max:255',
            'contract_id' => 'nullable|exists:contracts,id',
            'receipt_path' => 'nullable|string|max:255',
        ]);

        $expense = $advance->expenses()->create($validated);

        // Check if balance reached exactly 0
        $newRemaining = $remaining - $expense->amount;
        if (abs($newRemaining) < 0.0001) {
            $advance->update(['status' => 'completed']);
        }

        return response()->json($expense, 201);
    }

    public function registerReturn(Request $request, $id): JsonResponse
    {
        $advance = OperationalAdvance::where('company_id', app('current_company_id'))
            ->with(['expenses', 'returns'])
            ->findOrFail($id);

        if ($advance->status !== 'active') {
            return response()->json(['message' => 'لا يمكن تسجيل مرتجعات على سلفة غير نشطة.'], 422);
        }

        $totalExpenses = $advance->expenses->sum('amount');
        $totalReturns = $advance->returns->sum('amount');
        $remaining = $advance->amount - $totalExpenses - $totalReturns;

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.001|max:'.$remaining,
            'date' => 'required|date',
        ]);

        $return = $advance->returns()->create($validated);

        // Check if balance reached exactly 0
        $newRemaining = $remaining - $return->amount;
        if (abs($newRemaining) < 0.0001) {
            $advance->update(['status' => 'completed']);
        }

        return response()->json($return, 201);
    }

    /**
     * GET /api/operational-advances/my-balance — the float behind the calling login, if any.
     */
    public function myBalance(Request $request): JsonResponse
    {
        $companyId = (int) app('current_company_id');
        $employee = OperationalFundService::employeeFor($request->user(), $companyId);

        // A bare null would be sent as `{}`; say plainly that no float can exist for this login.
        if (! $employee) {
            return response()->json(['employee_id' => null, 'employee_name' => null, 'has_float' => false]);
        }

        return response()->json(array_merge(
            ['employee_id' => $employee->id, 'employee_name' => $employee->name],
            OperationalFundService::balanceFor($companyId, $employee->id)
        ));
    }

    /**
     * GET /api/operational-advances/balances — every administrative employee's float.
     */
    public function balances(): JsonResponse
    {
        return response()->json(OperationalFundService::balances((int) app('current_company_id')));
    }

    /**
     * POST /api/operational-advances/balances — hand cash to an administrative employee's float,
     * or take some back. A take-back is refused beyond what he still holds.
     */
    public function fund(Request $request): JsonResponse
    {
        $companyId = (int) app('current_company_id');

        $validated = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'kind' => ['nullable', Rule::in(['add', 'withdraw'])],
            'amount' => 'required|numeric|min:0.001',
            'date' => 'required|date',
            'notes' => 'nullable|string|max:255',
        ]);

        $employee = Employee::withoutGlobalScopes()->find($validated['employee_id']);
        if ($employee->role_category !== 'admin') {
            return response()->json([
                'message' => 'الرصيد يُعطى لموظف إداري فقط.',
                'errors' => ['employee_id' => ['الرصيد يُعطى لموظف إداري فقط.']],
            ], 422);
        }

        $amount = round((float) $validated['amount'], 3);
        $withdraw = ($validated['kind'] ?? 'add') === 'withdraw';

        if ($withdraw) {
            $available = OperationalFundService::balanceFor($companyId, $employee->id)['available'];
            if ($amount > $available + 0.0005) {
                $message = sprintf('المتاح في رصيده %s د.ك فقط، ولا يُسترجع أكثر منه.', number_format($available, 3));

                return response()->json(['message' => $message, 'errors' => ['amount' => [$message]]], 422);
            }
        }

        $fund = OperationalAdvanceFund::create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'amount' => $withdraw ? -$amount : $amount,
            'date' => $validated['date'],
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'fund' => $fund,
            'balance' => OperationalFundService::balanceFor($companyId, $employee->id),
        ], 201);
    }
}
