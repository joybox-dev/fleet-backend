<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

/**
 * Safe dispatcher for ERPNext sync jobs.
 * Catches any errors (missing classes, wrong signatures) so that
 * core FleetOps functionality is never blocked by bridge issues.
 */
class ErpSync
{
    public static function dispatch(string $jobClass, mixed ...$args): void
    {
        try {
            if (class_exists($jobClass)) {
                dispatch(new $jobClass(...$args));
            }
        } catch (\Throwable $e) {
            Log::warning("[ERPNext Sync] Failed to dispatch {$jobClass}: {$e->getMessage()}");
        }
    }
}
