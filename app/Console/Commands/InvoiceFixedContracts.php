<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Helpers\ErpSync;
use App\Services\ErpNext\Jobs\SyncFixedContractInvoiceJob;
use Illuminate\Console\Command;

/**
 * InvoiceFixedContracts
 *
 * Monthly artisan command that bills all active fixed-monthly contracts
 * by dispatching a Sales Invoice sync job for each one.
 *
 * Scheduled to run on the last day of every month at 23:00.
 * Can also be triggered manually:
 *
 *   php artisan fleetops:invoice-fixed-contracts
 *   php artisan fleetops:invoice-fixed-contracts --year=2026 --month=04
 *
 * Idempotent: If ERPNext already has the invoice, it will be a duplicate
 * (ERPNext handles duplicate prevention via naming series).
 */
class InvoiceFixedContracts extends Command
{
    protected $signature = 'fleetops:invoice-fixed-contracts
                            {--year= : Billing year (defaults to current)}
                            {--month= : Billing month (defaults to current)}';

    protected $description = 'Generate ERPNext Sales Invoices for all active fixed-monthly contracts';

    public function handle(): int
    {
        $year  = $this->option('year')  ?? now()->year;
        $month = str_pad($this->option('month') ?? now()->month, 2, '0', STR_PAD_LEFT);

        $this->info("📄 Invoicing fixed contracts for {$year}-{$month}...");

        $contracts = Contract::with('client')
            ->where('payment_type', 'fixed_monthly')
            ->where('is_active', true)
            ->where('fixed_monthly', '>', 0)
            ->get();

        if ($contracts->isEmpty()) {
            $this->warn('No active fixed-monthly contracts found.');
            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($contracts as $contract) {
            if (!$contract->client) {
                $this->warn("  ⚠ Contract #{$contract->contract_number} has no client — skipped.");
                continue;
            }

            ErpSync::dispatch(
                SyncFixedContractInvoiceJob::class,
                $contract->id,
                (string) $year,
                $month
            );

            $this->line("  ✅ {$contract->contract_number} — {$contract->name} — {$contract->fixed_monthly} KWD");
            $dispatched++;
        }

        $this->info("Done. Dispatched {$dispatched} invoice job(s) to the queue.");

        return self::SUCCESS;
    }
}
