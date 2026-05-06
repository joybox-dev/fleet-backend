<?php

namespace App\Console\Commands;

use App\Services\ErpNext\ErpNextClient;
use App\Services\ErpNext\ErpNextSeeder;
use App\Services\ErpNext\ErpNextAccountResolver;
use Illuminate\Console\Command;

/**
 * Artisan command: php artisan erpnext:setup
 *
 * One-command setup that:
 * 1. Tests ERPNext connectivity
 * 2. Seeds all required Items, Groups, Categories
 * 3. Discovers and caches the Chart of Accounts mapping
 * 4. Displays the resolved account map
 *
 * Run this after installing ERPNext or after a DB reset.
 * Safe to run multiple times (idempotent).
 */
class ErpNextSetupCommand extends Command
{
    protected $signature = 'erpnext:setup
        {--seed-only : Only run the seeder, skip account discovery}
        {--discover-only : Only discover accounts, skip seeding}
        {--refresh : Force refresh the account cache}';

    protected $description = 'Setup ERPNext integration: seed required entities and discover accounts';

    public function handle(): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════╗');
        $this->info('║   FleetOps × ERPNext Bridge Setup        ║');
        $this->info('╚══════════════════════════════════════════╝');
        $this->info('');

        $client = app(ErpNextClient::class);

        // Step 1: Test connection
        $this->info('🔌 Testing ERPNext connection...');
        if (!$client->ping()) {
            $this->error('  ✗ Cannot reach ERPNext at ' . config('erpnext.base_url'));
            $this->error('  Make sure ERPNext is running and the URL is correct.');
            return Command::FAILURE;
        }
        $this->info('  ✓ Connected to ' . config('erpnext.base_url'));
        $this->info('');

        // Step 2: Seed
        if (!$this->option('discover-only')) {
            $this->info('🌱 Seeding ERPNext with FleetOps entities...');

            $seeder = new ErpNextSeeder($client);
            $results = $seeder->seed();

            $created = collect($results)->where('status', 'created')->count();
            $exists = collect($results)->where('status', 'exists')->count();
            $failed = collect($results)->where('status', 'failed')->count();

            // Display results table
            $this->table(
                ['DocType', 'Name', 'Status'],
                collect($results)->map(fn($r) => [
                    $r['doctype'],
                    $r['name'],
                    match ($r['status']) {
                        'created' => '✓ Created',
                        'exists' => '• Already exists',
                        'skipped' => '⚠ Skipped: ' . ($r['error'] ?? ''),
                        'failed' => '✗ Failed: ' . ($r['error'] ?? ''),
                    },
                ])->toArray()
            );

            $this->info("  Summary: {$created} created, {$exists} existing, {$failed} failed");
            $this->info('');
        }

        // Step 3: Discover accounts
        if (!$this->option('seed-only')) {
            $this->info('🔍 Discovering Chart of Accounts...');

            $resolver = new ErpNextAccountResolver($client);
            $accountMap = $this->option('refresh') ? $resolver->refresh() : $resolver->refresh();

            // Display the resolved map
            $this->table(
                ['FleetOps Key', 'ERPNext Account'],
                collect($accountMap)->map(fn($account, $key) => [
                    $key,
                    $account ?: '⚠ Not found',
                ])->toArray()
            );

            $missing = collect($accountMap)->filter(fn($v) => empty($v))->count();
            if ($missing > 0) {
                $this->warn("  ⚠ {$missing} account(s) could not be auto-resolved.");
                $this->warn("  Set them manually in config/erpnext.php or .env");
            } else {
                $this->info('  ✓ All accounts resolved successfully');
            }
            $this->info('');
        }

        $this->info('✅ ERPNext setup complete!');
        $this->info('');

        return Command::SUCCESS;
    }
}
