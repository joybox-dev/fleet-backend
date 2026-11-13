<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\MaintenanceRecord;
use App\Models\OperationalAdvance;
use App\Models\OperationalAdvanceFund;
use App\Models\Vehicle;
use App\Models\VehicleExpenseType;
use App\Models\Violation;
use App\Services\CustodySpendingService;
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
            ->get();

        // What each custody paid for: the records paid from it (CustodySpendingService), next to
        // the free-text expenses recorded on it before custodies paid for real records.
        $spends = CustodySpendingService::spendsOf($advances->pluck('id')->all())->groupBy('operational_advance_id');
        $advances = $advances->map(function (OperationalAdvance $advance) use ($spends) {
            $advance->paid_records = $spends->get($advance->id, collect())->values();
            $advance->remaining_balance = max(0, CustodySpendingService::remaining($advance));

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

    public function registerExpense(): JsonResponse
    {
        // Since 2026-10-26 a custody pays for real records only — a driver or vehicle expense, a
        // company expense, a fine, a maintenance — each recorded on its own screen with this custody
        // named as where the money came from. The free-text expenses recorded before stay on their custodies,
        // and no new one is accepted.
        return response()->json([
            'message' => 'الصرف من العهدة لم يعد وصفاً حراً — سجّل المصروف أو المخالفة أو الصيانة من شاشتها، واختر هذه العهدة.',
        ], 422);
    }

    public function registerReturn(Request $request, $id): JsonResponse
    {
        $advance = OperationalAdvance::where('company_id', app('current_company_id'))
            ->with(['expenses', 'returns'])
            ->findOrFail($id);

        if ($advance->status !== 'active') {
            return response()->json(['message' => 'لا يمكن تسجيل مرتجعات على سلفة غير نشطة.'], 422);
        }

        $remaining = max(0, CustodySpendingService::remaining($advance));

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.001|max:'.$remaining,
            'date' => 'required|date',
        ]);

        $return = $advance->returns()->create($validated);
        CustodySpendingService::refreshStatus($advance->id);

        return response()->json($return, 201);
    }

    /**
     * GET /api/operational-advances/spendable — the custodies a payment may come from: the ones the
     * login holds, or every one in use with «إعطاء رصيد»; each with what is left in it.
     */
    public function spendable(Request $request): JsonResponse
    {
        $companyId = (int) app('current_company_id');
        $user = $request->user();
        $own = OperationalFundService::seesAll($user) ? null : OperationalFundService::employeeFor($user, $companyId);
        if (! OperationalFundService::seesAll($user) && ! $own) {
            return response()->json([]);
        }

        $rows = OperationalAdvance::with('employee:id,name,name_ar')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->when($own, fn ($q) => $q->where('employee_id', $own->id))
            ->orderBy('date')
            ->get()
            ->map(fn (OperationalAdvance $a) => [
                'id' => $a->id,
                'employee_id' => $a->employee_id,
                'employee_name' => $a->employee?->name,
                'employee_name_ar' => $a->employee?->name_ar,
                'reason' => $a->reason,
                'date' => $a->date?->toDateString(),
                'amount' => round((float) $a->amount, 3),
                'remaining' => max(0, CustodySpendingService::remaining($a)),
            ])
            ->filter(fn ($a) => $a['remaining'] > 0)
            ->values();

        return response()->json($rows);
    }

    /**
     * GET /api/operational-advances/workspace — «عهدتي»: everything the employee holding the cash
     * needs on one screen. The custodies the login may pay from, what each paid for, what is waiting
     * to be paid (fines not yet paid, maintenance approved and not yet paid), and the short lookups
     * the payment forms need — sent here because a holder may have none of the screens they come
     * from, and a refused lookup would blank the form.
     */
    public function workspace(Request $request): JsonResponse
    {
        $custodies = collect($this->spendable($request)->getData(true));
        if ($custodies->isEmpty()) {
            return response()->json(['custodies' => [], 'awaiting' => ['violations' => [], 'maintenance' => []], 'lookups' => null]);
        }

        $spends = CustodySpendingService::spendsOf($custodies->pluck('id')->all())->groupBy('operational_advance_id');
        $custodies = $custodies->map(fn (array $c) => $c + ['paid_records' => $spends->get($c['id'], collect())->values()->all()]);

        // Waiting to be paid: only what was recorded since custodies paid for everything — older
        // fines and repairs were paid the old way and are not owed by anyone's custody.
        $since = CustodySpendingService::SINCE;
        $violations = Violation::with(['employee:id,name,name_ar', 'vehicle:id,plate_number'])
            ->whereNull('operational_advance_id')
            ->where('created_at', '>=', $since)
            ->orderBy('violation_date')
            ->get(['id', 'employee_id', 'vehicle_id', 'violation_date', 'violation_type', 'reference_number', 'amount'])
            ->map(fn (Violation $v) => [
                'id' => $v->id, 'date' => substr((string) $v->violation_date, 0, 10), 'label' => $v->violation_type,
                'reference' => $v->reference_number, 'amount' => round((float) $v->amount, 3),
                'employee_name' => $v->employee?->name, 'plate_number' => $v->vehicle?->plate_number,
            ])->values();
        $maintenance = MaintenanceRecord::with('vehicle:id,plate_number')
            ->whereIn('status', ['approved', 'completed'])
            ->whereNull('operational_advance_id')
            ->where('actual_cost', '>', 0)
            ->where('approved_at', '>=', $since)
            ->orderBy('approved_at')
            ->get(['id', 'vehicle_id', 'maintenance_type', 'garage_name', 'maintenance_date', 'actual_cost'])
            ->map(fn (MaintenanceRecord $m) => [
                'id' => $m->id, 'date' => substr((string) $m->maintenance_date, 0, 10), 'label' => $m->garage_name,
                'type' => $m->maintenance_type, 'amount' => round((float) $m->actual_cost, 3),
                'plate_number' => $m->vehicle?->plate_number,
            ])->values();

        $companyId = (int) app('current_company_id');
        ExpenseCategory::ensureDefaults($companyId);

        return response()->json([
            'custodies' => $custodies->values(),
            'awaiting' => ['violations' => $violations, 'maintenance' => $maintenance],
            'lookups' => [
                'drivers' => Employee::where('role_category', 'driver')->whereNotIn('status', ['terminated'])
                    ->orderBy('name')->get(['id', 'name', 'name_ar', 'employee_number']),
                'vehicles' => Vehicle::orderBy('plate_number')->get(['id', 'plate_number']),
                'expense_types' => VehicleExpenseType::orderBy('id')->get(['id', 'name', 'name_ar']),
                'categories' => ExpenseCategory::whereNull('parent_id')->where('is_active', true)
                    ->with(['children' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id')])
                    ->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar'])
                    ->map(fn (ExpenseCategory $c) => ['id' => $c->id, 'name_ar' => $c->name_ar, 'children' => $c->children->map(fn ($i) => ['id' => $i->id, 'name_ar' => $i->name_ar])->values()]),
            ],
        ]);
    }

    /**
     * GET /api/operational-advances/employees — the administrative employees a custody or a float
     * can go to. The custody screen used to read them from the employees list, which a role
     * restricted to its contracts narrows to those contracts' drivers: the form then had no one to
     * choose («محاسب رواتب», 2026-11).
     */
    public function employees(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->can('op_advances.view') && ! $user->can('op_advances.create') && ! OperationalFundService::seesAll($user)) {
            return response()->json(['message' => 'غير مصرح لك باستعراض العهد التشغيلية.'], 403);
        }

        return response()->json(Employee::where('company_id', (int) app('current_company_id'))
            ->where('role_category', 'admin')
            ->whereNotIn('status', ['terminated'])
            ->orderBy('name')
            ->get(['id', 'name', 'name_ar', 'employee_number', 'status', 'role_category', 'user_id']));
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
