<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriverExpense;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DriverExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->can('driver_expenses.view') && !$request->user()->can('employees.view') && !$request->user()->can('payroll.view')) {
            return response()->json(['message' => 'غير مصرح لك باستعراض مصاريف السائقين.'], 403);
        }

        $companyId = app('current_company_id');
        $allowedDriverIds = \App\Services\ContractScopeService::getAllocatedDriverIds($request->user());

        $query = DriverExpense::with(['employee:id,name,name_ar,employee_number,first_name_ar,last_name_ar,first_name,last_name,code', 'vehicle:id,plate_number,make,model', 'expenseType:id,name,name_ar'])
            ->where('company_id', $companyId)
            ->when($allowedDriverIds !== null, fn($q) => $q->whereIn('employee_id', $allowedDriverIds));

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }

        if ($request->filled('borne_by')) {
            $query->where('borne_by', $request->borne_by);
        }

        if ($request->filled('expense_type')) {
            $query->where(function ($q) use ($request) {
                $q->where('expense_type', $request->expense_type)
                  ->orWhere('expense_type_id', $request->expense_type);
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('expense_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhere('vendor', 'like', "%{$search}%")
                  ->orWhereHas('employee', function ($eq) use ($search) {
                      $eq->where('first_name_ar', 'like', "%{$search}%")
                         ->orWhere('last_name_ar', 'like', "%{$search}%")
                         ->orWhere('code', 'like', "%{$search}%");
                  });
            });
        }

        // Clone query for summary totals
        $summaryQuery = clone $query;
        $totalAmount = (float) $summaryQuery->sum('amount');
        $totalCompanyShare = (float) $summaryQuery->sum('company_amount');
        $totalDriverShare = (float) $summaryQuery->sum('driver_amount');

        $perPage = $request->get('per_page', 50);
        $expenses = $query->orderBy('expense_date', 'desc')->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $expenses->items(),
            'current_page' => $expenses->currentPage(),
            'last_page' => $expenses->lastPage(),
            'total' => $expenses->total(),
            'summary' => [
                'total_amount'        => round($totalAmount, 3),
                'total_company_share' => round($totalCompanyShare, 3),
                'total_driver_share'  => round($totalDriverShare, 3),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->can('driver_expenses.create') && !$request->user()->can('employees.create') && !$request->user()->can('payroll.create')) {
            return response()->json(['message' => 'غير مصرح لك بإضافة مصاريف السائقين.'], 403);
        }

        $companyId = app('current_company_id');
        $validated = $request->validate([
            'employee_id'     => 'required|exists:employees,id',
            'vehicle_id'      => 'nullable|exists:vehicles,id',
            'expense_type_id' => 'nullable|exists:vehicle_expense_types,id',
            'expense_type'    => 'required|string|max:255',
            'amount'          => 'required|numeric|min:0.001',
            'borne_by'        => 'required|in:company,driver,split',
            'company_amount'  => 'nullable|numeric|min:0',
            'driver_amount'   => 'nullable|numeric|min:0',
            'expense_date'    => 'required|date',
            'vendor'          => 'nullable|string|max:255',
            'receipt_path'    => 'nullable|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'notes'           => 'nullable|string|max:1000',
        ]);

        $amount = (float) $validated['amount'];
        $borneBy = $validated['borne_by'];

        if ($borneBy === 'company') {
            $companyAmount = $amount;
            $driverAmount = 0;
        } elseif ($borneBy === 'driver') {
            $companyAmount = 0;
            $driverAmount = $amount;
        } else { // split
            $companyAmount = isset($validated['company_amount']) ? (float) $validated['company_amount'] : round($amount / 2, 3);
            $driverAmount = isset($validated['driver_amount']) ? (float) $validated['driver_amount'] : round($amount - $companyAmount, 3);
        }

        $validated['company_id'] = $companyId;
        $validated['company_amount'] = $companyAmount;
        $validated['driver_amount'] = $driverAmount;

        $expense = DriverExpense::create($validated);
        $expense->load(['employee:id,name,name_ar,employee_number,first_name_ar,last_name_ar,first_name,last_name,code', 'vehicle:id,plate_number,make,model', 'expenseType:id,name,name_ar']);

        return response()->json($expense, 201);
    }

    public function show(DriverExpense $driverExpense): JsonResponse
    {
        $driverExpense->load(['employee:id,name,name_ar,employee_number,first_name_ar,last_name_ar,first_name,last_name,code', 'vehicle:id,plate_number,make,model', 'expenseType:id,name,name_ar']);
        return response()->json($driverExpense);
    }

    public function update(Request $request, DriverExpense $driverExpense): JsonResponse
    {
        if (!$request->user()->can('driver_expenses.edit') && !$request->user()->can('employees.edit') && !$request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتعديل مصاريف السائقين.'], 403);
        }

        $validated = $request->validate([
            'employee_id'     => 'required|exists:employees,id',
            'vehicle_id'      => 'nullable|exists:vehicles,id',
            'expense_type_id' => 'nullable|exists:vehicle_expense_types,id',
            'expense_type'    => 'required|string|max:255',
            'amount'          => 'required|numeric|min:0.001',
            'borne_by'        => 'required|in:company,driver,split',
            'company_amount'  => 'nullable|numeric|min:0',
            'driver_amount'   => 'nullable|numeric|min:0',
            'expense_date'    => 'required|date',
            'vendor'          => 'nullable|string|max:255',
            'receipt_path'    => 'nullable|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'notes'           => 'nullable|string|max:1000',
        ]);

        $amount = (float) $validated['amount'];
        $borneBy = $validated['borne_by'];

        if ($borneBy === 'company') {
            $companyAmount = $amount;
            $driverAmount = 0;
        } elseif ($borneBy === 'driver') {
            $companyAmount = 0;
            $driverAmount = $amount;
        } else { // split
            $companyAmount = isset($validated['company_amount']) ? (float) $validated['company_amount'] : round($amount / 2, 3);
            $driverAmount = isset($validated['driver_amount']) ? (float) $validated['driver_amount'] : round($amount - $companyAmount, 3);
        }

        $validated['company_amount'] = $companyAmount;
        $validated['driver_amount'] = $driverAmount;

        $driverExpense->update($validated);
        $driverExpense->load(['employee:id,name,name_ar,employee_number,first_name_ar,last_name_ar,first_name,last_name,code', 'vehicle:id,plate_number,make,model', 'expenseType:id,name,name_ar']);

        return response()->json($driverExpense);
    }

    public function destroy(Request $request, DriverExpense $driverExpense): JsonResponse
    {
        if (!$request->user()->can('driver_expenses.delete') && !$request->user()->can('employees.delete') && !$request->user()->can('payroll.delete')) {
            return response()->json(['message' => 'غير مصرح لك بحذف مصاريف السائقين.'], 403);
        }

        if ($driverExpense->is_deducted) {
            return response()->json(['message' => 'لا يمكن حذف المصروف لأنه تم خصمه مسبقاً من كشف الرواتب.'], 422);
        }

        $driverExpense->delete();
        return response()->json(['message' => 'تم حذف مصروف السائق بنجاح.']);
    }
}
