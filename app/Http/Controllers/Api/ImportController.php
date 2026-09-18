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
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'entity_type' => 'required|in:employees,vehicles',
            'mode' => 'nullable|in:create,upsert',
        ]);

        $file = $request->file('file');

        // Compute file hash for duplicate detection
        $fileHash = hash_file('sha256', $file->getRealPath());

        // Check if this exact file was already imported for this entity+company
        $duplicate = $this->earlierRunOf($fileHash, $request->entity_type, $request->input('mode'));

        if ($duplicate) {
            return response()->json([
                'message' => 'هذا الملف تم استيراده مسبقاً بتاريخ '.
                    $duplicate->created_at->format('Y-m-d H:i').
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
            report($e);
            Storage::disk('local')->delete($path);

            return response()->json([
                'message' => 'تعذّرت قراءة الملف — تأكد أنه ملف Excel سليم (.xlsx) وغير محمي بكلمة مرور، وأن صف العناوين هو الصف الأول.',
            ], 422);
        }

        $fields = $this->importService->getFields($request->entity_type);

        return response()->json([
            'file_path' => $path,
            'file_hash' => $fileHash,
            'filename' => $file->getClientOriginalName(),
            'headers' => $parsed['headers'],
            'preview' => $parsed['preview'],
            'total_rows' => $parsed['total_rows'],
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
            'file_path' => 'required|string',
            'entity_type' => 'required|in:employees,vehicles',
            'mapping' => 'required|array',
            'mode' => 'nullable|in:create,upsert',
        ]);

        $fullPath = Storage::disk('local')->path($request->file_path);
        if (! file_exists($fullPath)) {
            return response()->json(['message' => 'الملف غير موجود. أعد الرفع.'], 404);
        }

        $result = $this->importService->previewMapped(
            $fullPath,
            $request->entity_type,
            $request->mapping,
            $request->input('mode') ?: ImportLog::MODE_CREATE
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
            'file_path' => 'required|string',
            'file_hash' => 'required|string',
            'entity_type' => 'required|in:employees,vehicles',
            'mapping' => 'required|array',
            'skip_rows' => 'nullable|array',
            'skip_rows.*' => 'integer',
            'mode' => 'nullable|in:create,upsert',
            'filename' => 'nullable|string|max:255',
        ]);

        $mode = $request->input('mode') ?: ImportLog::MODE_CREATE;

        $fullPath = Storage::disk('local')->path($request->file_path);
        if (! file_exists($fullPath)) {
            return response()->json(['message' => 'الملف غير موجود. أعد الرفع.'], 404);
        }

        // Double-check duplicate file
        $duplicate = $this->earlierRunOf($request->file_hash, $request->entity_type, $mode);

        if ($duplicate) {
            return response()->json([
                'message' => 'هذا الملف تم استيراده مسبقاً.',
            ], 409);
        }

        // Create import log as "pending"
        $importLog = ImportLog::create([
            'user_id' => $request->user()->id,
            'entity_type' => $request->entity_type,
            'mode' => $mode,
            // The name the person gave the file, not the random one it is stored under.
            'original_filename' => $request->input('filename') ?: basename($request->file_path),
            'file_hash' => $request->file_hash,
            'file_path' => $request->file_path,
            'column_mapping' => $request->mapping,
            'status' => 'pending',
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
            $mode,
        );

        return response()->json([
            'message' => 'تم بدء الاستيراد في الخلفية',
            'import_log' => $importLog->fresh(),
        ], 202);
    }

    /**
     * The run that already handled this exact file, if it should stop another one.
     *
     * Adding the same file twice is refused: it could only produce duplicates to skip. Updating
     * from the same file twice is harmless — the second run finds nothing left to change — so an
     * update is held back only while another run of that file is still working.
     */
    private function earlierRunOf(string $fileHash, string $entityType, ?string $mode): ?ImportLog
    {
        return ImportLog::where('file_hash', $fileHash)
            ->where('entity_type', $entityType)
            ->whereIn('status', $mode === ImportLog::MODE_UPSERT ? ['processing'] : ['completed', 'processing'])
            ->first();
    }

    /**
     * GET /api/import/status/{id}
     * Poll for import job completion.
     */
    public function status(int $id)
    {
        $log = ImportLog::find($id);
        if (! $log) {
            return response()->json(['message' => 'السجل غير موجود'], 404);
        }

        return response()->json([
            'import_log' => $log,
            'is_complete' => in_array($log->status, ['completed', 'failed']),
            'rows_imported' => $log->rows_imported,
            'rows_updated' => $log->rows_updated,
            'mode' => $log->mode,
            'rows_failed' => $log->rows_failed,
            'rows_skipped_duplicate' => $log->rows_skipped_duplicate,
            'rows_total' => $log->rows_total,
            'errors' => $log->errors ?? [],
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
        if (! $path) {
            return response()->json(['message' => 'نوع غير مدعوم'], 422);
        }

        return response()->download($path)->deleteFileAfterSend(true);
    }
}
