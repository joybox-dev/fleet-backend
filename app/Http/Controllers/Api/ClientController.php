<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
        $validated = $request->validate([
            'name'           => 'required|string|max:255|unique:clients,name',
            'name_ar'        => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone'          => 'nullable|string|max:30|unique:clients,phone',
            'email'          => 'nullable|email|max:255|unique:clients,email',
            'tax_number'     => 'nullable|string|max:50|unique:clients,tax_number',
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
        $validated = $request->validate([
            'name'           => "sometimes|string|max:255|unique:clients,name,{$client->id}",
            'name_ar'        => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone'          => "nullable|string|max:30|unique:clients,phone,{$client->id}",
            'email'          => "nullable|email|max:255|unique:clients,email,{$client->id}",
            'is_active'      => 'sometimes|boolean',
        ]);

        $client->update($validated);

        return response()->json($client->fresh());
    }

    public function destroy(Client $client): JsonResponse
    {
        $client->delete();
        return response()->json(['message' => 'Client deleted.']);
    }
}
