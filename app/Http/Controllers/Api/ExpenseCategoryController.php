<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyExpense;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The company's expense tree, two levels: a category and its items. Read by whoever records or
 * reads company expenses; changed from settings.
 */
class ExpenseCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->can('company_expenses.view') && ! $user->can('settings.view') && ! $user->can('reports.view')) {
            return response()->json(['message' => 'غير مصرح لك باستعراض شجرة المصاريف.'], 403);
        }

        $companyId = (int) app('current_company_id');
        ExpenseCategory::ensureDefaults($companyId);

        $tree = ExpenseCategory::whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (ExpenseCategory $c) => self::node($c) + [
                'children' => $c->children->map(fn (ExpenseCategory $i) => self::node($i))->values()->all(),
            ]);

        return response()->json(['data' => $tree->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->denied($request)) {
            return $denied;
        }

        $category = ExpenseCategory::create($this->validated($request));

        return response()->json(['data' => self::node($category)], 201);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        if ($denied = $this->denied($request)) {
            return $denied;
        }

        $expenseCategory->update($this->validated($request, $expenseCategory));

        return response()->json(['data' => self::node($expenseCategory->fresh())]);
    }

    public function destroy(Request $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        if ($denied = $this->denied($request)) {
            return $denied;
        }

        // An item with expenses recorded against it is history; it can be switched off, not removed.
        $ids = $expenseCategory->children()->pluck('id')->push($expenseCategory->id);
        if (CompanyExpense::withTrashed()->whereIn('expense_category_id', $ids)->exists()) {
            return response()->json(['message' => 'عليه مصاريف مسجّلة — أوقفه بدل حذفه.'], 422);
        }

        ExpenseCategory::whereIn('id', $ids)->where('id', '!=', $expenseCategory->id)->delete();
        $expenseCategory->delete();

        return response()->json(['message' => 'تم الحذف']);
    }

    private function denied(Request $request): ?JsonResponse
    {
        $user = $request->user();

        return $user->can('settings.edit') || $user->can('company_expenses.edit')
            ? null
            : response()->json(['message' => 'غير مصرح لك بتعديل شجرة المصاريف.'], 403);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ExpenseCategory $current = null): array
    {
        $companyId = (int) app('current_company_id');

        $data = $request->validate([
            'name_ar' => [$current ? 'sometimes' : 'required', 'string', 'max:120'],
            // Two levels only: an item hangs from a category, never from another item.
            'parent_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')->where('company_id', $companyId)->whereNull('parent_id')],
            'code' => ['nullable', 'string', 'max:30'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name_ar.required' => 'اسم البند مطلوب.',
            'parent_id.exists' => 'البند يتبع فئة رئيسية فقط.',
        ]);

        if ($current && isset($data['parent_id']) && (int) $data['parent_id'] === (int) $current->id) {
            abort(response()->json(['message' => 'لا يتبع البند نفسه.'], 422));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private static function node(ExpenseCategory $c): array
    {
        return [
            'id' => $c->id,
            'parent_id' => $c->parent_id,
            'name_ar' => $c->name_ar,
            'code' => $c->code,
            'sort_order' => $c->sort_order,
            'is_active' => (bool) $c->is_active,
        ];
    }
}
