<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriverGuarantee;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DriverGuaranteeController extends Controller
{
    /**
     * GET /api/guarantees
     */
    public function index(Request $request): JsonResponse
    {
        $query = DriverGuarantee::with('employee:id,name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $guarantees = $query->orderByDesc('received_date')->get();

        return response()->json($guarantees);
    }

    /**
     * POST /api/guarantees
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->can('guarantees.create')) {
            return response()->json(['message' => 'غير مصرح لك بإضافة ضمانة جديدة.'], 403);
        }

        $validated = $request->validate([
            'employee_id'     => 'required|exists:employees,id',
            'guarantee_type'  => 'required|in:passport,civil_id_copy,contract_copy,bank_guarantee,other',
            'document_number' => 'nullable|string|max:100',
            'file_path'       => 'nullable|string',
            'received_date'   => 'required|date',
            'notes'           => 'nullable|string',
        ]);

        $validated['status'] = 'held';

        $guarantee = DriverGuarantee::create($validated);
        $guarantee->load('employee:id,name');

        return response()->json($guarantee, 201);
    }

    /**
     * GET /api/guarantees/{guarantee}
     */
    public function show(DriverGuarantee $guarantee): JsonResponse
    {
        $guarantee->load('employee:id,name');
        return response()->json($guarantee);
    }

    /**
     * POST /api/guarantees/{guarantee}/return
     */
    public function returnItem(Request $request, DriverGuarantee $guarantee): JsonResponse
    {
        if (!$request->user()->can('guarantees.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتسجيل استرجاع الضمانة.'], 403);
        }

        if ($guarantee->status === 'returned') {
            return response()->json(['message' => 'هذه الضمانة مرتجعة بالفعل.'], 422);
        }

        $validated = $request->validate([
            'returned_date' => 'required|date',
            'notes'         => 'nullable|string',
        ]);

        $guarantee->update([
            'status'        => 'returned',
            'returned_date' => $validated['returned_date'],
            'notes'         => $validated['notes'] ?? $guarantee->notes,
        ]);

        $guarantee->load('employee:id,name');

        return response()->json($guarantee);
    }

    /**
     * DELETE /api/guarantees/{guarantee}
     */
    public function destroy(Request $request, DriverGuarantee $guarantee): JsonResponse
    {
        if (!$request->user()->can('guarantees.delete')) {
            return response()->json(['message' => 'غير مصرح لك بحذف الضمانات.'], 403);
        }

        $guarantee->delete();
        return response()->json(['message' => 'تم الحذف.']);
    }
}
