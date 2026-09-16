<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContractScopeService;
use App\Services\OperationsDashboardService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationsController extends Controller
{
    /**
     * GET /api/operations/dashboard?day=YYYY-MM-DD
     *
     * The operations manager's day, read from the supervisors' daily log: who is on duty, who has
     * no fit vehicle, whose cash is still out, who did not turn up, the worst performers and the
     * contracts losing money. Without `day` the last fully entered day is described; a supervisor
     * limited to some contracts sees their drivers only. See OperationsDashboardService.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'day' => 'nullable|date|before_or_equal:today',
        ]);

        return response()->json(OperationsDashboardService::build(
            $this->currentCompanyId(),
            Carbon::today(),
            ContractScopeService::getAllocatedContractIds(),
            isset($validated['day']) ? Carbon::parse($validated['day'])->toDateString() : null,
        ));
    }
}
