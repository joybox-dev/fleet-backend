<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeDocumentController extends Controller
{
    /**
     * GET /api/employees/{employee_id}/documents
     */
    public function index(int $employeeId, Request $request): JsonResponse
    {
        $docs = EmployeeDocument::where('employee_id', $employeeId)
            ->when($request->type, fn($q) => $q->where('document_type', $request->type))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->orderByDesc('expiry_date')
            ->get();

        // Auto-refresh status based on current date
        $docs->each(function ($doc) {
            $oldStatus = $doc->status;
            $doc->refreshStatus();
            if ($doc->status !== $oldStatus) {
                $doc->save();
            }
        });

        return response()->json($docs);
    }

    /**
     * POST /api/employees/{employee_id}/documents
     */
    public function store(int $employeeId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type'   => 'required|string|in:' . implode(',', EmployeeDocument::TYPES),
            'document_number' => 'nullable|string|max:100',
            'file_path'       => 'nullable|string|max:500',
            'issue_date'      => 'nullable|date',
            'expiry_date'     => 'nullable|date',
            'notes'           => 'nullable|string',
        ]);

        $validated['employee_id'] = $employeeId;

        $doc = EmployeeDocument::create($validated);
        $doc->refreshStatus();
        $doc->save();

        return response()->json($doc, 201);
    }

    /**
     * PUT /api/employees/{employee_id}/documents/{id}
     */
    public function update(int $employeeId, int $id, Request $request): JsonResponse
    {
        $doc = EmployeeDocument::where('employee_id', $employeeId)->findOrFail($id);

        $validated = $request->validate([
            'document_type'   => 'sometimes|string|in:' . implode(',', EmployeeDocument::TYPES),
            'document_number' => 'nullable|string|max:100',
            'file_path'       => 'nullable|string|max:500',
            'issue_date'      => 'nullable|date',
            'expiry_date'     => 'nullable|date',
            'status'          => 'sometimes|in:valid,expired,pending_renewal',
            'notes'           => 'nullable|string',
        ]);

        $doc->update($validated);
        $doc->refreshStatus();
        $doc->save();

        return response()->json($doc);
    }

    /**
     * DELETE /api/employees/{employee_id}/documents/{id}
     */
    public function destroy(int $employeeId, int $id): JsonResponse
    {
        $doc = EmployeeDocument::where('employee_id', $employeeId)->findOrFail($id);
        $doc->delete();

        return response()->json(['message' => 'Document deleted.']);
    }
}
