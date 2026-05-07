<?php

namespace App\Services\ErpNext;

use App\Services\ErpNext\Mappers\CustomerMapper;
use App\Services\ErpNext\Mappers\EmployeeMapper;
use App\Services\ErpNext\Mappers\VehicleMapper;
use App\Services\ErpNext\Mappers\InvoiceMapper;
use App\Services\ErpNext\Mappers\JournalMapper;
use App\Services\ErpNext\Mappers\PaymentMapper;
use App\Services\ErpNext\Mappers\PayrollMapper;
use App\Services\ErpNext\Mappers\StockMapper;
use App\Services\ErpNext\Mappers\PayrollDeductionMapper;
use App\Services\ErpNext\Mappers\FuelExpenseMapper;
use App\Services\ErpNext\Mappers\FixedInvoiceMapper;
use Illuminate\Support\Facades\Log;

/**
 * ERPNext Service — Main Orchestrator
 *
 * High-level service that coordinates all FleetOps → ERPNext operations.
 * Uses mappers to transform data and the ErpNextClient for HTTP calls.
 *
 * Architecture Rule: All methods are called from Queue Jobs (async).
 * FleetOps never blocks waiting for ERPNext.
 *
 * Sync Flow:
 * 1. FleetOps event occurs (daily log created, violation recorded, etc.)
 * 2. Event listener dispatches a queue job
 * 3. Queue job calls this service
 * 4. This service maps data + calls ErpNextClient
 * 5. On success: updates erp_sync_status = 'synced' in FleetOps DB
 * 6. On failure: updates erp_sync_status = 'failed' + logs error
 */
class ErpNextService
{
    private ErpNextClient $client;

    public function __construct(ErpNextClient $client)
    {
        $this->client = $client;
    }

    // ──────────────────────────────────────────────
    // Client/Customer Sync
    // ──────────────────────────────────────────────

    /**
     * Sync a FleetOps Client → ERPNext Customer.
     *
     * @return string ERPNext Customer name
     */
    public function syncClient(array $client): string
    {
        $data = CustomerMapper::toErpNext($client);

        // Check if already exists
        if (!empty($client['erp_id']) && $this->client->documentExists('Customer', $client['erp_id'])) {
            $result = $this->client->updateDocument('Customer', $client['erp_id'], $data);
            Log::channel('erpnext')->info("Updated Customer in ERPNext", ['name' => $client['erp_id']]);
            return $client['erp_id'];
        }

        $result = $this->client->createDocument('Customer', $data);
        $erpName = $result['name'];
        Log::channel('erpnext')->info("Created Customer in ERPNext", ['name' => $erpName]);

        return $erpName;
    }

    // ──────────────────────────────────────────────
    // Employee Sync
    // ──────────────────────────────────────────────

    /**
     * Sync a FleetOps Employee → ERPNext Employee + Salary Structure Assignment.
     *
     * @return string ERPNext Employee name
     */
    public function syncEmployee(array $employee): string
    {
        $data = EmployeeMapper::toErpNext($employee);

        if (!empty($employee['erp_id']) && $this->client->documentExists('Employee', $employee['erp_id'])) {
            $this->client->updateDocument('Employee', $employee['erp_id'], $data);
            Log::channel('erpnext')->info("Updated Employee in ERPNext", ['name' => $employee['erp_id']]);
            return $employee['erp_id'];
        }

        $result = $this->client->createDocument('Employee', $data);
        $erpName = $result['name'];

        // Create Salary Structure Assignment (OFFICIAL salary only)
        try {
            $ssaData = EmployeeMapper::toSalaryStructureAssignment($employee, $erpName);
            $this->client->createDocument('Salary Structure Assignment', $ssaData);
            Log::channel('erpnext')->info("Created Salary Structure Assignment", ['employee' => $erpName]);
        } catch (\Exception $e) {
            Log::channel('erpnext')->warning("Salary Structure Assignment failed (non-critical)", [
                'employee' => $erpName,
                'error' => $e->getMessage(),
            ]);
        }

        Log::channel('erpnext')->info("Created Employee in ERPNext", ['name' => $erpName]);
        return $erpName;
    }

    // ──────────────────────────────────────────────
    // Vehicle Sync (Asset)
    // ──────────────────────────────────────────────

    /**
     * Sync a FleetOps Vehicle → ERPNext Asset.
     *
     * @return string ERPNext Asset name
     */
    public function syncVehicle(array $vehicle): string
    {
        $data = VehicleMapper::toErpNext($vehicle);

        if (!empty($vehicle['erp_id']) && $this->client->documentExists('Asset', $vehicle['erp_id'])) {
            $this->client->updateDocument('Asset', $vehicle['erp_id'], $data);
            return $vehicle['erp_id'];
        }

        $result = $this->client->createDocument('Asset', $data);
        $erpName = $result['name'];
        Log::channel('erpnext')->info("Created Asset in ERPNext", ['name' => $erpName]);

        return $erpName;
    }

    // ──────────────────────────────────────────────
    // Daily Log → Sales Invoice
    // ──────────────────────────────────────────────

    /**
     * Sync a DailyLog → ERPNext Sales Invoice.
     * Creates a Sales Invoice representing contract income for the day.
     *
     * @return string ERPNext Sales Invoice name
     */
    public function syncDailyLog(array $dailyLog, array $contract, array $vehicle): string
    {
        $data = InvoiceMapper::toErpNext($dailyLog, $contract, $vehicle);

        $result = $this->client->createDocument('Sales Invoice', $data);
        $erpName = $result['name'];
        Log::channel('erpnext')->info("Created Sales Invoice in ERPNext", [
            'name' => $erpName,
            'daily_log_id' => $dailyLog['id'],
        ]);

        return $erpName;
    }

    // ──────────────────────────────────────────────
    // Fixed Contract → Sales Invoice (Monthly)
    // ──────────────────────────────────────────────

    /**
     * Sync a fixed-monthly contract → ERPNext Sales Invoice.
     *
     * Called by the monthly scheduler. Creates a single Sales Invoice
     * for the contract's fixed_monthly amount.
     *
     * @param array  $contract  Contract data (with client relation)
     * @param array  $client    Client data (with erp_id)
     * @param string $year      Billing year
     * @param string $month     Billing month (zero-padded)
     * @return string ERPNext Sales Invoice name
     */
    public function syncFixedContractInvoice(
        array $contract,
        array $client,
        string $year,
        string $month
    ): string {
        $data = FixedInvoiceMapper::toErpNext($contract, $client, $year, $month);

        $result = $this->client->createDocument('Sales Invoice', $data);
        $erpName = $result['name'];

        Log::channel('erpnext')->info("Created Fixed Contract Sales Invoice in ERPNext", [
            'name'        => $erpName,
            'contract_id' => $contract['id'],
            'client'      => $client['name'],
            'period'      => "{$year}-{$month}",
            'amount'      => $contract['fixed_monthly'],
        ]);

        return $erpName;
    }

    // ──────────────────────────────────────────────
    // Violation → Journal Entry
    // ──────────────────────────────────────────────

    /**
     * Sync a Violation → ERPNext Journal Entry (deduction).
     *
     * @return string ERPNext Journal Entry name
     */
    public function syncViolation(array $violation, array $employee): string
    {
        $data = JournalMapper::violationToJournalEntry($violation, $employee);

        $result = $this->client->createDocument('Journal Entry', $data);
        $erpName = $result['name'];
        Log::channel('erpnext')->info("Created Violation Journal Entry in ERPNext", [
            'name' => $erpName,
            'violation_id' => $violation['id'],
        ]);

        return $erpName;
    }

    // ──────────────────────────────────────────────
    // Cash Settlement → Payment Entry
    // ──────────────────────────────────────────────

    /**
     * Sync a CashSettlement → ERPNext Payment Entry.
     *
     * @return string ERPNext Payment Entry name
     */
    public function syncCashSettlement(array $settlement, array $employee): string
    {
        $data = PaymentMapper::toErpNext($settlement, $employee);

        $result = $this->client->createDocument('Payment Entry', $data);
        $erpName = $result['name'];
        Log::channel('erpnext')->info("Created Payment Entry in ERPNext", [
            'name' => $erpName,
            'settlement_id' => $settlement['id'],
        ]);

        return $erpName;
    }

    // ──────────────────────────────────────────────
    // Maintenance → Payment + Journal
    // ──────────────────────────────────────────────

    /**
     * Sync approved maintenance → ERPNext Payment Entry to garage.
     *
     * @return string ERPNext Payment Entry name
     */
    public function syncMaintenancePayment(array $maintenanceRequest, array $approvedQuote): string
    {
        $data = PaymentMapper::maintenancePaymentToErpNext($maintenanceRequest, $approvedQuote);

        $result = $this->client->createDocument('Payment Entry', $data);
        $erpName = $result['name'];
        Log::channel('erpnext')->info("Created Maintenance Payment in ERPNext", [
            'name' => $erpName,
            'maintenance_id' => $maintenanceRequest['id'],
        ]);

        return $erpName;
    }

    /**
     * Sync driver-charged maintenance → ERPNext Journal Entry.
     */
    public function syncMaintenanceChargeOnDriver(
        array $maintenanceRequest,
        array $employee,
        float $amount
    ): string {
        $data = JournalMapper::maintenanceChargeToJournalEntry($maintenanceRequest, $employee, $amount);

        $result = $this->client->createDocument('Journal Entry', $data);
        $erpName = $result['name'];
        Log::channel('erpnext')->info("Created Maintenance Charge Journal Entry", [
            'name' => $erpName,
            'employee' => $employee['id'],
        ]);

        return $erpName;
    }

    // ──────────────────────────────────────────────
    // Payroll → Salary Slip (OFFICIAL ONLY)
    // ──────────────────────────────────────────────

    /**
     * Sync OFFICIAL payroll → ERPNext Salary Slip.
     * Only the bank salary is sent. Internal calculations stay in FleetOps.
     */
    public function syncPayroll(array $employee, string $year, string $month): ?string
    {
        if (empty($employee['erp_id'])) {
            Log::channel('erpnext')->warning("Cannot sync payroll — employee not synced to ERPNext", [
                'employee_id' => $employee['id'],
            ]);
            return null;
        }

        $data = PayrollMapper::toOfficialSalarySlip($employee, $year, $month);

        $result = $this->client->createDocument('Salary Slip', $data);
        $erpName = $result['name'];
        Log::channel('erpnext')->info("Created Salary Slip in ERPNext", [
            'name' => $erpName,
            'employee' => $employee['erp_employee_id'],
            'month' => "{$year}-{$month}",
        ]);

        return $erpName;
    }

    // ──────────────────────────────────────────────
    // Payroll Deductions → Journal Entry (Batch)
    // ──────────────────────────────────────────────

    /**
     * Sync aggregated payroll deductions → ERPNext Journal Entry.
     *
     * Called when a payroll batch is approved. Creates a single JE that
     * balances the ledger: Debit cash (recovered), Credit receivables/expenses.
     *
     * @param string $year
     * @param string $month
     * @param float  $totalViolations   Sum of driver-liable violations deducted
     * @param float  $totalMaintenance  Sum of driver-charged maintenance deducted
     * @param float  $totalCustody      Sum of custody damage/loss charges
     * @return string ERPNext Journal Entry name
     */
    public function syncPayrollDeductions(
        string $year,
        string $month,
        float $totalViolations,
        float $totalMaintenance,
        float $totalCustody = 0
    ): string {
        $data = PayrollDeductionMapper::toJournalEntry(
            $year, $month, $totalViolations, $totalMaintenance, $totalCustody
        );

        $result = $this->client->createDocument('Journal Entry', $data);
        $erpName = $result['name'];

        Log::channel('erpnext')->info("Created Payroll Deductions Journal Entry in ERPNext", [
            'name'         => $erpName,
            'period'       => "{$year}-{$month}",
            'violations'   => $totalViolations,
            'maintenance'  => $totalMaintenance,
            'custody'      => $totalCustody,
        ]);

        return $erpName;
    }

    // ──────────────────────────────────────────────
    // Fuel Allowance → Journal Entry (Batch)
    // ──────────────────────────────────────────────

    /**
     * Sync consolidated monthly fuel allowance → ERPNext Journal Entry.
     *
     * Called when a payroll batch is approved. Creates a single JE:
     * Debit fuel_expense (company cost), Credit cash_in_hand (paid out).
     *
     * @param string $year
     * @param string $month
     * @param float  $totalAmount  Sum of fuel_allowance from all slips
     * @return string ERPNext Journal Entry name
     */
    public function syncFuelExpense(string $year, string $month, float $totalAmount): string
    {
        $data = FuelExpenseMapper::toJournalEntry($year, $month, $totalAmount);

        $result = $this->client->createDocument('Journal Entry', $data);
        $erpName = $result['name'];

        Log::channel('erpnext')->info("Created Fuel Expense Journal Entry in ERPNext", [
            'name'   => $erpName,
            'period' => "{$year}-{$month}",
            'amount' => $totalAmount,
        ]);

        return $erpName;
    }

    // ──────────────────────────────────────────────
    // Custody → Stock Entry
    // ──────────────────────────────────────────────

    /**
     * Sync custody issuance → ERPNext Stock Entry (Material Issue).
     */
    public function syncCustodyIssue(array $custodyItem, array $employee): string
    {
        $data = StockMapper::custodyIssueToStockEntry($custodyItem, $employee);

        $result = $this->client->createDocument('Stock Entry', $data);
        $erpName = $result['name'];
        Log::channel('erpnext')->info("Created Stock Entry (Issue) in ERPNext", [
            'name' => $erpName,
            'custody_id' => $custodyItem['id'],
        ]);

        return $erpName;
    }

    /**
     * Sync custody return → ERPNext Stock Entry (Material Receipt).
     */
    public function syncCustodyReturn(array $custodyItem, array $employee): string
    {
        $data = StockMapper::custodyReturnToStockEntry($custodyItem, $employee);

        $result = $this->client->createDocument('Stock Entry', $data);
        $erpName = $result['name'];
        Log::channel('erpnext')->info("Created Stock Entry (Return) in ERPNext", [
            'name' => $erpName,
            'custody_id' => $custodyItem['id'],
        ]);

        return $erpName;
    }

    // ──────────────────────────────────────────────
    // Utility Methods
    // ──────────────────────────────────────────────

    /**
     * Test the connection to ERPNext.
     */
    public function testConnection(): array
    {
        $isReachable = $this->client->ping();

        return [
            'reachable' => $isReachable,
            'base_url'  => config('erpnext.base_url'),
            'auth_method' => config('erpnext.auth_method'),
            'sync_enabled' => config('erpnext.sync.enabled'),
        ];
    }

    /**
     * Get sync status summary for admin dashboard.
     */
    public function getSyncStatus(): array
    {
        return [
            'circuit_breaker_open' => cache('erpnext_cb_open', false),
            'recent_failures' => cache('erpnext_cb_failures', 0),
            'connection_alive' => $this->client->ping(),
        ];
    }
}
