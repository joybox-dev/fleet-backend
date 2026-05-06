<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ErpSync;
use App\Models\Violation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ViolationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $violations = Violation::with(['employee:id,name', 'vehicle:id,plate_number'])
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->vehicle_id, fn($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->when($request->boolean('undeducted'), fn($q) => $q->where('is_deducted', false)->where('is_driver_liable', true))
            ->orderByDesc('violation_date')
            ->paginate(50);

        return response()->json($violations);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'      => 'required|exists:employees,id',
            'vehicle_id'       => 'required|exists:vehicles,id',
            'violation_date'   => 'required|date',
            'violation_type'   => 'required|string|max:255',
            'reference_number' => 'nullable|string|max:100|unique:violations,reference_number',
            'amount'           => 'required|numeric|min:0',
            'is_driver_liable' => 'boolean',
            'photo_path'       => 'nullable|string',
            'notes'            => 'nullable|string',
        ]);

        $validated['created_by'] = $request->user()->id;

        $violation = Violation::create($validated);

        ErpSync::dispatch(\App\Services\ErpNext\Jobs\SyncViolationJob::class, $violation->id);

        return response()->json($violation->load(['employee:id,name', 'vehicle:id,plate_number']), 201);
    }

    public function show(Violation $violation): JsonResponse
    {
        return response()->json($violation->load(['employee', 'vehicle', 'createdBy:id,name']));
    }

    public function update(Request $request, Violation $violation): JsonResponse
    {
        if ($violation->is_deducted) {
            return response()->json(['message' => 'Cannot edit a violation that has been deducted from payroll.'], 403);
        }

        $validated = $request->validate([
            'violation_type'   => 'sometimes|string|max:255',
            'amount'           => 'sometimes|numeric|min:0',
            'is_driver_liable' => 'sometimes|boolean',
            'photo_path'       => 'nullable|string',
            'notes'            => 'nullable|string',
        ]);

        $violation->update($validated);

        return response()->json($violation->fresh());
    }

    public function destroy(Violation $violation): JsonResponse
    {
        if ($violation->is_deducted) {
            return response()->json(['message' => 'Cannot delete a deducted violation.'], 403);
        }
        $violation->delete();
        return response()->json(['message' => 'Violation deleted.']);
    }
}
