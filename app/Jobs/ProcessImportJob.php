<?php

namespace App\Jobs;

use App\Models\ImportLog;
use App\Services\ImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Background job for processing Excel imports.
 * Runs in the queue so the HTTP request returns immediately.
 * If no queue driver is configured, falls back to sync.
 */
class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300; // 5 minutes max

    /**
     * Declared with its default rather than promoted: a job queued before this property existed
     * is unserialized without it, and must still run as the add-only import it was.
     */
    public string $mode = ImportLog::MODE_CREATE;

    public function __construct(
        public int $importLogId,
        public string $filePath,
        public string $entityType,
        public array $mapping,
        public array $skipRows,
        public int $companyId,
        string $mode = ImportLog::MODE_CREATE,
    ) {
        $this->mode = $mode;
    }

    public function handle(ImportService $importService): void
    {
        // Set company context for BelongsToCompany trait
        app()->instance('current_company_id', $this->companyId);

        $importLog = ImportLog::withoutGlobalScope('company')->find($this->importLogId);
        if (! $importLog) {
            return;
        }

        $importLog->update(['status' => 'processing']);

        $fullPath = Storage::disk('local')->path($this->filePath);
        if (! file_exists($fullPath)) {
            $importLog->update([
                'status' => 'failed',
                'errors' => [['row' => 0, 'errors' => ['file' => ['الملف غير موجود']]]],
            ]);

            return;
        }

        try {
            // Get preview data with validation
            $previewData = $importService->previewMapped(
                $fullPath,
                $this->entityType,
                $this->mapping,
                $this->mode
            );

            // Execute import
            $importService->executeImport(
                $importLog,
                $previewData['rows'],
                $this->skipRows,
                $this->mode
            );
        } catch (\Throwable $e) {
            report($e);
            $importLog->update([
                'status' => 'failed',
                'errors' => [['row' => 0, 'errors' => ['exception' => [self::UNREADABLE]]]],
            ]);
        } finally {
            // Clean up temp file
            @unlink($fullPath);
        }
    }

    /** Said to the person importing; the cause itself goes to the log. */
    private const UNREADABLE = 'تعذّرت قراءة الملف أو معالجته — تأكد أنه ملف Excel سليم وأن صف العناوين هو الصف الأول، ثم أعد المحاولة. سُجِّل الخطأ للمراجعة الفنية.';

    public function failed(\Throwable $e): void
    {
        $importLog = ImportLog::withoutGlobalScope('company')->find($this->importLogId);
        if ($importLog) {
            $importLog->update([
                'status' => 'failed',
                'errors' => [['row' => 0, 'errors' => ['exception' => [self::UNREADABLE]]]],
            ]);
        }
    }
}
