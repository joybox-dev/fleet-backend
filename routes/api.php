<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashSettlementController;
use App\Http\Controllers\Api\ClientCollectionController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ContractAssignmentController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\ContractDashboardController;
use App\Http\Controllers\Api\CurrencyExchangeRateController;
use App\Http\Controllers\Api\CustodyController;
use App\Http\Controllers\Api\CustodyTypeController;
use App\Http\Controllers\Api\DailyLogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DriverExpenseController;
use App\Http\Controllers\Api\DriverGuaranteeController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeDocumentController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\ExpenseLedgerController;
use App\Http\Controllers\Api\GlobalSearchController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\KetaImportController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\OperationalAdvanceController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SalaryAdvanceController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SuperAdminCompanyController;
use App\Http\Controllers\Api\SupervisorAllocationController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\VehicleExpenseController;
use App\Http\Controllers\Api\VehicleExpenseTypeController;
use App\Http\Controllers\Api\VehicleHandoverController;
use App\Http\Controllers\Api\VehicleTypeController;
use App\Http\Controllers\Api\ViolationController;
use App\Http\Controllers\Api\WhatsAppController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FleetOps API Routes
|--------------------------------------------------------------------------
| Two gates on every route. `role:` names the built-in roles a group is for (admin / operator /
| accountant); a company-defined role passes it and is judged by `permission:` — the same keys
| the sidebar hides pages by, so what a login cannot see it also cannot call. A controller that
| checks a finer permission of its own keeps doing so.
| All routes return JSON. Authentication via Laravel Sanctum tokens.
*/

// ─── Public: Authentication ───────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

// ─── Protected: Requires valid Sanctum token ─────────────────────────
Route::middleware(['auth:sanctum', 'company'])->group(function () {

    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // ── Company info (user's own) ────────────────────────────────────
    Route::get('company', [CompanyController::class, 'current']);
    Route::middleware('permission:settings.edit')->group(function () {
        Route::put('company', [CompanyController::class, 'update']);
        Route::post('company', [CompanyController::class, 'update']); // multipart fallback
    });

    // ── File Uploads (all roles) ─────────────────────────────────────
    Route::post('upload', [UploadController::class, 'store']);
    Route::post('upload/multiple', [UploadController::class, 'storeMultiple']);

    // ── Operational Advances (Phase 16 - accessible to all company roles to request/view)
    Route::apiResource('operational-advances', OperationalAdvanceController::class)->only(['index', 'store']);

    // ── Dashboard (all roles) ────────────────────────────────────────
    Route::get('dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('dashboard/expiry-alerts', [DashboardController::class, 'expiryAlerts']);
    Route::get('dashboard/contracts-profitability', [DashboardController::class, 'contractsProfitability']);
    Route::get('dashboard/money-at-risk', [DashboardController::class, 'moneyAtRisk']);

    // ═══════════════════════════════════════════════════════════════════
    // OPERATOR + ADMIN: Daily operations
    // ═══════════════════════════════════════════════════════════════════
    Route::middleware('role:admin,operator')->group(function () {

        // Daily Logs — core operator entry
        Route::middleware('permission:daily_logs.view')->group(function () {
            Route::apiResource('daily-logs', DailyLogController::class)->only(['index', 'show']);
        });
        Route::post('daily-logs/bulk', [DailyLogController::class, 'bulkStore']);
        Route::apiResource('daily-logs', DailyLogController::class)->except(['index', 'show']);

        // Vehicles — view + assign/unassign
        Route::middleware('permission:vehicles.view')->group(function () {
            Route::get('vehicles', [VehicleController::class, 'index']);
            Route::get('vehicles/{vehicle}', [VehicleController::class, 'show']);
        });
        Route::middleware('permission:vehicles.edit,employees.edit')->group(function () {
            Route::post('vehicles/{vehicle}/assign', [VehicleController::class, 'assign']);
            Route::post('vehicles/{vehicle}/unassign', [VehicleController::class, 'unassign']);
        });

        // Lookup lists every form needs; managed from the settings screen.
        Route::get('vehicle-types', [VehicleTypeController::class, 'index']);
        Route::get('vehicle-expense-types', [VehicleExpenseTypeController::class, 'index']);
        Route::middleware('permission:settings.edit')->group(function () {
            Route::apiResource('vehicle-types', VehicleTypeController::class)->except(['index']);
            Route::apiResource('vehicle-expense-types', VehicleExpenseTypeController::class)->except(['index', 'show']);
        });

        // Violations — record traffic fines
        Route::middleware('permission:violations.view')->group(function () {
            Route::get('violations/resolve-driver', [ViolationController::class, 'resolveDriver']);
            Route::apiResource('violations', ViolationController::class)->only(['index', 'show']);
        });
        Route::apiResource('violations', ViolationController::class)->except(['index', 'show']);

        // Maintenance — report + view
        Route::middleware('permission:maintenance.view')->group(function () {
            Route::get('maintenance', [MaintenanceController::class, 'index']);
            Route::get('maintenance/{maintenance}', [MaintenanceController::class, 'show']);
        });
        Route::post('maintenance', [MaintenanceController::class, 'store']);

        // Cash Settlements — record handover
        Route::post('cash-settlements', [CashSettlementController::class, 'store'])->middleware('permission:cash.create');
        Route::middleware('permission:cash.view')->group(function () {
            Route::get('cash-settlements', [CashSettlementController::class, 'index']);
            Route::get('cash-settlements/pending', [CashSettlementController::class, 'pending']);
        });

        // Leaves — CRUD + approve/reject (admin + operator)
        Route::middleware('permission:leaves.view,employees.view')->group(function () {
            Route::get('leave-types', [LeaveController::class, 'types']);
            Route::get('leaves/balance/{employee}', [LeaveController::class, 'balance']);
            Route::apiResource('leaves', LeaveController::class)->only(['index', 'show']);
        });
        Route::apiResource('leaves', LeaveController::class)->except(['index', 'show']);
        Route::post('leaves/{leave}/approve', [LeaveController::class, 'approve']);
        Route::post('leaves/{leave}/reject', [LeaveController::class, 'reject']);

        // Operations Dashboard
        Route::get('operations/dashboard', [OperationsController::class, 'dashboard'])->middleware('permission:operations.view');

        // Vehicle Handovers
        Route::middleware('permission:vehicles.view,employees.view')->group(function () {
            Route::apiResource('vehicle-handovers', VehicleHandoverController::class)->only(['index', 'show']);
        });
        Route::middleware('permission:vehicles.edit,employees.edit')->group(function () {
            Route::apiResource('vehicle-handovers', VehicleHandoverController::class)->only(['store', 'destroy']);
        });
    });

    // ═══════════════════════════════════════════════════════════════════
    // ADMIN ONLY: Management & configuration
    // ═══════════════════════════════════════════════════════════════════
    Route::middleware('role:admin')->group(function () {

        // Deletion Integrity Checks — the question is only asked by someone about to delete.
        Route::get('employees/{employee}/deletion-check', [EmployeeController::class, 'deletionCheck'])->middleware('permission:employees.delete');
        Route::get('vehicles/{vehicle}/deletion-check', [VehicleController::class, 'deletionCheck'])->middleware('permission:vehicles.delete');
        Route::get('clients/{client}/deletion-check', [ClientController::class, 'deletionCheck'])->middleware('permission:clients.delete');
        Route::get('contracts/{contract}/deletion-check', [ContractController::class, 'deletionCheck'])->middleware('permission:contracts.delete');
        Route::get('custody-types/{custody_type}/deletion-check', [CustodyTypeController::class, 'deletionCheck'])->middleware('permission:settings.edit');

        // Clients — full CRUD
        Route::middleware('permission:clients.view')->group(function () {
            Route::apiResource('clients', ClientController::class)->only(['index', 'show']);
        });
        Route::apiResource('clients', ClientController::class)->except(['index', 'show']);

        // Contracts — full CRUD
        Route::middleware('permission:contracts.view')->group(function () {
            Route::apiResource('contracts', ContractController::class)->only(['index', 'show']);
            Route::get('contracts/{contract}/dashboard', [ContractDashboardController::class, 'show']);
        });
        Route::apiResource('contracts', ContractController::class)->except(['index', 'show']);

        // Roles — who may do what. Only the settings screen edits them.
        Route::middleware('permission:settings.view')->group(function () {
            Route::get('roles/permissions', [RoleController::class, 'availablePermissions']);
            Route::apiResource('roles', RoleController::class)->only(['index']);
        });
        Route::middleware('permission:settings.edit')->group(function () {
            Route::apiResource('roles', RoleController::class)->except(['index']);
        });

        // Contract Assignments & Overrides — set from the employee's profile
        Route::get('contract-assignments', [ContractAssignmentController::class, 'index'])
            ->middleware('permission:employees.view,contracts.view,daily_logs.view');
        Route::middleware('permission:employees.edit')->group(function () {
            Route::apiResource('contract-assignments', ContractAssignmentController::class)
                ->except(['index'])
                ->parameters(['contract-assignments' => 'assignment']);
            Route::post('contract-assignments/{assignment}/overrides', [ContractAssignmentController::class, 'storeOverride']);
            Route::put('contract-assignments/overrides/{override}', [ContractAssignmentController::class, 'updateOverride']);
            Route::delete('contract-assignments/overrides/{override}', [ContractAssignmentController::class, 'destroyOverride']);
        });

        // Supervisor cost allocations
        Route::get('supervisor-allocations', [SupervisorAllocationController::class, 'index'])->middleware('permission:employees.view');
        Route::post('supervisor-allocations', [SupervisorAllocationController::class, 'store'])->middleware('permission:employees.edit');

        // Currency Exchange Rates
        Route::get('currency-exchange-rates', [CurrencyExchangeRateController::class, 'index'])->middleware('permission:settings.view');
        Route::middleware('permission:settings.edit')->group(function () {
            Route::apiResource('currency-exchange-rates', CurrencyExchangeRateController::class)->only(['store', 'destroy']);
        });

        // Keeta Importer — it writes daily logs, so that is the permission it needs.
        Route::middleware('permission:daily_logs.create')->group(function () {
            Route::post('keta/preview', [KetaImportController::class, 'preview']);
            Route::post('keta/confirm', [KetaImportController::class, 'confirm']);
        });

        // Employees — full CRUD + balance
        Route::post('employees/bulk-delete', [EmployeeController::class, 'bulkDestroy']);
        Route::middleware('permission:employees.view')->group(function () {
            Route::apiResource('employees', EmployeeController::class)->only(['index', 'show']);
            Route::get('employees/{employee}/balance', [EmployeeController::class, 'balance']);
            Route::get('employees/{employee}/history', [EmployeeController::class, 'history']);
            Route::get('employees/{employee}/documents', [EmployeeDocumentController::class, 'index']);
        });
        Route::apiResource('employees', EmployeeController::class)->except(['index', 'show']);

        // One search box across contracts, employees, vehicles, clients and violations.
        Route::get('search', GlobalSearchController::class);

        // Employee Documents
        Route::middleware('permission:employees.edit')->group(function () {
            Route::post('employees/{employee}/documents', [EmployeeDocumentController::class, 'store']);
            Route::put('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'update']);
            Route::delete('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy']);
        });

        // Evaluation Criteria (company settings) & Employee Evaluations
        Route::get('evaluation-criteria', [EvaluationController::class, 'criteriaIndex'])->middleware('permission:evaluations.view');
        Route::middleware('permission:evaluations.edit')->group(function () {
            Route::post('evaluation-criteria', [EvaluationController::class, 'criteriaStore']);
            Route::put('evaluation-criteria/{criterion}', [EvaluationController::class, 'criteriaUpdate']);
            Route::delete('evaluation-criteria/{criterion}', [EvaluationController::class, 'criteriaDestroy']);
            Route::apiResource('evaluations', EvaluationController::class)->only(['update']);
        });
        Route::apiResource('evaluations', EvaluationController::class)->only(['index', 'show'])->middleware('permission:evaluations.view');
        Route::apiResource('evaluations', EvaluationController::class)->only(['store'])->middleware('permission:evaluations.create');
        Route::apiResource('evaluations', EvaluationController::class)->only(['destroy'])->middleware('permission:evaluations.delete');

        // Vehicles — create/update/delete (operators can only view)
        Route::post('vehicles/bulk-delete', [VehicleController::class, 'bulkDestroy']);
        Route::post('vehicles', [VehicleController::class, 'store']);
        Route::put('vehicles/{vehicle}', [VehicleController::class, 'update']);
        Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy']);

        // Maintenance — approve/reject
        Route::put('maintenance/{maintenance}', [MaintenanceController::class, 'update']);
        Route::delete('maintenance/{maintenance}', [MaintenanceController::class, 'destroy']);
        Route::post('maintenance/{maintenance}/approve', [MaintenanceController::class, 'approve']);
        Route::post('maintenance/{maintenance}/reject', [MaintenanceController::class, 'reject'])->middleware('permission:maintenance.edit');

        // Custody Items — full CRUD + return
        Route::middleware('permission:custody.view')->group(function () {
            Route::apiResource('custody', CustodyController::class)->only(['index', 'show']);
        });
        Route::apiResource('custody', CustodyController::class)->except(['index', 'show']);
        Route::post('custody/{custody}/return', [CustodyController::class, 'returnItem']);

        // Custody Types — a lookup for the custody form, managed from settings
        Route::get('custody-types', [CustodyTypeController::class, 'index'])->middleware('permission:custody.view,settings.view');
        Route::middleware('permission:settings.edit')->group(function () {
            Route::apiResource('custody-types', CustodyTypeController::class)->except(['index', 'show']);
        });

        // Driver Expenses
        Route::apiResource('driver-expenses', DriverExpenseController::class)->only(['show'])
            ->middleware('permission:driver_expenses.view,employees.view,payroll.view');
        Route::apiResource('driver-expenses', DriverExpenseController::class)->except(['show']);

        // Read-only view over all six spending screens at once. Adds nothing and changes nothing:
        // each row links back to the screen that owns it.
        Route::get('expenses', [ExpenseLedgerController::class, 'index']);

        // ── Import/Export ─────────────────────────────────────
        Route::prefix('import')->group(function () {
            Route::middleware('permission:settings.view')->group(function () {
                Route::get('entity-types', [ImportController::class, 'entityTypes']);
                Route::get('fields/{entity}', [ImportController::class, 'fields']);
                Route::get('logs', [ImportController::class, 'logs']);
                Route::get('status/{id}', [ImportController::class, 'status']);
                Route::get('template/{entity}', [ImportController::class, 'template']);
            });
            Route::middleware('permission:settings.edit')->group(function () {
                Route::post('upload', [ImportController::class, 'upload']);
                Route::post('preview', [ImportController::class, 'preview']);
                Route::post('confirm', [ImportController::class, 'confirm']);
            });
        });
    });

    // ═══════════════════════════════════════════════════════════════════
    // ADMIN + ACCOUNTANT: Financial
    // ═══════════════════════════════════════════════════════════════════
    Route::middleware('role:admin,accountant')->group(function () {

        // Payroll
        Route::prefix('payroll')->group(function () {
            Route::get('consolidated/{year}/{month}', [PayrollController::class, 'consolidatedSheet'])
                ->middleware('permission:payroll.view,contract_payroll.view');
            Route::post('consolidated/{year}/{month}/approve', [PayrollController::class, 'approveConsolidatedSheet']);
            Route::post('consolidated/{year}/{month}/unapprove', [PayrollController::class, 'unapproveConsolidatedSheet']);
            // Recording what was actually paid out — money leaving the company, so it takes the
            // same authority as editing payroll or approving it.
            Route::post('consolidated/{year}/{month}/disbursements', [PayrollController::class, 'storeDisbursement'])
                ->middleware('permission:payroll.edit,contract_payroll.approve');
            Route::put('disbursements/{disbursement}', [PayrollController::class, 'updateDisbursement'])
                ->middleware('permission:payroll.edit,contract_payroll.approve');
            Route::delete('disbursements/{disbursement}', [PayrollController::class, 'destroyDisbursement'])
                ->middleware('permission:payroll.edit,contract_payroll.approve');
            // Deciding, before approval, that a charge waits for a later month or that an
            // instalment is different this month — the same authority as approving.
            Route::post('consolidated/{year}/{month}/deduction-overrides', [PayrollController::class, 'storeDeductionOverride'])
                ->middleware('permission:payroll.edit,contract_payroll.approve');
            Route::delete('deduction-overrides/{override}', [PayrollController::class, 'destroyDeductionOverride'])
                ->middleware('permission:payroll.edit,contract_payroll.approve');
            Route::get('contract-sheet/{contract}', [PayrollController::class, 'contractSheet']);
            Route::post('contract-sheet/{contract}/approve', [PayrollController::class, 'approveContractSheet']);
            Route::post('contract-sheet/{contract}/unapprove', [PayrollController::class, 'unapproveContractSheet']);
            Route::get('contract-sheet/{contract}/adjustments', [PayrollController::class, 'getContractAdjustments'])
                ->middleware('permission:payroll.view,contract_payroll.view');
            Route::post('contract-sheet/{contract}/adjustments', [PayrollController::class, 'storeContractAdjustment']);
            Route::delete('contract-sheet/adjustments/{adjustment}', [PayrollController::class, 'destroyContractAdjustment']);
        });

        // Reports
        Route::prefix('reports')->middleware('permission:reports.view')->group(function () {
            Route::get('deductions', [ReportController::class, 'deductions']);
            Route::get('violations', [ReportController::class, 'violations']);
            Route::get('expiring-docs', [ReportController::class, 'expiringDocs']);
            Route::get('pending-cash', [ReportController::class, 'pendingCash']);
            Route::get('weekly-orders', [ReportController::class, 'weeklyOrders']);
            Route::get('fleet-status', [ReportController::class, 'fleetStatus']);
            Route::get('vehicle-profitability', [ReportController::class, 'vehicleProfitability']);
            Route::get('driver-status', [ReportController::class, 'driverStatus']);
            Route::get('contract-profitability', [ReportController::class, 'contractProfitability']);
            Route::get('missing-docs', [ReportController::class, 'missingDocs']);
        });

        // Settings
        Route::get('settings', [SettingsController::class, 'index'])->middleware('permission:settings.view');
        Route::put('settings', [SettingsController::class, 'update'])->middleware('permission:settings.edit');

        // WhatsApp
        Route::middleware('permission:settings.edit')->group(function () {
            Route::post('whatsapp/test-connection', [WhatsAppController::class, 'testConnection']);
            Route::post('whatsapp/send', [WhatsAppController::class, 'sendMessage']);
        });

        // ── Phase 2: New Modules ──────────────────────────────

        // Driver Guarantees
        Route::middleware('permission:guarantees.view')->group(function () {
            Route::apiResource('guarantees', DriverGuaranteeController::class)->only(['index', 'show']);
        });
        Route::apiResource('guarantees', DriverGuaranteeController::class)->only(['store', 'destroy']);
        Route::post('guarantees/{guarantee}/return', [DriverGuaranteeController::class, 'returnItem']);

        // Vehicle Expenses
        Route::middleware('permission:vehicle_expenses.view')->group(function () {
            Route::get('vehicle-expenses/summary', [VehicleExpenseController::class, 'summary']);
            Route::apiResource('vehicle-expenses', VehicleExpenseController::class)->only(['index', 'show']);
        });
        Route::apiResource('vehicle-expenses', VehicleExpenseController::class)->except(['index', 'show']);

        // Salary Advances
        // No destroy: an advance is written off through cancel, which keeps the record. The route
        // was registered without a method behind it and answered 500 to anyone who found it.
        Route::middleware('permission:salary_advances.view')->group(function () {
            Route::apiResource('salary-advances', SalaryAdvanceController::class)->only(['index', 'show']);
        });
        Route::apiResource('salary-advances', SalaryAdvanceController::class)->only(['store']);
        Route::post('salary-advances/{salaryAdvance}/cancel', [SalaryAdvanceController::class, 'cancel']);

        // Operational Advances (Phase 16)
        Route::middleware('permission:op_advances.edit')->group(function () {
            Route::post('operational-advances/{id}/approve', [OperationalAdvanceController::class, 'approve']);
            Route::post('operational-advances/{id}/reject', [OperationalAdvanceController::class, 'reject']);
        });
        Route::middleware('permission:op_advances.create,op_advances.edit')->group(function () {
            Route::post('operational-advances/{id}/expense', [OperationalAdvanceController::class, 'registerExpense']);
            Route::post('operational-advances/{id}/return', [OperationalAdvanceController::class, 'registerReturn']);
        });

        // Client Collections (Phase 16)
        Route::get('contracts/{contractId}/collections', [ClientCollectionController::class, 'index'])->middleware('permission:contracts.view');
        Route::middleware('permission:contracts.edit')->group(function () {
            Route::post('contracts/{contractId}/collections', [ClientCollectionController::class, 'store']);
            Route::delete('contracts/{contractId}/collections/{id}', [ClientCollectionController::class, 'destroy']);
        });

    });

    // ═══════════════════════════════════════════════════════════════════
    // SUPER ADMIN: Platform management
    // ═══════════════════════════════════════════════════════════════════
    Route::middleware('super_admin')->prefix('admin')->group(function () {
        Route::apiResource('companies', SuperAdminCompanyController::class);
        Route::put('companies/{company}/modules', [SuperAdminCompanyController::class, 'updateModules']);
        Route::put('companies/{company}/branding', [SuperAdminCompanyController::class, 'updateBranding']);
        Route::get('companies/{company}/users', [SuperAdminCompanyController::class, 'users']);
        Route::post('companies/{company}/users', [SuperAdminCompanyController::class, 'addUser']);
        Route::post('companies/{company}/users/create', [SuperAdminCompanyController::class, 'createUser']);
        Route::put('companies/{company}/users/{user}', [SuperAdminCompanyController::class, 'updateUser']);
        Route::delete('companies/{company}/users/{user}', [SuperAdminCompanyController::class, 'removeUser']);
        Route::get('dashboard', [SuperAdminCompanyController::class, 'dashboard']);
    });
});
