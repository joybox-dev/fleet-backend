<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AuditLog;

class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune {--days=90}';
    protected $description = 'Prune audit logs older than specified days (default 90 days)';

    public function handle()
    {
        $days = (int) $this->option('days');
        $deleted = AuditLog::where('created_at', '<', now()->subDays($days))->delete();
        $this->info("Pruned {$deleted} audit log records older than {$days} days.");
    }
}
