<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContractAssignment;
use App\Models\DriverContractOverride;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class ContractAssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
        ]);

        $assignments = ContractAssignment::with(['contract:id,name,currency,payment_type', 'overrides'])
            ->where('employee_id', $request->employee_id)
            ->orderByDesc('start_date')
            ->get();

        return response()->json($assignments);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');

        $validated = $request->validate([
            'employee_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('employees', 'id')
                    ->where('company_id', $companyId)
                    ->where('role_category', 'driver'),
            ],
            'contract_id' => 'required|exists:contracts,id',
            'start_date'  => 'required|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            'courier_id'  => 'nullable|string|max:255',
            'status'      => 'required|in:active,inactive',
        ]);

        $driver = \App\Models\Employee::findOrFail($validated['employee_id']);
        $contract = \App\Models\Contract::findOrFail($validated['contract_id']);

        $activeVehicleAssignment = \App\Models\VehicleAssignment::where('employee_id', $driver->id)
            ->where('is_active', true)
            ->first();

        if ($activeVehicleAssignment && $activeVehicleAssignment->vehicle) {
            $driverVehicleTypeId = $activeVehicleAssignment->vehicle->vehicle_type_id;
            if ($driverVehicleTypeId !== null) {
                if ($contract->vehicle_type_id !== null) {
                    if ($contract->vehicle_type_id !== $driverVehicleTypeId) {
                        return response()->json([
                            'message' => 'نوع المركبة الحالية للسائق لا يتوافق مع نوع المركبة المسموح به لهذا العقد.',
                            'errors' => ['contract_id' => ['نوع المركبة غير متوافق مع العقد.']]
                        ], 422);
                    }
                } else {
                    $clientPricing = $contract->client_pricing_rules ?? [];
                    $configuredVehicleTypes = array_keys($clientPricing);
                    if (!in_array((string)$driverVehicleTypeId, array_map('strval', $configuredVehicleTypes))) {
                        return response()->json([
                            'message' => 'نوع المركبة الحالية للسائق لا يتوافق مع المركبات المتاحة في تسعير هذا العقد.',
                            'errors' => ['contract_id' => ['نوع المركبة غير متوافق مع العقد.']]
                        ], 422);
                    }
                }
            }
        }

        // Check if the driver is already assigned to this contract (uniqueness check)
        $exists = ContractAssignment::where('employee_id', $validated['employee_id'])
            ->where('contract_id', $validated['contract_id'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'السائق معين بالفعل على هذا العقد ولا يمكن تكرار تعيينه.',
                'errors' => ['contract_id' => ['السائق معين بالفعل على هذا العقد.']]
            ], 422);
        }

        // Check for overlapping assignments on the same contract
        $overlap = ContractAssignment::where('employee_id', $validated['employee_id'])
            ->where('contract_id', $validated['contract_id'])
            ->where('status', 'active')
            ->where(function ($query) use ($validated) {
                $start = $validated['start_date'];
                $end = $validated['end_date'] ?? '9999-12-31';
                
                $query->where(function ($q) use ($start, $end) {
                    $q->where('start_date', '<=', $end)
                      ->whereRaw('COALESCE(end_date, "9999-12-31") >= ?', [$start]);
                });
            })
            ->exists();

        if ($overlap && $validated['status'] === 'active') {
            return response()->json([
                'message' => 'السائق لديه تعيين نشط متداخل في نفس الفترة لهذا العقد.',
                'errors' => ['start_date' => ['يوجد تداخل في تواريخ التعيين النشط لهذا العقد.']]
            ], 422);
        }

        $validated['company_id'] = $companyId;
        $assignment = ContractAssignment::create($validated);

        return response()->json($assignment->load('contract'), 201);
    }

    public function update(Request $request, ContractAssignment $assignment): JsonResponse
    {
        $validated = $request->validate([
            'start_date'  => 'sometimes|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            'courier_id'  => 'nullable|string|max:255',
            'status'      => 'sometimes|in:active,inactive',
        ]);

        // Check overlaps on update if active
        if (($validated['status'] ?? $assignment->status) === 'active') {
            $startDate = $validated['start_date'] ?? $assignment->start_date;
            $endDate = array_key_exists('end_date', $validated) ? $validated['end_date'] : $assignment->end_date;

            $overlap = ContractAssignment::where('employee_id', $assignment->employee_id)
                ->where('contract_id', $assignment->contract_id)
                ->where('id', '!=', $assignment->id)
                ->where('status', 'active')
                ->where(function ($query) use ($startDate, $endDate) {
                    $start = $startDate;
                    $end = $endDate ?? '9999-12-31';
                    
                    $query->where(function ($q) use ($start, $end) {
                        $q->where('start_date', '<=', $end)
                          ->whereRaw('COALESCE(end_date, "9999-12-31") >= ?', [$start]);
                    });
                })
                ->exists();

            if ($overlap) {
                return response()->json([
                    'message' => 'السائق لديه تعيين نشط متداخل في نفس الفترة لهذا العقد.',
                    'errors' => ['start_date' => ['يوجد تداخل في تواريخ التعيين النشط لهذا العقد.']]
                ], 422);
            }
        }

        $assignment->update($validated);

        return response()->json($assignment->load('contract'));
    }

    public function destroy(ContractAssignment $assignment): JsonResponse
    {
        // Prevent deletion if daily logs exist under this assignment contract
        $hasLogs = \App\Models\DailyLog::where('employee_id', $assignment->employee_id)
            ->where('contract_id', $assignment->contract_id)
            ->whereBetween('log_date', [$assignment->start_date, $assignment->end_date ?? Carbon::now()])
            ->exists();

        if ($hasLogs) {
            return response()->json([
                'message' => 'لا يمكن حذف التعيين لوجود سجلات عمل مسجلة للسائق تحت هذا العقد. يمكنك إيقاف التعيين بتعديل تاريخ النهاية.'
            ], 422);
        }

        $assignment->delete();
        return response()->json(['message' => 'تم حذف تعيين العقد بنصف نجاح.']);
    }

    // --- Overrides Management ---

    public function storeOverride(Request $request, ContractAssignment $assignment): JsonResponse
    {
        $companyId = app('current_company_id');

        $validated = $request->validate([
            'override_type'           => 'nullable|string|in:fixed,zones,tiers,hybrid,zones_tiers',
            'fixed_amount'            => 'nullable|numeric|min:0',
            'fixed_target'            => 'nullable|integer|min:0',
            'fixed_deficit_rate'      => 'nullable|numeric|min:0',
            'fixed_bonus_type'        => 'nullable|string|in:lump_sum,per_order',
            'fixed_surplus_bonus'     => 'nullable|numeric|min:0',
            'fixed_surplus_rate'      => 'nullable|numeric|min:0',
            'zone_target_orders'      => 'nullable|integer|min:0',
            'zone_deficit_rate'       => 'nullable|numeric|min:0',
            'zone_bonus_type'         => 'nullable|string|in:lump_sum,per_order',
            'zone_target_bonus'       => 'nullable|numeric|min:0',
            'zone_surplus_rate'       => 'nullable|numeric|min:0',
            'zones'                   => 'nullable|array',
            'tiers'                   => 'nullable|array',
            'hybrid_fixed'            => 'nullable|numeric|min:0',
            'hybrid_tiers'            => 'nullable|array',
            'zones_tiers'             => 'nullable|array',
            'customization_reason'    => 'required|string|max:1000',
            'effective_from'          => 'required|date',
            'effective_to'            => 'nullable|date|after_or_equal:effective_from',
        ]);

        // Validate override dates are within assignment dates
        $from = Carbon::parse($validated['effective_from']);
        $to = $validated['effective_to'] ? Carbon::parse($validated['effective_to']) : null;
        
        $assignStart = Carbon::parse($assignment->start_date);
        $assignEnd = $assignment->end_date ? Carbon::parse($assignment->end_date) : null;

        if ($from->lt($assignStart) || ($assignEnd && $from->gt($assignEnd))) {
            return response()->json([
                'message' => 'تاريخ بدء التجاوز يجب أن يكون ضمن فترة تعيين العقد للسائق.',
                'errors' => ['effective_from' => ['تاريخ التجاوز خارج نطاق تواريخ التعيين.']]
            ], 422);
        }

        if ($to && ($to->lt($assignStart) || ($assignEnd && $to->gt($assignEnd)))) {
            return response()->json([
                'message' => 'تاريخ نهاية التجاوز يجب أن يكون ضمن فترة تعيين العقد للسائق.',
                'errors' => ['effective_to' => ['تاريخ التجاوز خارج نطاق تواريخ التعيين.']]
            ], 422);
        }

        // Validate no overlapping overrides
        $overlap = DriverContractOverride::where('contract_assignment_id', $assignment->id)
            ->where(function ($query) use ($validated) {
                $start = $validated['effective_from'];
                $end = $validated['effective_to'] ?? '9999-12-31';
                
                $query->where(function ($q) use ($start, $end) {
                    $q->where('effective_from', '<=', $end)
                      ->whereRaw('COALESCE(effective_to, "9999-12-31") >= ?', [$start]);
                });
            })
            ->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'يوجد تجاوز آخر مخصص متداخل في نفس التواريخ لهذا السائق.',
                'errors' => ['effective_from' => ['يوجد تداخل مع فترة تجاوز أخرى.']]
            ], 422);
        }

        $pricingRulesFields = [
            'fixed_amount', 'fixed_target', 'fixed_deficit_rate', 'fixed_bonus_type',
            'fixed_surplus_bonus', 'fixed_surplus_rate', 'zone_target_orders',
            'zone_deficit_rate', 'zone_bonus_type', 'zone_target_bonus', 'zone_surplus_rate',
            'zones', 'tiers', 'hybrid_fixed', 'hybrid_tiers', 'zones_tiers'
        ];
        $rules = [];
        foreach ($pricingRulesFields as $field) {
            if ($request->has($field)) {
                $rules[$field] = $request->input($field);
            }
        }
        $validated['custom_pricing_rules'] = $rules;

        // Legacy mapping for backwards-compatibility & payroll runs
        if (isset($validated['override_type'])) {
            $oType = $validated['override_type'];
            $validated['custom_fixed_salary'] = ($oType === 'fixed') ? $request->input('fixed_amount') : (($oType === 'hybrid') ? $request->input('hybrid_fixed') : null);
            $validated['custom_monthly_target'] = ($oType === 'fixed') ? $request->input('fixed_target') : (($oType === 'zones') ? $request->input('zone_target_orders') : null);
            $validated['custom_monthly_bonus'] = ($oType === 'fixed') ? $request->input('fixed_surplus_bonus') : (($oType === 'zones') ? $request->input('zone_target_bonus') : null);
            $validated['custom_order_commission'] = ($oType === 'fixed') ? $request->input('fixed_deficit_rate') : (($oType === 'zones') ? $request->input('zone_deficit_rate') : null);
        }

        $validated['company_id'] = $companyId;
        $validated['contract_assignment_id'] = $assignment->id;
        
        $override = DriverContractOverride::create($validated);

        return response()->json($override, 201);
    }

    public function updateOverride(Request $request, DriverContractOverride $override): JsonResponse
    {
        $assignment = $override->contractAssignment;
        
        $validated = $request->validate([
            'override_type'           => 'nullable|string|in:fixed,zones,tiers,hybrid,zones_tiers',
            'fixed_amount'            => 'nullable|numeric|min:0',
            'fixed_target'            => 'nullable|integer|min:0',
            'fixed_deficit_rate'      => 'nullable|numeric|min:0',
            'fixed_bonus_type'        => 'nullable|string|in:lump_sum,per_order',
            'fixed_surplus_bonus'     => 'nullable|numeric|min:0',
            'fixed_surplus_rate'      => 'nullable|numeric|min:0',
            'zone_target_orders'      => 'nullable|integer|min:0',
            'zone_deficit_rate'       => 'nullable|numeric|min:0',
            'zone_bonus_type'         => 'nullable|string|in:lump_sum,per_order',
            'zone_target_bonus'       => 'nullable|numeric|min:0',
            'zone_surplus_rate'       => 'nullable|numeric|min:0',
            'zones'                   => 'nullable|array',
            'tiers'                   => 'nullable|array',
            'hybrid_fixed'            => 'nullable|numeric|min:0',
            'hybrid_tiers'            => 'nullable|array',
            'zones_tiers'             => 'nullable|array',
            'customization_reason'    => 'sometimes|required|string|max:1000',
            'effective_from'          => 'sometimes|required|date',
            'effective_to'            => 'nullable|date|after_or_equal:effective_from',
        ]);

        $from = Carbon::parse($validated['effective_from'] ?? $override->effective_from);
        $to = array_key_exists('effective_to', $validated) 
            ? ($validated['effective_to'] ? Carbon::parse($validated['effective_to']) : null)
            : ($override->effective_to ? Carbon::parse($override->effective_to) : null);
        
        $assignStart = Carbon::parse($assignment->start_date);
        $assignEnd = $assignment->end_date ? Carbon::parse($assignment->end_date) : null;

        if ($from->lt($assignStart) || ($assignEnd && $from->gt($assignEnd))) {
            return response()->json([
                'message' => 'تاريخ بدء التجاوز يجب أن يكون ضمن فترة تعيين العقد للسائق.',
                'errors' => ['effective_from' => ['تاريخ التجاوز خارج نطاق تواريخ التعيين.']]
            ], 422);
        }

        if ($to && ($to->lt($assignStart) || ($assignEnd && $to->gt($assignEnd)))) {
            return response()->json([
                'message' => 'تاريخ نهاية التجاوز يجب أن يكون ضمن فترة تعيين العقد للسائق.',
                'errors' => ['effective_to' => ['تاريخ التجاوز خارج نطاق تواريخ التعيين.']]
            ], 422);
        }

        // Validate overlaps excluding itself
        $overlap = DriverContractOverride::where('contract_assignment_id', $assignment->id)
            ->where('id', '!=', $override->id)
            ->where(function ($query) use ($from, $to) {
                $start = $from->toDateString();
                $end = $to ? $to->toDateString() : '9999-12-31';
                
                $query->where(function ($q) use ($start, $end) {
                    $q->where('effective_from', '<=', $end)
                      ->whereRaw('COALESCE(effective_to, "9999-12-31") >= ?', [$start]);
                });
            })
            ->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'يوجد تجاوز آخر مخصص متداخل في نفس التواريخ لهذا السائق.',
                'errors' => ['effective_from' => ['يوجد تداخل مع فترة تجاوز أخرى.']]
            ], 422);
        }

        $pricingRulesFields = [
            'fixed_amount', 'fixed_target', 'fixed_deficit_rate', 'fixed_bonus_type',
            'fixed_surplus_bonus', 'fixed_surplus_rate', 'zone_target_orders',
            'zone_deficit_rate', 'zone_bonus_type', 'zone_target_bonus', 'zone_surplus_rate',
            'zones', 'tiers', 'hybrid_fixed', 'hybrid_tiers', 'zones_tiers'
        ];
        
        // Merge pricing rules
        $existingRules = $override->custom_pricing_rules ?? [];
        $rules = $existingRules;
        foreach ($pricingRulesFields as $field) {
            if ($request->has($field)) {
                $rules[$field] = $request->input($field);
            }
        }
        $validated['custom_pricing_rules'] = $rules;

        // Legacy mapping for backwards-compatibility & payroll runs
        $oType = $validated['override_type'] ?? $override->override_type;
        if ($oType) {
            $validated['custom_fixed_salary'] = ($oType === 'fixed') ? ($request->has('fixed_amount') ? $request->input('fixed_amount') : $override->custom_fixed_salary) : (($oType === 'hybrid') ? ($request->has('hybrid_fixed') ? $request->input('hybrid_fixed') : $override->custom_fixed_salary) : null);
            $validated['custom_monthly_target'] = ($oType === 'fixed') ? ($request->has('fixed_target') ? $request->input('fixed_target') : $override->custom_monthly_target) : (($oType === 'zones') ? ($request->has('zone_target_orders') ? $request->input('zone_target_orders') : $override->custom_monthly_target) : null);
            $validated['custom_monthly_bonus'] = ($oType === 'fixed') ? ($request->has('fixed_surplus_bonus') ? $request->input('fixed_surplus_bonus') : $override->custom_monthly_bonus) : (($oType === 'zones') ? ($request->has('zone_target_bonus') ? $request->input('zone_target_bonus') : $override->custom_monthly_bonus) : null);
            $validated['custom_order_commission'] = ($oType === 'fixed') ? ($request->has('fixed_deficit_rate') ? $request->input('fixed_deficit_rate') : $override->custom_order_commission) : (($oType === 'zones') ? ($request->has('zone_deficit_rate') ? $request->input('zone_deficit_rate') : $override->custom_order_commission) : null);
        }

        $override->update($validated);

        return response()->json($override);
    }

    public function destroyOverride(DriverContractOverride $override): JsonResponse
    {
        $override->delete();
        return response()->json(['message' => 'تم حذف التجاوز بنجاح.']);
    }
}
