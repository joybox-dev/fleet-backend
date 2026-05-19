<?php

namespace App\Jobs;

use App\Models\ImportLog;
use App\Services\ImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

    public function __construct(
        public int    $importLogId,
        public string $filePath,
        public string $entityType,
        public array  $mapping,
        public array  $skipRows,
        public int    $companyId,
    ) {}

    public function handle(ImportService $importService): void
    {
        // Set company context for BelongsToCompany trait
        app()->instance('current_company_id', $this->companyId);

        $importLog = ImportLog::withoutGlobalScope('company')->find($this->importLogId);
        if (!$importLog) return;

        $importLog->update(['status' => 'processing']);

        $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($this->filePath);
        if (!file_exists($fullPath)) {
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
                $this->mapping
            );

            // Execute import
            $importService->executeImport(
                $importLog,
                $previewData['rows'],
                $this->skipRows
            );
        } catch (\Throwable $e) {
            $importLog->update([
                'status' => 'failed',
                'errors' => [['row' => 0, 'errors' => ['exception' => [$e->getMessage()]]]],
            ]);
        } finally {
            // Clean up temp file
            @unlink($fullPath);
        }
    }

    public function failed(\Throwable $e): void
    {
        $importLog = ImportLog::withoutGlobalScope('company')->find($this->importLogId);
        if ($importLog) {
            $importLog->update([
                'status' => 'failed',
                'errors' => [['row' => 0, 'errors' => ['exception' => [$e->getMessage()]]]],
            ]);
        }
    }
}
