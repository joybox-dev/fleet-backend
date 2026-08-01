<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperationalAdvance;
use App\Models\OperationalAdvanceExpense;
use App\Models\OperationalAdvanceReturn;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OperationalAdvanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');
        $user = $request->user();

        // Check if user has permission to manage all operational advances (requires explicit op_advances.create or super admin)
        $canManageAll = $user && (
            $user->isSuperAdmin() || 
            $user->can('op_advances.create')
        );

        $employeeId = $request->employee_id;

        if (!$canManageAll) {
            // Scope to logged-in user's employee ID only
            $userEmployee = \App\Models\Employee::withoutGlobalScopes()
                ->where(function($q) use ($user) {
                    $q->where('user_id', $user?->id);
                    if (!empty($user?->email)) {
                        $q->orWhereHas('user', function($uq) use ($user) {
                            $uq->where('email', $user->email);
                        });
                    }
                })->first();

            if (!$userEmployee) {
                return response()->json([]);
            }
            $employeeId = $userEmployee->id;
        }

        $advances = OperationalAdvance::with(['employee:id,name', 'approver:id,name', 'expenses.contract:id,name', 'returns'])
            ->where('company_id', $companyId)
            ->when($employeeId, fn($q) => $q->where('employee_id', $employeeId))
            ->orderByDesc('date')
            ->get()
            ->map(function ($advance) {
                $totalExpenses = $advance->expenses->sum('amount');
                $totalReturns = $advance->returns->sum('amount');
                $advance->remaining_balance = max(0, $advance->amount - $totalExpenses - $totalReturns);
                return $advance;
            });

        return response()->json($advances);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');
        $user = $request->user();

        $canCreateActive = $user && ($user->isSuperAdmin() || $user->role === 'admin' || $user->can('op_advances.create') || $user->can('op_advances.edit'));
        $canRequestPending = $user && ($user->role === 'operator' || $user->can('op_advances.view'));

        if (!$canCreateActive && !$canRequestPending) {
            return response()->json(['message' => 'غير مصرح لك بإضافة عهدة تشغيلية جديدة.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'amount' => 'required|numeric|min:0.001',
            'date' => 'required|date',
            'reason' => 'required|string|max:255',
        ]);

        $validated['company_id'] = $companyId;

        if ($canCreateActive) {
            $validated['status'] = 'active';
            $validated['approved_by'] = $user->id;
        } else {
            $validated['status'] = 'pending';
        }

        $advance = OperationalAdvance::create($validated);

        return response()->json($advance->load(['employee']), 201);
    }

    public function approve(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->role !== 'admin' && !$user->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح. يجب أن تكون مسؤول شركة لاعتماد السلف.'], 403);
        }

        $advance = OperationalAdvance::where('company_id', app('current_company_id'))->findOrFail($id);
        if ($advance->status !== 'pending') {
            return response()->json(['message' => 'هذه السلفة ليست في حالة معلقة.'], 422);
        }

        $advance->update([
            'status' => 'active',
            'approved_by' => $user->id
        ]);

        return response()->json($advance);
    }

    public function reject(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->role !== 'admin' && !$user->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح. يجب أن تكون مسؤول شركة لرفض السلف.'], 403);
        }

        $advance = OperationalAdvance::where('company_id', app('current_company_id'))->findOrFail($id);
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
            'amount' => 'required|numeric|min:0.001|max:' . $remaining,
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
            'amount' => 'required|numeric|min:0.001|max:' . $remaining,
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
}
