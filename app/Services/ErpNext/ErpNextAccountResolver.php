<?php

namespace App\Services\ErpNext;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ERPNext Account Resolver
 *
 * Dynamically discovers and caches ERPNext Chart of Accounts.
 * No more hardcoded account names — the bridge auto-detects them.
 *
 * Flow:
 * 1. On first call (or cache miss), fetches all accounts from ERPNext
 * 2. Matches accounts by account_type (Cash, Receivable, Payable, etc.)
 * 3. Caches the mapping for 24 hours
 * 4. Falls back to config defaults if ERPNext is unreachable
 *
 * Usage:
 *   $resolver = app(ErpNextAccountResolver::class);
 *   $cashAccount = $resolver->get('cash_in_hand');
 */
class ErpNextAccountResolver
{
    private ErpNextClient $client;

    /** Cache key for the account map */
    private const CACHE_KEY = 'erpnext_account_map';

    /** Cache TTL in seconds (24 hours) */
    private const CACHE_TTL = 86400;

    /**
     * Maps our internal keys → ERPNext account_type + fallback logic.
     * Priority: exact account_type match → name pattern match → config fallback.
     */
    private const ACCOUNT_RULES = [
        'delivery_income' => [
            'match_type' => 'account_type',
            'values' => [],
            'match_name' => ['Service', 'Income'],
            'root_type' => 'Income',
            'config_key' => 'erpnext.accounts.delivery_income',
        ],
        'cash_in_hand' => [
            'match_type' => 'account_type',
            'values' => ['Cash'],
            'match_name' => ['Cash'],
            'root_type' => 'Asset',
            'config_key' => 'erpnext.accounts.cash_in_hand',
        ],
        'pending_cash' => [
            'match_type' => 'account_type',
            'values' => ['Receivable'],
            'match_name' => ['Debtors', 'Receivable'],
            'root_type' => 'Asset',
            'config_key' => 'erpnext.accounts.pending_cash',
        ],
        'salary_expense' => [
            'match_type' => 'name_contains',
            'values' => [],
            'match_name' => ['Salary'],
            'root_type' => 'Expense',
            'config_key' => 'erpnext.accounts.salary_expense',
        ],
        'maintenance_expense' => [
            'match_type' => 'name_contains',
            'values' => [],
            'match_name' => ['Maintenance', 'Repair'],
            'root_type' => 'Expense',
            'config_key' => 'erpnext.accounts.maintenance_expense',
        ],
        'fuel_expense' => [
            'match_type' => 'name_contains',
            'values' => [],
            'match_name' => ['Fuel', 'Travel', 'Transportation'],
            'root_type' => 'Expense',
            'config_key' => 'erpnext.accounts.fuel_expense',
        ],
        'insurance_expense' => [
            'match_type' => 'name_contains',
            'values' => [],
            'match_name' => ['Insurance', 'Administrative'],
            'root_type' => 'Expense',
            'config_key' => 'erpnext.accounts.insurance_expense',
        ],
        'violation_receivable' => [
            'match_type' => 'account_type',
            'values' => ['Receivable'],
            'match_name' => ['Debtors', 'Receivable'],
            'root_type' => 'Asset',
            'config_key' => 'erpnext.accounts.violation_receivable',
        ],
        'accounts_payable' => [
            'match_type' => 'account_type',
            'values' => ['Payable'],
            'match_name' => ['Creditors', 'Payable'],
            'root_type' => 'Liability',
            'config_key' => 'erpnext.accounts.accounts_payable',
        ],
        'vehicle_asset' => [
            'match_type' => 'account_type',
            'values' => ['Fixed Asset'],
            'match_name' => ['Capital Equipment', 'Vehicle', 'Fixed Asset'],
            'root_type' => 'Asset',
            'config_key' => 'erpnext.accounts.vehicle_asset',
        ],
        'depreciation' => [
            'match_type' => 'account_type',
            'values' => ['Accumulated Depreciation'],
            'match_name' => ['Depreciation'],
            'root_type' => 'Asset',
            'config_key' => 'erpnext.accounts.depreciation',
        ],
    ];

    public function __construct(ErpNextClient $client)
    {
        $this->client = $client;
    }

    /**
     * Get an ERPNext account name by our internal key.
     *
     * @param string $key Internal key (e.g., 'cash_in_hand', 'salary_expense')
     * @return string ERPNext account name (e.g., '1110 - Cash - FO')
     */
    public function get(string $key): string
    {
        $map = $this->getAccountMap();
        return $map[$key] ?? config(self::ACCOUNT_RULES[$key]['config_key'] ?? "erpnext.accounts.{$key}", '');
    }

    /**
     * Get all resolved accounts as key => ERPNext name.
     */
    public function all(): array
    {
        return $this->getAccountMap();
    }

    /**
     * Force refresh the account cache from ERPNext.
     *
     * @return array The resolved account map
     */
    public function refresh(): array
    {
        Cache::forget(self::CACHE_KEY);
        return $this->getAccountMap();
    }

    /**
     * Get the cached account map, or discover from ERPNext.
     */
    private function getAccountMap(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            try {
                return $this->discoverAccounts();
            } catch (\Exception $e) {
                Log::channel('erpnext')->warning('Account discovery failed, using config defaults', [
                    'error' => $e->getMessage(),
                ]);
                return $this->getConfigDefaults();
            }
        });
    }

    /**
     * Discover accounts from ERPNext by fetching the Chart of Accounts.
     */
    private function discoverAccounts(): array
    {
        $company = config('erpnext.company');

        // Fetch all leaf accounts for our company
        $accounts = $this->client->listDocuments('Account', [
            ['company', '=', $company],
            ['is_group', '=', 0],
        ], ['name', 'account_name', 'account_type', 'root_type', 'is_group', 'parent_account'], 200);

        $map = [];

        foreach (self::ACCOUNT_RULES as $key => $rule) {
            $map[$key] = $this->resolveAccount($accounts, $rule);
        }

        Log::channel('erpnext')->info('Account discovery completed', $map);

        return $map;
    }

    /**
     * Resolve a single account using the rule definition.
     */
    private function resolveAccount(array $accounts, array $rule): string
    {
        // Step 1: Try matching by account_type
        if ($rule['match_type'] === 'account_type' && !empty($rule['values'])) {
            foreach ($accounts as $account) {
                if (in_array($account['account_type'], $rule['values'])) {
                    // If multiple matches, prefer the one whose name matches our patterns
                    foreach ($rule['match_name'] as $pattern) {
                        if (stripos($account['name'], $pattern) !== false ||
                            stripos($account['account_name'] ?? '', $pattern) !== false) {
                            return $account['name'];
                        }
                    }
                }
            }
            // Fallback: return first match by type
            foreach ($accounts as $account) {
                if (in_array($account['account_type'], $rule['values'])) {
                    return $account['name'];
                }
            }
        }

        // Step 2: Try matching by name pattern
        foreach ($rule['match_name'] as $pattern) {
            foreach ($accounts as $account) {
                $rootMatch = empty($rule['root_type']) || ($account['root_type'] ?? '') === $rule['root_type'];
                if ($rootMatch && (
                    stripos($account['name'], $pattern) !== false ||
                    stripos($account['account_name'] ?? '', $pattern) !== false
                )) {
                    return $account['name'];
                }
            }
        }

        // Step 3: Fall back to config
        return config($rule['config_key'], '');
    }

    /**
     * Build defaults from config when ERPNext is unreachable.
     */
    private function getConfigDefaults(): array
    {
        $map = [];
        foreach (self::ACCOUNT_RULES as $key => $rule) {
            $map[$key] = config($rule['config_key'], '');
        }
        return $map;
    }
}
