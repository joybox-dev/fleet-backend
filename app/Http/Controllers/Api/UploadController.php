<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    /**
     * POST /api/upload
     * Generic file upload — returns path to use in other endpoints.
     * Supports: violation photos, maintenance photos/invoices, receipt photos.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file'     => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240', // 10MB max
            'category' => 'required|in:violations,maintenance,receipts,expenses,custody,documents,handovers',
        ]);

        $file     = $request->file('file');
        $category = $validated['category'];
        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

        $path = $file->storeAs("uploads/{$category}", $filename, 'public');

        return response()->json([
            'path'     => $path,
            'url'      => Storage::disk('public')->url($path),
            'filename' => $file->getClientOriginalName(),
            'size'     => $file->getSize(),
            'mime'     => $file->getMimeType(),
        ], 201);
    }

    /**
     * POST /api/upload/multiple
     * Bulk upload — for maintenance photos (multiple photos per record).
     */
    public function storeMultiple(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'files'    => 'required|array|min:1|max:10',
            'files.*'  => 'file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'category' => 'required|in:violations,maintenance,receipts,expenses,custody,documents,handovers',
        ]);

        $category = $validated['category'];
        $results  = [];

        foreach ($request->file('files') as $file) {
            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs("uploads/{$category}", $filename, 'public');

            $results[] = [
                'path'     => $path,
                'url'      => Storage::disk('public')->url($path),
                'filename' => $file->getClientOriginalName(),
                'size'     => $file->getSize(),
            ];
        }

        return response()->json(['files' => $results], 201);
    }
}
