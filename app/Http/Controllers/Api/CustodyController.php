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
        $items = CustodyItem::with(['employee:id,name', 'issuedBy:id,name'])
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->item_type, fn($q) => $q->where('item_type', $request->item_type))
            ->when($request->boolean('not_returned'), fn($q) => $q->where('is_returned', false))
            ->orderByDesc('issued_date')
            ->paginate(50);

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'     => 'required|exists:employees,id',
            'item_type'       => 'required|in:phone,sim,clothing,cash,other',
            'item_description'=> 'nullable|string|max:255',
            'serial_number'   => 'nullable|string|max:100|unique:custody_items,serial_number',
            'value'           => 'nullable|numeric|min:0',
            'issued_date'     => 'required|date',
            'notes'           => 'nullable|string',
        ]);

        $validated['issued_by'] = $request->user()->id;

        $item = CustodyItem::create($validated);

        ErpSync::dispatch(\App\Services\ErpNext\Jobs\SyncCustodyJob::class, $item->id, 'issue');

        return response()->json($item->load('employee:id,name'), 201);
    }

    public function show(CustodyItem $custody): JsonResponse
    {
        return response()->json($custody->load(['employee', 'issuedBy:id,name']));
    }

    public function update(Request $request, CustodyItem $custody): JsonResponse
    {
        if ($custody->is_returned) {
            return response()->json(['message' => 'Item already returned.'], 422);
        }

        $validated = $request->validate([
            'item_description' => 'nullable|string',
            'notes'            => 'nullable|string',
        ]);

        $custody->update($validated);
        return response()->json($custody->fresh());
    }

    public function destroy(CustodyItem $custody): JsonResponse
    {
        $custody->delete();
        return response()->json(['message' => 'Custody item deleted.']);
    }

    /**
     * POST /api/custody/{custody}/return
     */
    public function returnItem(Request $request, CustodyItem $custody): JsonResponse
    {
        if ($custody->is_returned) {
            return response()->json(['message' => 'Item already returned.'], 422);
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
            'is_returned'      => true,
            'notes'            => $validated['notes'] ?? $custody->notes,
        ]);

        ErpSync::dispatch(\App\Services\ErpNext\Jobs\SyncCustodyJob::class, $custody->id, 'return');

        return response()->json(['message' => 'Item returned.', 'item' => $custody->fresh()]);
    }
}
