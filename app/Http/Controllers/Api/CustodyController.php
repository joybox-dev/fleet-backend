<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ErpSync;
use App\Models\CustodyItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CustodyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = CustodyItem::with(['employee:id,name,name_ar,employee_number', 'issuedBy:id,name', 'custodyType:id,name,icon'])
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->item_type, fn($q) => $q->where('item_type', $request->item_type))
            ->when($request->custody_type_id, fn($q) => $q->where('custody_type_id', $request->custody_type_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->boolean('not_returned'), fn($q) => $q->where('status', '!=', 'returned'))
            ->orderByDesc('issued_date')
            ->paginate(50);

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->can('custody.create')) {
            return response()->json(['message' => 'غير مصرح لك بتسليم عُهدة جديدة.'], 403);
        }

        $validated = $request->validate([
            'employee_id'     => 'required|exists:employees,id',
            'item_type'       => 'nullable|in:phone,sim,clothing,cash,other',
            'custody_type_id' => 'nullable|exists:custody_types,id',
            'item_description'=> 'nullable|string|max:255',
            'serial_number'   => 'nullable|string|max:100|unique:custody_items,serial_number',
            'value'           => 'nullable|numeric|min:0',
            'issued_date'     => 'required|date',
            'notes'           => 'nullable|string',
        ]);

        $validated['issued_by'] = $request->user()->id;
        $validated['status'] = 'active';

        $item = CustodyItem::create($validated);

        ErpSync::dispatch(\App\Services\ErpNext\Jobs\SyncCustodyJob::class, $item->id, 'issue');

        return response()->json($item->load(['employee:id,name', 'custodyType:id,name,icon']), 201);
    }

    public function show(CustodyItem $custody): JsonResponse
    {
        return response()->json($custody->load(['employee', 'issuedBy:id,name', 'custodyType:id,name,icon']));
    }

    public function update(Request $request, CustodyItem $custody): JsonResponse
    {
        if (!$request->user()->can('custody.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتعديل بيانات العُهدة.'], 403);
        }

        if ($custody->status === 'returned') {
            return response()->json(['message' => 'لا يمكن تعديل عُهدة تم إرجاعها.'], 422);
        }

        $validated = $request->validate([
            'item_description' => 'nullable|string',
            'status'           => 'nullable|in:active,held',
            'notes'            => 'nullable|string',
        ]);

        $custody->update($validated);
        return response()->json($custody->fresh());
    }

    public function destroy(Request $request, CustodyItem $custody): JsonResponse
    {
        if (!$request->user()->can('custody.delete')) {
            return response()->json(['message' => 'غير مصرح لك بحذف العُهد.'], 403);
        }

        $custody->delete();
        return response()->json(['message' => 'Custody item deleted.']);
    }

    /**
     * POST /api/custody/{custody}/return
     */
    public function returnItem(Request $request, CustodyItem $custody): JsonResponse
    {
        if (!$request->user()->can('custody.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتسجيل استرجاع العُهد.'], 403);
        }

        if ($custody->status === 'returned') {
            return response()->json(['message' => 'تم إرجاع العُهدة مسبقاً.'], 422);
        }

        $validated = $request->validate([
            'returned_date'    => 'required|date',
            'return_condition' => 'required|in:good,damaged,lost',
            'deduction_amount' => 'nullable|numeric|min:0',
            'notes'            => 'nullable|string',
        ]);

        $custody->update([
            'returned_date'    => $validated['returned_date'],
            'return_condition' => $validated['return_condition'],
            'deduction_amount' => $validated['deduction_amount'] ?? 0,
            'status'           => 'returned',
            'notes'            => $validated['notes'] ?? $custody->notes,
        ]);

        ErpSync::dispatch(\App\Services\ErpNext\Jobs\SyncCustodyJob::class, $custody->id, 'return');

        return response()->json(['message' => 'تم إرجاع العُهدة.', 'item' => $custody->fresh()]);
    }
}
