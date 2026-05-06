<?php

namespace App\Services\ErpNext;

use Illuminate\Support\Facades\Log;

/**
 * ERPNext Seeder
 *
 * Creates all required ERPNext entities that the FleetOps bridge depends on.
 * Run this once when setting up a new ERPNext instance, or after a reset.
 *
 * What it seeds:
 * 1. Items: DELIVERY-SERVICE, CUSTODY-PHONE, CUSTODY-SIM, CUSTODY-CLOTHING, CUSTODY-OTHER, CUSTODY-CASH
 * 2. Item Groups: Services, Custody Items (if missing)
 * 3. Supplier Group: Garages (for maintenance payments)
 * 4. Customer Group: Delivery Brands (for clients like Yalla Go, Keeta)
 * 5. Salary Structure: FleetOps Official Salary (for bank payroll)
 * 6. Asset Category: Vehicles
 * 7. Warehouse: FleetOps Custody Store
 *
 * All creations are IDEMPOTENT — safe to run multiple times.
 */
class ErpNextSeeder
{
    private ErpNextClient $client;
    private array $results = [];

    public function __construct(ErpNextClient $client)
    {
        $this->client = $client;
    }

    /**
     * Run the full seed process.
     *
     * @return array Results of each seed step
     */
    public function seed(): array
    {
        $this->results = [];

        $this->seedItemGroups();
        $this->seedItems();
        $this->seedCustomerGroups();
        $this->seedSupplierGroups();
        $this->seedAssetCategory();
        $this->seedSalaryStructure();

        Log::channel('erpnext')->info('ERPNext seeding completed', $this->results);

        return $this->results;
    }

    /**
     * Seed Item Groups.
     */
    private function seedItemGroups(): void
    {
        $groups = [
            ['item_group_name' => 'Services', 'parent_item_group' => 'All Item Groups'],
            ['item_group_name' => 'Custody Items', 'parent_item_group' => 'All Item Groups'],
        ];

        foreach ($groups as $group) {
            $this->createIfNotExists('Item Group', $group['item_group_name'], $group);
        }
    }

    /**
     * Seed Items used by the bridge.
     */
    private function seedItems(): void
    {
        $items = [
            [
                'item_code' => 'DELIVERY-SERVICE',
                'item_name' => 'خدمة توصيل طلبات',
                'item_group' => 'Services',
                'stock_uom' => 'Nos',
                'is_stock_item' => 0,
                'description' => 'Delivery service per order — used for daily log invoicing',
            ],
            [
                'item_code' => 'CUSTODY-PHONE',
                'item_name' => 'عهدة هاتف',
                'item_group' => 'Custody Items',
                'stock_uom' => 'Nos',
                'is_stock_item' => 1,
                'has_serial_no' => 1,
                'description' => 'Phone issued to driver as custody',
            ],
            [
                'item_code' => 'CUSTODY-SIM',
                'item_name' => 'عهدة شريحة',
                'item_group' => 'Custody Items',
                'stock_uom' => 'Nos',
                'is_stock_item' => 1,
                'has_serial_no' => 1,
                'description' => 'SIM card issued to driver as custody',
            ],
            [
                'item_code' => 'CUSTODY-CLOTHING',
                'item_name' => 'عهدة ملابس',
                'item_group' => 'Custody Items',
                'stock_uom' => 'Nos',
                'is_stock_item' => 1,
                'description' => 'Uniform/clothing issued to driver as custody',
            ],
            [
                'item_code' => 'CUSTODY-CASH',
                'item_name' => 'عهدة مالية',
                'item_group' => 'Custody Items',
                'stock_uom' => 'Nos',
                'is_stock_item' => 0,
                'description' => 'Cash custody tracked for driver settlements',
            ],
            [
                'item_code' => 'CUSTODY-OTHER',
                'item_name' => 'عهدة أخرى',
                'item_group' => 'Custody Items',
                'stock_uom' => 'Nos',
                'is_stock_item' => 1,
                'description' => 'Other custody items issued to driver',
            ],
        ];

        foreach ($items as $item) {
            $this->createIfNotExists('Item', $item['item_code'], $item);
        }
    }

    /**
     * Seed Customer Groups for FleetOps clients.
     */
    private function seedCustomerGroups(): void
    {
        $groups = [
            ['customer_group_name' => 'Delivery Brands', 'parent_customer_group' => 'All Customer Groups'],
        ];

        foreach ($groups as $group) {
            $this->createIfNotExists('Customer Group', $group['customer_group_name'], $group);
        }
    }

    /**
     * Seed Supplier Groups for garages.
     */
    private function seedSupplierGroups(): void
    {
        $groups = [
            ['supplier_group_name' => 'Garages'],
        ];

        foreach ($groups as $group) {
            $this->createIfNotExists('Supplier Group', $group['supplier_group_name'], $group);
        }
    }

    /**
     * Seed Asset Category for vehicles.
     */
    private function seedAssetCategory(): void
    {
        // Get the depreciation account from config
        $depreciationAccount = config('erpnext.accounts.depreciation', '1780 - Accumulated Depreciation - FO');
        $assetAccount = config('erpnext.accounts.vehicle_asset', '1710 - Capital Equipment - FO');
        $company = config('erpnext.company');

        $category = [
            'asset_category_name' => 'Vehicles',
            'enable_cwip_accounting' => 0,
            'accounts' => [
                [
                    'company_name' => $company,
                    'fixed_asset_account' => $assetAccount,
                    'accumulated_depreciation_account' => $depreciationAccount,
                    'depreciation_expense_account' => config('erpnext.accounts.depreciation', '5203 - Depreciation - FO'),
                ]
            ],
        ];

        $this->createIfNotExists('Asset Category', 'Vehicles', $category);
    }

    /**
     * Seed Salary Structure for official bank payroll.
     * Note: Requires 'Basic' Salary Component to exist first.
     * ERPNext creates this by default — structure is created only if component exists.
     */
    private function seedSalaryStructure(): void
    {
        // Check if Basic salary component exists first
        if (!$this->client->documentExists('Salary Component', 'Basic')) {
            $this->results[] = [
                'doctype' => 'Salary Structure',
                'name' => 'FleetOps Official Salary',
                'status' => 'skipped',
                'error' => 'Basic salary component not found — run ERPNext payroll setup first',
            ];
            return;
        }

        $structure = [
            'name' => 'FleetOps Official Salary',
            'company' => config('erpnext.company'),
            'payroll_frequency' => config('erpnext.payroll.payroll_frequency', 'Monthly'),
            'is_active' => 'Yes',
            'earnings' => [
                [
                    'salary_component' => 'Basic',
                    'formula' => 'base',
                    'amount_based_on_formula' => 1,
                ],
            ],
        ];

        $this->createIfNotExists('Salary Structure', 'FleetOps Official Salary', $structure);
    }

    /**
     * Create an ERPNext document if it doesn't already exist.
     * Idempotent — returns 'exists' if already present.
     */
    private function createIfNotExists(string $doctype, string $name, array $data): void
    {
        try {
            if ($this->client->documentExists($doctype, $name)) {
                $this->results[] = [
                    'doctype' => $doctype,
                    'name' => $name,
                    'status' => 'exists',
                ];
                return;
            }

            $data['doctype'] = $doctype;
            $result = $this->client->createDocument($doctype, $data);

            $this->results[] = [
                'doctype' => $doctype,
                'name' => $result['name'] ?? $name,
                'status' => 'created',
            ];
        } catch (\Exception $e) {
            // Handle "already exists" errors gracefully
            if (str_contains($e->getMessage(), 'DuplicateEntryError') ||
                str_contains($e->getMessage(), 'already exists')) {
                $this->results[] = [
                    'doctype' => $doctype,
                    'name' => $name,
                    'status' => 'exists',
                ];
            } else {
                $this->results[] = [
                    'doctype' => $doctype,
                    'name' => $name,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }
    }
}
