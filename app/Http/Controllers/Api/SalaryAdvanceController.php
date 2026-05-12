<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\SalaryAdvance;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SalaryAdvanceController extends Controller
{
    /**
     * GET /api/salary-advances
     */
    public function index(Request $request): JsonResponse
    {
        $query = SalaryAdvance::with(['employee:id,name', 'approver:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $advances = $query->orderByDesc('advance_date')->get();

        return response()->json($advances);
    }

    /**
     * POST /api/salary-advances
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'         => 'required|exists:employees,id',
            'amount'              => 'required|numeric|min:1',
            'monthly_installment' => 'required|numeric|min:0.001',
            'advance_date'        => 'required|date',
            'reason'              => 'nullable|string|max:500',
        ]);

        // Check for existing active advance for same employee
        $existing = SalaryAdvance::where('employee_id', $validated['employee_id'])
            ->where('status', 'active')
            ->exists();

        if ($existing) {
            return response()->json([
                'message' => 'يوجد سلفة فعّالة لهذا الموظف. يجب إتمامها أو إلغاؤها أولاً.',
            ], 422);
        }

        $amount      = (float) $validated['amount'];
        $installment = (float) $validated['monthly_installment'];

        $validated['total_installments'] = (int) ceil($amount / $installment);
        $validated['paid_installments']  = 0;
        $validated['remaining_balance']  = $amount;
        $validated['status']             = 'active';
        $validated['approved_by']        = $request->user()->id;

        $advance = SalaryAdvance::create($validated);



        $advance->load(['employee:id,name', 'approver:id,name']);

        return response()->json($advance, 201);
    }

    /**
     * GET /api/salary-advances/{salaryAdvance}
     */
    public function show(SalaryAdvance $salaryAdvance): JsonResponse
    {
        $salaryAdvance->load([
            'employee:id,name',
            'approver:id,name',
            'deductions' => fn($q) => $q->orderByDesc('deduction_date'),
        ]);

        return response()->json($salaryAdvance);
    }

    /**
     * POST /api/salary-advances/{salaryAdvance}/cancel
     */
    public function cancel(SalaryAdvance $salaryAdvance): JsonResponse
    {
        if ($salaryAdvance->status !== 'active') {
            return response()->json([
                'message' => 'لا يمكن إلغاء سلفة غير فعّالة.',
            ], 422);
        }

        $salaryAdvance->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'تم إلغاء السلفة.',
            'data'    => $salaryAdvance,
        ]);
    }
}
