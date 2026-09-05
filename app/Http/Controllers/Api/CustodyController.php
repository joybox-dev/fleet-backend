<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ErpSync;
use App\Http\Controllers\Controller;
use App\Models\CustodyItem;
use App\Services\ErpNext\Jobs\SyncCustodyJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustodyController extends Controller
{
    /**
     * `exists` and `unique` do not know about the tenant scope, so every id and every serial on this
     * screen was checked against the whole installation: one company could attach another company's
     * employee or custody type, and a serial another company had used was permanently unavailable.
     */
    private function tenantRules(int $companyId): array
    {
        return [
            'employee_id' => [
                'required',
                Rule::exists('employees', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'custody_type_id' => [
                'nullable',
                Rule::exists('custody_types', 'id')->where('company_id', $companyId),
            ],
            // Serials are only unique inside the company, and a returned-then-deleted item must not
            // burn its serial forever.
            'serial_number' => [
                'nullable', 'string', 'max:100',
                Rule::unique('custody_items', 'serial_number')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $items = CustodyItem::with(['employee:id,name,name_ar,employee_number', 'issuedBy:id,name', 'custodyType:id,name,icon'])
            ->when($request->employee_id, fn ($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->item_type, fn ($q) => $q->where('item_type', $request->item_type))
            ->when($request->custody_type_id, fn ($q) => $q->where('custody_type_id', $request->custody_type_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->boolean('not_returned'), fn ($q) => $q->where('status', '!=', 'returned'))
            ->orderByDesc('issued_date')
            ->paginate(50);

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('custody.create')) {
            return response()->json(['message' => 'غير مصرح لك بتسليم عُهدة جديدة.'], 403);
        }

        $validated = $request->validate($this->tenantRules(app('current_company_id')) + [
            'item_type' => 'nullable|in:phone,sim,clothing,cash,other',
            'item_description' => 'nullable|string|max:255',
            'value' => 'nullable|numeric|min:0',
            'issued_date' => 'required|date',
            'notes' => 'nullable|string',
            // What the driver signed for. Without it the value charged when an item is lost traces
            // back to nothing but a number typed at handover.
            'handover_proof_path' => 'required|string|max:255',
        ]);

        $validated['issued_by'] = $request->user()->id;
        $validated['status'] = 'active';

        $item = CustodyItem::create($validated);

        ErpSync::dispatch(SyncCustodyJob::class, $item->id, 'issue');

        return response()->json($item->load(['employee:id,name', 'custodyType:id,name,icon']), 201);
    }

    public function show(CustodyItem $custody): JsonResponse
    {
        return response()->json($custody->load(['employee', 'issuedBy:id,name', 'custodyType:id,name,icon']));
    }

    public function update(Request $request, CustodyItem $custody): JsonResponse
    {
        if (! $request->user()->can('custody.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتعديل بيانات العُهدة.'], 403);
        }

        if ($custody->status === 'returned') {
            return response()->json(['message' => 'لا يمكن تعديل عُهدة تم إرجاعها.'], 422);
        }

        $validated = $request->validate([
            'item_description' => 'nullable|string',
            'status' => 'nullable|in:active,held',
            'notes' => 'nullable|string',
        ]);

        $custody->update($validated);

        return response()->json($custody->fresh());
    }

    public function destroy(Request $request, CustodyItem $custody): JsonResponse
    {
        if (! $request->user()->can('custody.delete')) {
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
        if (! $request->user()->can('custody.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتسجيل استرجاع العُهد.'], 403);
        }

        if ($custody->status === 'returned') {
            return response()->json(['message' => 'تم إرجاع العُهدة مسبقاً.'], 422);
        }

        $validated = $request->validate([
            'returned_date' => 'required|date',
            'return_condition' => 'required|in:good,damaged,lost',
            'deduction_amount' => 'nullable|numeric|min:0',
            // Charging a driver for damage or a loss needs the damage or the loss on record.
            'return_proof_path' => 'nullable|string|max:255|required_if:return_condition,damaged,lost',
            'notes' => 'nullable|string',
        ]);

        $custody->update([
            'returned_date' => $validated['returned_date'],
            'return_condition' => $validated['return_condition'],
            'deduction_amount' => $validated['deduction_amount'] ?? 0,
            'status' => 'returned',
            'notes' => $validated['notes'] ?? $custody->notes,
        ]);

        ErpSync::dispatch(SyncCustodyJob::class, $custody->id, 'return');

        return response()->json(['message' => 'تم إرجاع العُهدة.', 'item' => $custody->fresh()]);
    }
}
