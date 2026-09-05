<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExpenseLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One read-only view over the six screens that record spending. It writes nothing and owns nothing:
 * every row points back at the screen its record has always lived on.
 */
class ExpenseLedgerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Anyone who may read one of the underlying screens may read the list of all of them.
        $user = $request->user();
        $allowed = collect(['violations.view', 'driver_expenses.view', 'vehicle_expenses.view',
            'maintenance.view', 'custody.view', 'salary_advances.view', 'payroll.view'])
            ->contains(fn ($p) => $user->can($p));

        if (! $allowed) {
            return response()->json(['message' => 'غير مصرح لك باستعراض المصاريف.'], 403);
        }

        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'kind' => 'nullable|string|in:'.implode(',', array_keys(ExpenseLedgerService::KINDS)),
            'employee_id' => 'nullable|integer',
            'vehicle_id' => 'nullable|integer',
            'borne_by' => 'nullable|string|in:company,driver,split',
            'search' => 'nullable|string|max:120',
        ]);

        return response()->json(
            ExpenseLedgerService::build(app('current_company_id'), $filters)
        );
    }
}
