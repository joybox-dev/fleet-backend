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
            'employee_id' => 'required|exists:employees,id',
            'contract_id' => 'required|exists:contracts,id',
            'start_date'  => 'required|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            'courier_id'  => 'nullable|string|max:255',
            'status'      => 'required|in:active,inactive',
        ]);

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
            'custom_order_commission' => 'nullable|numeric|min:0',
            'custom_hourly_rate'      => 'nullable|numeric|min:0',
            'custom_fixed_salary'     => 'nullable|numeric|min:0',
            'custom_monthly_target'    => 'nullable|integer|min:0',
            'custom_monthly_bonus'     => 'nullable|numeric|min:0',
            'custom_valid_days'        => 'nullable|integer|min:0',
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

        $validated['company_id'] = $companyId;
        $validated['contract_assignment_id'] = $assignment->id;
        
        $override = DriverContractOverride::create($validated);

        return response()->json($override, 201);
    }

    public function updateOverride(Request $request, DriverContractOverride $override): JsonResponse
    {
        $assignment = $override->contractAssignment;
        
        $validated = $request->validate([
            'custom_order_commission' => 'nullable|numeric|min:0',
            'custom_hourly_rate'      => 'nullable|numeric|min:0',
            'custom_fixed_salary'     => 'nullable|numeric|min:0',
            'custom_monthly_target'    => 'nullable|integer|min:0',
            'custom_monthly_bonus'     => 'nullable|numeric|min:0',
            'custom_valid_days'        => 'nullable|integer|min:0',
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

        $override->update($validated);

        return response()->json($override);
    }

    public function destroyOverride(DriverContractOverride $override): JsonResponse
    {
        $override->delete();
        return response()->json(['message' => 'تم حذف التجاوز بنجاح.']);
    }
}
