<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KetaImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KetaImportController extends Controller
{
    protected KetaImportService $importService;

    public function __construct(KetaImportService $importService)
    {
        $this->importService = $importService;
    }

    /**
     * Upload and preview the Keeta Excel report.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls'
        ]);

        $file = $request->file('file');
        $filePath = $file->getRealPath();

        $previewResult = $this->importService->previewFile($filePath);

        if (!$previewResult['success']) {
            return response()->json([
                'message' => $previewResult['message'] ?? 'فشل في قراءة ملف كيتا.'
            ], 422);
        }

        return response()->json($previewResult);
    }

    /**
     * Confirm and commit the previewed rows.
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'rows' => 'required|array',
            'rows.*.date' => 'required|date',
            'rows.*.courier_id' => 'required|string',
            'rows.*.shift_valid' => 'required|boolean',
            'rows.*.online_hours' => 'required|numeric',
            'rows.*.orders_count' => 'required|integer',
            'rows.*.ontime_rate' => 'nullable|numeric',
            'rows.*.avg_delivery_time' => 'nullable|integer',
            'rows.*.employee_id' => 'nullable|integer',
            'rows.*.contract_id' => 'nullable|integer',
        ]);

        $rows = $request->input('rows');

        $result = $this->importService->commitImport($rows);

        if (!$result['success']) {
            return response()->json([
                'message' => $result['message'] ?? 'فشل في حفظ سجلات كيتا.',
                'errors' => $result['errors'] ?? []
            ], 422);
        }

        return response()->json([
            'message' => 'تم استيراد سجلات العمل اليومية بنجاح.',
            'imported' => $result['imported'],
            'failed' => $result['failed'],
            'errors' => $result['errors']
        ]);
    }
}
