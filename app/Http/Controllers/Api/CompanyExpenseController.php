<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyExpense;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The company's own spending — rent, residencies, printing, bank fees — against its expense tree.
 * Nothing here charges a driver: an employee, vehicle or contract on the record is a reference.
 */
class CompanyExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('company_expenses.view') && ! $request->user()->can('reports.view')) {
            return response()->json(['message' => 'غير مصرح لك باستعراض مصاريف الشركة.'], 403);
        }

        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'category_id' => 'nullable|integer',
            'search' => 'nullable|string|max:120',
        ]);

        $rows = CompanyExpense::with(['category.parent', 'employee:id,name,employee_number', 'vehicle:id,plate_number', 'contract:id,name'])
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->when($filters['category_id'] ?? null, function ($q, $id) {
                // A category stands for its items too.
                $ids = ExpenseCategory::where('parent_id', $id)->pluck('id')->push((int) $id);
                $q->whereIn('expense_category_id', $ids);
            })
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function ($q) use ($filters) {
                $term = '%'.trim((string) $filters['search']).'%';
                $q->where(fn ($w) => $w->where('description', 'like', $term)->orWhere('vendor', 'like', $term)->orWhere('reference', 'like', $term));
            })
            ->orderByDesc('expense_date')->orderByDesc('id')
            ->limit(5000)
            ->get()
            ->map(fn (CompanyExpense $e) => self::row($e));

        return response()->json([
            'data' => $rows->values()->all(),
            'total' => round((float) $rows->sum('amount'), 3),
            'paid_via' => CompanyExpense::PAID_VIA,
        ]);
    }

    public function show(Request $request, CompanyExpense $companyExpense): JsonResponse
    {
        if (! $request->user()->can('company_expenses.view') && ! $request->user()->can('reports.view')) {
            return response()->json(['message' => 'غير مصرح لك باستعراض مصاريف الشركة.'], 403);
        }

        return response()->json(['data' => self::row($companyExpense->load(['category.parent', 'employee', 'vehicle', 'contract']))]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('company_expenses.create')) {
            return response()->json(['message' => 'غير مصرح لك بتسجيل مصاريف الشركة.'], 403);
        }

        $expense = CompanyExpense::create($this->validated($request) + ['created_by' => $request->user()->id]);

        return response()->json(['data' => self::row($expense->load(['category.parent', 'employee', 'vehicle', 'contract']))], 201);
    }

    public function update(Request $request, CompanyExpense $companyExpense): JsonResponse
    {
        if (! $request->user()->can('company_expenses.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتعديل مصاريف الشركة.'], 403);
        }

        $companyExpense->update($this->validated($request, true));

        return response()->json(['data' => self::row($companyExpense->fresh(['category.parent', 'employee', 'vehicle', 'contract']))]);
    }

    public function destroy(Request $request, CompanyExpense $companyExpense): JsonResponse
    {
        if (! $request->user()->can('company_expenses.delete')) {
            return response()->json(['message' => 'غير مصرح لك بحذف مصاريف الشركة.'], 403);
        }

        $companyExpense->delete();

        return response()->json(['message' => 'تم الحذف']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $companyId = (int) app('current_company_id');
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'expense_category_id' => [$req, 'integer', Rule::exists('expense_categories', 'id')->where('company_id', $companyId)],
            'amount' => [$req, 'numeric', 'min:0.001'],
            'expense_date' => [$req, 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_via' => ['nullable', 'string', Rule::in(array_keys(CompanyExpense::PAID_VIA))],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('company_id', $companyId)],
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')->where('company_id', $companyId)],
            'receipt_path' => ['nullable', 'string', 'max:255'],
        ], [
            'expense_category_id.required' => 'اختر بند المصروف.',
            'amount.required' => 'المبلغ مطلوب.',
            'amount.min' => 'المبلغ يجب أن يكون أكبر من صفر.',
            'expense_date.required' => 'تاريخ المصروف مطلوب.',
        ]);
    }

    /** @return array<string, mixed> */
    public static function row(CompanyExpense $e): array
    {
        return [
            'id' => $e->id,
            'expense_category_id' => $e->expense_category_id,
            'category_id' => $e->category?->parent_id ?? $e->expense_category_id,
            'category_name' => $e->category?->parent?->name_ar ?? $e->category?->name_ar,
            'item_name' => $e->category?->parent_id ? $e->category->name_ar : null,
            'category_path' => $e->category?->path(),
            'amount' => round((float) $e->amount, 3),
            'expense_date' => $e->expense_date?->toDateString(),
            'description' => $e->description,
            'vendor' => $e->vendor,
            'reference' => $e->reference,
            'paid_via' => $e->paid_via,
            'paid_via_label' => CompanyExpense::PAID_VIA[$e->paid_via] ?? $e->paid_via,
            'employee_id' => $e->employee_id,
            'employee_name' => $e->employee?->name,
            'vehicle_id' => $e->vehicle_id,
            'plate_number' => $e->vehicle?->plate_number,
            'contract_id' => $e->contract_id,
            'contract_name' => $e->contract?->name,
            'receipt_path' => $e->receipt_path,
        ];
    }
}
