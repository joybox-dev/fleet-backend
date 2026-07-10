<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientCollection;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ClientCollectionController extends Controller
{
    public function index($contractId): JsonResponse
    {
        $companyId = app('current_company_id');
        $contract = Contract::where('company_id', $companyId)->findOrFail($contractId);
        
        $collections = ClientCollection::where('contract_id', $contract->id)->orderByDesc('date')->get();
        return response()->json($collections);
    }

    public function store(Request $request, $contractId): JsonResponse
    {
        $companyId = app('current_company_id');
        $contract = Contract::where('company_id', $companyId)->findOrFail($contractId);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.001',
            'date' => 'required|date',
            'payment_method' => 'required|string|in:bank_transfer,cash,cheque',
            'notes' => 'nullable|string|max:255',
        ]);

        $validated['company_id'] = $companyId;
        $validated['contract_id'] = $contract->id;

        $collection = ClientCollection::create($validated);

        return response()->json($collection, 201);
    }

    public function destroy($contractId, $id): JsonResponse
    {
        $companyId = app('current_company_id');
        $contract = Contract::where('company_id', $companyId)->findOrFail($contractId);
        $collection = ClientCollection::where('contract_id', $contract->id)->findOrFail($id);
        
        $collection->delete();
        return response()->json(['message' => 'تم حذف دفعة العميل بنجاح.']);
    }
}
