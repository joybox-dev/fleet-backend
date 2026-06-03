<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\DailyLogController;
use App\Http\Controllers\Api\ViolationController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\CustodyController;
use App\Http\Controllers\Api\CashSettlementController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\SuperAdminCompanyController;
use App\Http\Controllers\Api\DriverGuaranteeController;
use App\Http\Controllers\Api\VehicleExpenseController;
use App\Http\Controllers\Api\SalaryAdvanceController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\EmployeeDocumentController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\VehicleHandoverController;

/*
|--------------------------------------------------------------------------
| FleetOps API Routes
|--------------------------------------------------------------------------
| Roles: admin (full access), operator (daily ops), accountant (financial)
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
    Route::put('company', [CompanyController::class, 'update']);
    Route::post('company', [CompanyController::class, 'update']); // multipart fallback

    // ── File Uploads (all roles) ─────────────────────────────────────
    Route::post('upload', [UploadController::class, 'store']);
    Route::post('upload/multiple', [UploadController::class, 'storeMultiple']);

    // ── Dashboard (all roles) ────────────────────────────────────────
    Route::get('dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('dashboard/expiry-alerts', [DashboardController::class, 'expiryAlerts']);
    Route::get('dashboard/contracts-profitability', [DashboardController::class, 'contractsProfitability']);

    // ═══════════════════════════════════════════════════════════════════
    // OPERATOR + ADMIN: Daily operations
    // ═══════════════════════════════════════════════════════════════════
    Route::middleware('role:admin,operator')->group(function () {

        // Daily Logs — core operator entry
        Route::apiResource('daily-logs', DailyLogController::class);

        // Vehicles — view + assign/unassign + odometer
        Route::get('vehicles', [VehicleController::class, 'index']);
        Route::get('vehicles/{vehicle}', [VehicleController::class, 'show']);
        Route::post('vehicles/{vehicle}/assign', [VehicleController::class, 'assign']);
        Route::post('vehicles/{vehicle}/unassign', [VehicleController::class, 'unassign']);
        Route::patch('vehicles/{vehicle}/odometer', [VehicleController::class, 'updateOdometer']);

        // Violations — record traffic fines
        Route::get('violations/resolve-driver', [ViolationController::class, 'resolveDriver']);
        Route::apiResource('violations', ViolationController::class);

        // Maintenance — report + view
        Route::get('maintenance', [MaintenanceController::class, 'index']);
        Route::get('maintenance/{maintenance}', [MaintenanceController::class, 'show']);
        Route::post('maintenance', [MaintenanceController::class, 'store']);

        // Cash Settlements — record handover
        Route::post('cash-settlements', [CashSettlementController::class, 'store']);
        Route::get('cash-settlements', [CashSettlementController::class, 'index']);
        Route::get('cash-settlements/pending', [CashSettlementController::class, 'pending']);

        // Leaves — CRUD + approve/reject (admin + operator)
        Route::get('leave-types', [LeaveController::class, 'types']);
        Route::get('leaves/balance/{employee}', [LeaveController::class, 'balance']);
        Route::apiResource('leaves', LeaveController::class);
        Route::post('leaves/{leave}/approve', [LeaveController::class, 'approve']);
        Route::post('leaves/{leave}/reject', [LeaveController::class, 'reject']);

        // Operations Dashboard
        Route::get('operations/dashboard', [OperationsController::class, 'dashboard']);

        // Vehicle Handovers
        Route::apiResource('vehicle-handovers', VehicleHandoverController::class);
    });

    // ═══════════════════════════════════════════════════════════════════
    // ADMIN ONLY: Management & configuration
    // ═══════════════════════════════════════════════════════════════════
    Route::middleware('role:admin')->group(function () {

        // Deletion Integrity Checks
        Route::get('employees/{employee}/deletion-check', [EmployeeController::class, 'deletionCheck']);
        Route::get('vehicles/{vehicle}/deletion-check', [VehicleController::class, 'deletionCheck']);
        Route::get('clients/{client}/deletion-check', [ClientController::class, 'deletionCheck']);
        Route::get('contracts/{contract}/deletion-check', [ContractController::class, 'deletionCheck']);
        Route::get('custody-types/{custody_type}/deletion-check', [\App\Http\Controllers\Api\CustodyTypeController::class, 'deletionCheck']);

        // Clients — full CRUD
        Route::apiResource('clients', ClientController::class);

        // Contracts — full CRUD + lock
        Route::apiResource('contracts', ContractController::class);
        Route::post('contracts/{contract}/lock', [ContractController::class, 'lock']);

        // Employees — full CRUD + balance
        Route::post('employees/bulk-delete', [EmployeeController::class, 'bulkDestroy']);
        Route::apiResource('employees', EmployeeController::class);
        Route::get('employees/{employee}/balance', [EmployeeController::class, 'balance']);

        // Employee Documents
        Route::get('employees/{employee}/documents', [EmployeeDocumentController::class, 'index']);
        Route::post('employees/{employee}/documents', [EmployeeDocumentController::class, 'store']);
        Route::put('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'update']);
        Route::delete('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy']);

        // Evaluation Criteria (company settings)
        Route::get('evaluation-criteria', [EvaluationController::class, 'criteriaIndex']);
        Route::post('evaluation-criteria', [EvaluationController::class, 'criteriaStore']);
        Route::put('evaluation-criteria/{criterion}', [EvaluationController::class, 'criteriaUpdate']);
        Route::delete('evaluation-criteria/{criterion}', [EvaluationController::class, 'criteriaDestroy']);

        // Employee Evaluations
        Route::apiResource('evaluations', EvaluationController::class);

        // Vehicles — create/update/delete (operators can only view)
        Route::post('vehicles/bulk-delete', [VehicleController::class, 'bulkDestroy']);
        Route::post('vehicles', [VehicleController::class, 'store']);
        Route::put('vehicles/{vehicle}', [VehicleController::class, 'update']);
        Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy']);

        // Maintenance — approve/reject
        Route::put('maintenance/{maintenance}', [MaintenanceController::class, 'update']);
        Route::delete('maintenance/{maintenance}', [MaintenanceController::class, 'destroy']);
        Route::post('maintenance/{maintenance}/approve', [MaintenanceController::class, 'approve']);
        Route::post('maintenance/{maintenance}/reject', [MaintenanceController::class, 'reject']);

        // Custody Items — full CRUD + return
        Route::apiResource('custody', CustodyController::class);
        Route::post('custody/{custody}/return', [CustodyController::class, 'returnItem']);

        // Custody Types — manage types
        Route::apiResource('custody-types', \App\Http\Controllers\Api\CustodyTypeController::class)->except(['show']);

        // ── Import/Export ─────────────────────────────────────
        Route::prefix('import')->group(function () {
            Route::get('entity-types', [ImportController::class, 'entityTypes']);
            Route::get('fields/{entity}', [ImportController::class, 'fields']);
            Route::post('upload', [ImportController::class, 'upload']);
            Route::post('preview', [ImportController::class, 'preview']);
            Route::post('confirm', [ImportController::class, 'confirm']);
            Route::get('logs', [ImportController::class, 'logs']);
            Route::get('status/{id}', [ImportController::class, 'status']);
            Route::get('template/{entity}', [ImportController::class, 'template']);
        });
    });

    // ═══════════════════════════════════════════════════════════════════
    // ADMIN + ACCOUNTANT: Financial
    // ═══════════════════════════════════════════════════════════════════
    Route::middleware('role:admin,accountant')->group(function () {

        // Payroll
        Route::prefix('payroll')->group(function () {
            Route::post('run', [PayrollController::class, 'run']);
            Route::get('{year}/{month}', [PayrollController::class, 'show']);
            Route::get('{year}/{month}/{employee}', [PayrollController::class, 'slip']);
            Route::post('{year}/{month}/approve', [PayrollController::class, 'approve']);
        });

        // Reports
        Route::prefix('reports')->group(function () {
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
        Route::get('settings', [\App\Http\Controllers\Api\SettingsController::class, 'index']);
        Route::put('settings', [\App\Http\Controllers\Api\SettingsController::class, 'update']);

        // WhatsApp
        Route::post('whatsapp/test-connection', [\App\Http\Controllers\Api\WhatsAppController::class, 'testConnection']);
        Route::post('whatsapp/send', [\App\Http\Controllers\Api\WhatsAppController::class, 'sendMessage']);

        // ── Phase 2: New Modules ──────────────────────────────

        // Driver Guarantees
        Route::apiResource('guarantees', DriverGuaranteeController::class)->except(['update']);
        Route::post('guarantees/{guarantee}/return', [DriverGuaranteeController::class, 'returnItem']);

        // Vehicle Expenses
        Route::get('vehicle-expenses/summary', [VehicleExpenseController::class, 'summary']);
        Route::apiResource('vehicle-expenses', VehicleExpenseController::class);

        // Salary Advances
        Route::apiResource('salary-advances', SalaryAdvanceController::class)->except(['update']);
        Route::post('salary-advances/{salaryAdvance}/cancel', [SalaryAdvanceController::class, 'cancel']);
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


