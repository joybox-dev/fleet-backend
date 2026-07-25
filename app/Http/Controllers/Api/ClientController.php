<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clients = Client::query()
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->boolean('active_only'), fn($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(50);

        return response()->json($clients);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');

        $validated = $request->validate([
            'name'           => [
                'required',
                'string',
                'max:255',
                Rule::unique('clients', 'name')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'name_ar'        => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone'          => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('clients', 'phone')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'email'          => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('clients', 'email')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'tax_number'     => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('clients', 'tax_number')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
        ]);

        $client = Client::create($validated);

        return response()->json($client, 201);
    }

    public function show(Client $client): JsonResponse
    {
        return response()->json($client->load('contracts'));
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $companyId = app('current_company_id');

        $validated = $request->validate([
            'name'           => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('clients', 'name')->ignore($client->id)->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'name_ar'        => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone'          => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('clients', 'phone')->ignore($client->id)->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'email'          => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('clients', 'email')->ignore($client->id)->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'is_active'      => 'sometimes|boolean',
        ]);

        $client->update($validated);

        return response()->json($client->fresh());
    }

    public function deletionCheck(Client $client): JsonResponse
    {
        $blocks = $client->getDeletionBlocks();
        return response()->json([
            'is_deletable' => empty($blocks),
            'blocks' => $blocks,
        ]);
    }

    public function destroy(Client $client): JsonResponse
    {
        $blocks = $client->getDeletionBlocks();
        if (!empty($blocks)) {
            return response()->json([
                'message' => 'لا يمكن حذف العميل لوجود ارتباطات نشطة.',
                'errors' => $blocks,
            ], 422);
        }

        $client->delete();
        return response()->json(['message' => 'Client deleted.']);
    }
}
