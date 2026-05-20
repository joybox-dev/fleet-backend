<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contracts = Contract::with('client:id,name')
            ->when($request->client_id, fn($q) => $q->where('client_id', $request->client_id))
            ->when($request->boolean('active_only'), fn($q) => $q->where('is_active', true))
            ->orderByDesc('start_date')
            ->paginate(50);

        return response()->json($contracts);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id'      => 'required|exists:clients,id',
            'contract_number'=> 'required|string|unique:contracts,contract_number',
            'name'           => 'required|string|max:255',
            'payment_type'   => 'required|in:per_order,fixed,hybrid',
            'rate_per_order' => 'required_if:payment_type,per_order,hybrid|nullable|numeric|min:0',
            'fixed_monthly'  => 'required_if:payment_type,fixed,hybrid|nullable|numeric|min:0',
            'start_date'     => 'required|date',
            'end_date'       => 'nullable|date|after:start_date',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $contract = Contract::create($validated);

        return response()->json($contract->load('client:id,name'), 201);
    }

    public function show(Contract $contract): JsonResponse
    {
        return response()->json($contract->load(['client', 'dailyLogs' => fn($q) => $q->latest('log_date')->limit(10)]));
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        // From meeting: contract data cannot be changed after locking
        if ($contract->is_locked) {
            return response()->json(['message' => 'Contract is locked and cannot be modified.'], 403);
        }

        $validated = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'rate_per_order' => 'sometimes|nullable|numeric|min:0',
            'fixed_monthly'  => 'sometimes|nullable|numeric|min:0',
            'end_date'       => 'nullable|date',
            'is_active'      => 'sometimes|boolean',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $contract->update($validated);

        return response()->json($contract->fresh());
    }

    public function destroy(Contract $contract): JsonResponse
    {
        if ($contract->is_locked) {
            return response()->json(['message' => 'Cannot delete a locked contract.'], 403);
        }
        $contract->delete();
        return response()->json(['message' => 'Contract deleted.']);
    }

    /**
     * POST /api/contracts/{contract}/lock
     * Permanently lock contract data per anti-tampering requirement.
     */
    public function lock(Contract $contract): JsonResponse
    {
        if ($contract->is_locked) {
            return response()->json(['message' => 'Contract is already locked.'], 422);
        }

        $contract->update(['is_locked' => true]);

        return response()->json(['message' => 'Contract locked successfully. Financial data is now immutable.']);
    }
}
