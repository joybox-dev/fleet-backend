<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contracts = Contract::with('client:id,name')
            ->when($request->client_id, fn($q) => $q->where('client_id', $request->client_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->boolean('active_only'), fn($q) => $q->where('status', 'active'))
            ->orderByDesc('start_date')
            ->paginate(50);

        return response()->json($contracts);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');

        $validated = $request->validate([
            'client_id'      => 'required|exists:clients,id',
            'contract_number'=> [
                'required',
                'string',
                Rule::unique('contracts', 'contract_number')->where('company_id', $companyId),
            ],
            'name'           => 'required|string|max:255',
            'client_name'    => 'nullable|string|max:255',
            'status'         => 'nullable|in:active,suspended,ended',
            'currency'       => 'nullable|in:KWD,SAR,QAR',
            'payment_type'   => 'required|in:per_order,hourly,fixed,hybrid',
            
            // Backwards compatibility
            'rate_per_order' => 'nullable|numeric|min:0',
            'fixed_monthly'  => 'nullable|numeric|min:0',

            // Defaults and customizations
            'default_order_commission'   => 'nullable|numeric|min:0',
            'default_hourly_rate'        => 'nullable|numeric|min:0',
            'default_work_hours_source'  => 'nullable|in:manual,timesheet,keeta_report',
            'default_fixed_salary'       => 'nullable|numeric|min:0',
            'default_absence_divisor'    => 'nullable|integer|min:1',
            'default_monthly_target'     => 'nullable|integer|min:0',
            'default_daily_target'       => 'nullable|integer|min:0',
            'required_drivers_count'     => 'nullable|integer|min:0',
            'required_vehicles_count'    => 'nullable|integer|min:0',
            
            'expected_monthly_revenue'   => 'nullable|numeric|min:0',
            'expected_monthly_expenses'  => 'nullable|numeric|min:0',
            'target_profit_margin'       => 'nullable|numeric|min:0|max:100',
            'expected_total_profit'      => 'nullable|numeric|min:0',
            
            'default_required_valid_days' => 'nullable|integer|min:0',
            'threshold_type'             => 'nullable|in:percentage,fixed_count,both',
            'minor_threshold_limit'      => 'nullable|numeric|min:0',
            'major_threshold_limit'      => 'nullable|numeric|min:0',
            
            'start_date'     => 'required|date',
            'end_date'       => 'nullable|date|after:start_date',
            'notes'          => 'nullable|string|max:1000',
        ]);

        if (empty($validated['client_name'])) {
            $client = \App\Models\Client::find($validated['client_id']);
            $validated['client_name'] = $client ? $client->name : 'Default Client';
        }
        if (empty($validated['status'])) {
            $validated['status'] = 'active';
        }
        if (empty($validated['currency'])) {
            $validated['currency'] = 'KWD';
        }

        if (isset($validated['rate_per_order']) && !isset($validated['default_order_commission'])) {
            $validated['default_order_commission'] = $validated['rate_per_order'];
        }
        if (isset($validated['fixed_monthly']) && !isset($validated['default_fixed_salary'])) {
            $validated['default_fixed_salary'] = $validated['fixed_monthly'];
        }

        $contract = Contract::create($validated);

        return response()->json($contract->load('client:id,name'), 201);
    }

    public function show(Contract $contract): JsonResponse
    {
        return response()->json($contract->load(['client', 'assignments.employee', 'monthlyParameters', 'bonuses']));
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        if ($contract->is_locked) {
            return response()->json(['message' => 'Contract is locked and cannot be modified.'], 403);
        }

        $companyId = app('current_company_id');

        $validated = $request->validate([
            'client_id'      => 'sometimes|exists:clients,id',
            'contract_number'=> [
                'sometimes',
                'string',
                Rule::unique('contracts', 'contract_number')->ignore($contract->id)->where('company_id', $companyId),
            ],
            'name'           => 'sometimes|string|max:255',
            'client_name'    => 'sometimes|string|max:255',
            'status'         => 'sometimes|in:active,suspended,ended',
            'currency'       => 'sometimes|in:KWD,SAR,QAR',
            'payment_type'   => 'sometimes|in:per_order,hourly,fixed,hybrid',
            
            // Backwards compatibility
            'rate_per_order' => 'nullable|numeric|min:0',
            'fixed_monthly'  => 'nullable|numeric|min:0',

            'default_order_commission'   => 'nullable|numeric|min:0',
            'default_hourly_rate'        => 'nullable|numeric|min:0',
            'default_work_hours_source'  => 'nullable|in:manual,timesheet,keeta_report',
            'default_fixed_salary'       => 'nullable|numeric|min:0',
            'default_absence_divisor'    => 'nullable|integer|min:1',
            'default_monthly_target'     => 'nullable|integer|min:0',
            'default_daily_target'       => 'nullable|integer|min:0',
            'required_drivers_count'     => 'nullable|integer|min:0',
            'required_vehicles_count'    => 'nullable|integer|min:0',
            
            'expected_monthly_revenue'   => 'nullable|numeric|min:0',
            'expected_monthly_expenses'  => 'nullable|numeric|min:0',
            'target_profit_margin'       => 'nullable|numeric|min:0|max:100',
            'expected_total_profit'      => 'nullable|numeric|min:0',
            
            'default_required_valid_days' => 'nullable|integer|min:0',
            'threshold_type'             => 'nullable|in:percentage,fixed_count,both',
            'minor_threshold_limit'      => 'nullable|numeric|min:0',
            'major_threshold_limit'      => 'nullable|numeric|min:0',
            
            'start_date'     => 'sometimes|date',
            'end_date'       => 'nullable|date',
            'is_active'      => 'sometimes|boolean',
            'notes'          => 'nullable|string|max:1000',
        ]);

        if (isset($validated['rate_per_order']) && !isset($validated['default_order_commission'])) {
            $validated['default_order_commission'] = $validated['rate_per_order'];
        }
        if (isset($validated['fixed_monthly']) && !isset($validated['default_fixed_salary'])) {
            $validated['default_fixed_salary'] = $validated['fixed_monthly'];
        }

        $contract->update($validated);

        return response()->json($contract->fresh());
    }

    public function deletionCheck(Contract $contract): JsonResponse
    {
        $blocks = $contract->getDeletionBlocks();
        return response()->json([
            'is_deletable' => empty($blocks),
            'blocks' => $blocks,
        ]);
    }

    public function destroy(Contract $contract): JsonResponse
    {
        $blocks = $contract->getDeletionBlocks();
        if (!empty($blocks)) {
            return response()->json([
                'message' => 'لا يمكن حذف العقد لوجود ارتباطات نشطة أو قيود تشغيلية.',
                'errors' => $blocks,
            ], 422);
        }

        $contract->delete();
        return response()->json(['message' => 'Contract deleted.']);
    }

    public function lock(Contract $contract): JsonResponse
    {
        if ($contract->is_locked) {
            return response()->json(['message' => 'Contract is already locked.'], 422);
        }

        $contract->update(['is_locked' => true]);

        return response()->json(['message' => 'Contract locked successfully. Financial data is now immutable.']);
    }

    public function storeMonthlyParameter(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'year'                       => 'required|integer|min:2020|max:2030',
            'month'                      => 'required|integer|min:1|max:12',
            'min_valid_days'             => 'nullable|integer|min:0',
            'min_completed_orders'       => 'nullable|integer|min:0',
            'daily_active_time_percentage' => 'nullable|numeric|min:0|max:100',
            'daily_min_orders'           => 'nullable|integer|min:0',
            'capacity_incentive_rules'   => 'nullable|array',
            'experience_incentive_rules' => 'nullable|array',
            'mandatory_days'             => 'nullable|array',
            'mandatory_days.*.start_date' => 'required|date',
            'mandatory_days.*.end_date'   => 'required|date|after_or_equal:mandatory_days.*.start_date',
            'mandatory_days.*.min_required_days' => 'required|integer|min:1',
        ]);

        \DB::beginTransaction();
        try {
            $param = \App\Models\ContractMonthlyParameter::updateOrCreate([
                'contract_id' => $contract->id,
                'year'        => $validated['year'],
                'month'       => $validated['month'],
            ], [
                'company_id'                 => $contract->company_id,
                'min_valid_days'             => $validated['min_valid_days'] ?? null,
                'min_completed_orders'       => $validated['min_completed_orders'] ?? null,
                'daily_active_time_percentage' => $validated['daily_active_time_percentage'] ?? null,
                'daily_min_orders'           => $validated['daily_min_orders'] ?? null,
                'capacity_incentive_rules'   => $validated['capacity_incentive_rules'] ?? null,
                'experience_incentive_rules' => $validated['experience_incentive_rules'] ?? null,
            ]);

            // Clear and recreate mandatory days
            $param->mandatoryDays()->delete();
            if (!empty($validated['mandatory_days'])) {
                foreach ($validated['mandatory_days'] as $md) {
                    $param->mandatoryDays()->create($md);
                }
            }

            \DB::commit();
            return response()->json($param->load('mandatoryDays'), 200);
        } catch (\Throwable $e) {
            \DB::rollBack();
            return response()->json(['message' => 'حدث خطأ أثناء حفظ الإعدادات: ' . $e->getMessage()], 500);
        }
    }

    public function storeBonus(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'bonus_name'           => 'required|string|max:255',
            'amount'               => 'required|numeric|min:0',
            'is_valid_drivers_only' => 'required|boolean',
        ]);

        $validated['company_id'] = $contract->company_id;
        $bonus = $contract->bonuses()->create($validated);

        return response()->json($bonus, 201);
    }

    public function destroyBonus(Contract $contract, $bonusId): JsonResponse
    {
        $bonus = $contract->bonuses()->findOrFail($bonusId);
        $bonus->delete();
        return response()->json(['message' => 'تم حذف الحافز بنجاح.']);
    }
}
