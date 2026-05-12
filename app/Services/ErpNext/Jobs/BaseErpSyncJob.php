<?php

namespace App\Services\ErpNext\Jobs;

use App\Services\ErpNext\ErpNextService;
use App\Services\ErpNext\ErpNextConnectionException;
use App\Services\ErpNext\ErpNextCircuitOpenException;
use App\Services\ErpNext\CompanyErpContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Base class for all ERPNext sync jobs.
 *
 * Design principles:
 * 1. All sync is ASYNC — FleetOps never waits for ERPNext
 * 2. Jobs are IDEMPOTENT — safe to retry
 * 3. Failed jobs update erp_sync_status = 'failed' in FleetOps DB
 * 4. Successful jobs update erp_sync_status = 'synced' with ERP reference
 * 5. Circuit-breaker failures release the job for later retry
 * 6. Multi-tenant: each job resolves the ERPNext company from the entity's company_id
 */
abstract class BaseErpSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying (exponential backoff).
     */
    public function backoff(): array
    {
        return [30, 120, 300]; // 30s, 2min, 5min
    }

    /**
     * The queue this job should run on.
     */
    public function viaQueue(): string
    {
        return config('erpnext.sync.queue', 'erpnext-sync');
    }

    /**
     * Resolve ERPNext company context from a FleetOps company_id.
     * Falls back to global config if company has no ERPNext mapping yet.
     */
    protected function resolveErpContext(?int $companyId): CompanyErpContext
    {
        if ($companyId) {
            return CompanyErpContext::forCompany($companyId);
        }
        return CompanyErpContext::fromGlobalConfig();
    }

    /**
     * Update the sync status of a FleetOps entity.
     */
    protected function updateSyncStatus(string $table, int $id, string $status, ?string $erpId = null): void
    {
        $update = [
            'erp_sync_status' => $status,
            'erp_synced_at'   => now(),
        ];

        if ($erpId) {
            $update['erp_id'] = $erpId;
        }

        DB::table($table)->where('id', $id)->update($update);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('erpnext')->error("ERPNext sync job failed permanently", [
            'job' => static::class,
            'error' => $exception->getMessage(),
        ]);
    }
}
