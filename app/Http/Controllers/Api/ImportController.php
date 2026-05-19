<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessImportJob;
use App\Models\ImportLog;
use App\Services\ImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{
    protected ImportService $importService;

    public function __construct(ImportService $importService)
    {
        $this->importService = $importService;
    }

    /**
     * GET /api/import/entity-types
     */
    public function entityTypes()
    {
        return response()->json([
            'entity_types' => $this->importService->entityTypes(),
        ]);
    }

    /**
     * GET /api/import/fields/{entity}
     */
    public function fields(string $entity)
    {
        $fields = $this->importService->getFields($entity);
        if (empty($fields)) {
            return response()->json(['message' => 'نوع الكيان غير مدعوم'], 422);
        }

        return response()->json(['fields' => $fields]);
    }

    /**
     * POST /api/import/upload
     * Upload Excel → parse headers for mapping step.
     * Also checks for duplicate file (same hash + entity + company).
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file'        => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'entity_type' => 'required|in:employees,vehicles',
        ]);

        $file = $request->file('file');

        // Compute file hash for duplicate detection
        $fileHash = hash_file('sha256', $file->getRealPath());

        // Check if this exact file was already imported for this entity+company
        $duplicate = ImportLog::where('file_hash', $fileHash)
            ->where('entity_type', $request->entity_type)
            ->whereIn('status', ['completed', 'processing'])
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' => 'هذا الملف تم استيراده مسبقاً بتاريخ ' .
                    $duplicate->created_at->format('Y-m-d H:i') .
                    '. لا يمكن استيراد نفس الملف مرتين.',
                'duplicate_log' => $duplicate,
            ], 409);
        }

        // Check if there's an import currently processing for this entity
        $inProgress = ImportLog::where('entity_type', $request->entity_type)
            ->where('status', 'processing')
            ->first();

        if ($inProgress) {
            return response()->json([
                'message' => 'يوجد استيراد قيد التنفيذ حالياً لنفس النوع. الرجاء الانتظار.',
                'active_import' => $inProgress,
            ], 409);
        }

        // Store temporarily
        $path = $file->store('imports', 'local');
        $fullPath = Storage::disk('local')->path($path);

        try {
            $parsed = $this->importService->parseFile($fullPath);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            return response()->json([
                'message' => 'فشل في قراءة الملف: ' . $e->getMessage(),
            ], 422);
        }

        $fields = $this->importService->getFields($request->entity_type);

        return response()->json([
            'file_path'     => $path,
            'file_hash'     => $fileHash,
            'filename'      => $file->getClientOriginalName(),
            'headers'       => $parsed['headers'],
            'preview'       => $parsed['preview'],
            'total_rows'    => $parsed['total_rows'],
            'system_fields' => $fields,
        ]);
    }

    /**
     * POST /api/import/preview
     * Validate all rows with the given mapping.
     */
    public function preview(Request $request)
    {
        $request->validate([
            'file_path'   => 'required|string',
            'entity_type' => 'required|in:employees,vehicles',
            'mapping'     => 'required|array',
        ]);

        $fullPath = Storage::disk('local')->path($request->file_path);
        if (!file_exists($fullPath)) {
            return response()->json(['message' => 'الملف غير موجود. أعد الرفع.'], 404);
        }

        $result = $this->importService->previewMapped(
            $fullPath,
            $request->entity_type,
            $request->mapping
        );

        return response()->json($result);
    }

    /**
     * POST /api/import/confirm
     * Create import log + dispatch background job.
     * Returns immediately with import_log ID for polling.
     */
    public function confirm(Request $request)
    {
        $request->validate([
            'file_path'    => 'required|string',
            'file_hash'    => 'required|string',
            'entity_type'  => 'required|in:employees,vehicles',
            'mapping'      => 'required|array',
            'skip_rows'    => 'nullable|array',
            'skip_rows.*'  => 'integer',
        ]);

        $fullPath = Storage::disk('local')->path($request->file_path);
        if (!file_exists($fullPath)) {
            return response()->json(['message' => 'الملف غير موجود. أعد الرفع.'], 404);
        }

        // Double-check duplicate file
        $duplicate = ImportLog::where('file_hash', $request->file_hash)
            ->where('entity_type', $request->entity_type)
            ->whereIn('status', ['completed', 'processing'])
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' => 'هذا الملف تم استيراده مسبقاً.',
            ], 409);
        }

        // Create import log as "pending"
        $importLog = ImportLog::create([
            'user_id'           => $request->user()->id,
            'entity_type'       => $request->entity_type,
            'original_filename' => basename($request->file_path),
            'file_hash'         => $request->file_hash,
            'file_path'         => $request->file_path,
            'column_mapping'    => $request->mapping,
            'status'            => 'pending',
        ]);

        $companyId = app()->bound('current_company_id')
            ? app('current_company_id')
            : $request->user()->company_id;

        // Dispatch to queue (falls back to sync if no queue driver)
        ProcessImportJob::dispatch(
            $importLog->id,
            $request->file_path,
            $request->entity_type,
            $request->mapping,
            $request->skip_rows ?? [],
            $companyId,
        );

        return response()->json([
            'message'    => 'تم بدء الاستيراد في الخلفية',
            'import_log' => $importLog->fresh(),
        ], 202);
    }

    /**
     * GET /api/import/status/{id}
     * Poll for import job completion.
     */
    public function status(int $id)
    {
        $log = ImportLog::find($id);
        if (!$log) {
            return response()->json(['message' => 'السجل غير موجود'], 404);
        }

        return response()->json([
            'import_log'            => $log,
            'is_complete'           => in_array($log->status, ['completed', 'failed']),
            'rows_imported'         => $log->rows_imported,
            'rows_failed'           => $log->rows_failed,
            'rows_skipped_duplicate'=> $log->rows_skipped_duplicate,
            'rows_total'            => $log->rows_total,
            'errors'                => $log->errors ?? [],
        ]);
    }

    /**
     * GET /api/import/logs
     */
    public function logs(Request $request)
    {
        $logs = ImportLog::with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($logs);
    }

    /**
     * GET /api/import/template/{entity}
     */
    public function template(string $entity)
    {
        $path = $this->importService->generateTemplate($entity);
        if (!$path) {
            return response()->json(['message' => 'نوع غير مدعوم'], 422);
        }

        return response()->download($path)->deleteFileAfterSend(true);
    }
}
