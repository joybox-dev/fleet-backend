<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustodyType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CustodyTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(CustodyType::orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:custody_types,name',
            'icon' => 'nullable|string|max:10',
        ]);

        $type = CustodyType::create($validated);

        return response()->json($type, 201);
    }

    public function update(Request $request, CustodyType $custodyType): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:custody_types,name,' . $custodyType->id,
            'icon' => 'nullable|string|max:10',
        ]);

        $custodyType->update($validated);

        return response()->json($custodyType);
    }

    public function deletionCheck(CustodyType $custodyType): JsonResponse
    {
        $blocks = $custodyType->getDeletionBlocks();
        return response()->json([
            'is_deletable' => empty($blocks),
            'blocks' => $blocks,
        ]);
    }

    public function destroy(CustodyType $custodyType): JsonResponse
    {
        $blocks = $custodyType->getDeletionBlocks();
        if (!empty($blocks)) {
            return response()->json([
                'message' => 'لا يمكن حذف نوع العهدة لوجود ارتباطات نشطة.',
                'errors' => $blocks,
            ], 422);
        }

        $custodyType->delete();

        return response()->json(['message' => 'تم حذف النوع بنجاح.']);
    }
}
