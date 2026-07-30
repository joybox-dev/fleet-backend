<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleExpense;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class VehicleExpenseController extends Controller
{
    /**
     * GET /api/vehicle-expenses
     */
    public function index(Request $request): JsonResponse
    {
        $query = VehicleExpense::with('vehicle:id,plate_number,make,model');

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('expense_type')) {
            $query->where('expense_type', $request->expense_type);
        }
        if ($request->filled('from')) {
            $query->whereDate('expense_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->to);
        }

        $expenses = $query->orderByDesc('expense_date')->get();

        return response()->json($expenses);
    }

    /**
     * POST /api/vehicle-expenses
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->can('vehicle_expenses.create')) {
            return response()->json(['message' => 'غير مصرح لك بإضافة مصاريف مركبـة.'], 403);
        }

        $validated = $request->validate([
            'vehicle_id'   => 'required|exists:vehicles,id',
            'expense_type' => 'required|in:fuel,insurance,tires,registration,fine,repair,other',
            'amount'       => 'required|numeric|min:0.001',
            'expense_date' => 'required|date',
            'vendor'       => 'nullable|string|max:255',
            'receipt_path' => 'nullable|string',
            'description'  => 'nullable|string|max:500',
            'notes'        => 'nullable|string',
        ]);

        $expense = VehicleExpense::create($validated);
        $expense->load('vehicle:id,plate_number,make,model');

        return response()->json($expense, 201);
    }

    /**
     * GET /api/vehicle-expenses/{vehicleExpense}
     */
    public function show(VehicleExpense $vehicleExpense): JsonResponse
    {
        $vehicleExpense->load('vehicle:id,plate_number,make,model');
        return response()->json($vehicleExpense);
    }

    /**
     * PUT /api/vehicle-expenses/{vehicleExpense}
     */
    public function update(Request $request, VehicleExpense $vehicleExpense): JsonResponse
    {
        if (!$request->user()->can('vehicle_expenses.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتعديل مصاريف المركبة.'], 403);
        }

        $validated = $request->validate([
            'vehicle_id'   => 'sometimes|exists:vehicles,id',
            'expense_type' => 'sometimes|in:fuel,insurance,tires,registration,fine,repair,other',
            'amount'       => 'sometimes|numeric|min:0.001',
            'expense_date' => 'sometimes|date',
            'vendor'       => 'nullable|string|max:255',
            'receipt_path' => 'nullable|string',
            'description'  => 'nullable|string|max:500',
            'notes'        => 'nullable|string',
        ]);

        $vehicleExpense->update($validated);
        $vehicleExpense->load('vehicle:id,plate_number,make,model');

        return response()->json($vehicleExpense);
    }

    /**
     * DELETE /api/vehicle-expenses/{vehicleExpense}
     */
    public function destroy(Request $request, VehicleExpense $vehicleExpense): JsonResponse
    {
        if (!$request->user()->can('vehicle_expenses.delete')) {
            return response()->json(['message' => 'غير مصرح لك بحذف مصاريف المركبة.'], 403);
        }

        $vehicleExpense->delete();
        return response()->json(['message' => 'تم الحذف.']);
    }

    /**
     * GET /api/vehicle-expenses/summary
     * Aggregate expenses by type, optionally filtered by vehicle and date range.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = VehicleExpense::query();

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('from')) {
            $query->whereDate('expense_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->to);
        }

        $byType = $query->groupBy('expense_type')
            ->selectRaw('expense_type, SUM(amount) as total, COUNT(*) as count')
            ->get()
            ->keyBy('expense_type');

        $grandTotal = $byType->sum('total');

        return response()->json([
            'by_type'     => $byType,
            'grand_total' => round($grandTotal, 3),
        ]);
    }
}
