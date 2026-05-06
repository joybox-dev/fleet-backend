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
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\UploadController;

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
Route::middleware('auth:sanctum')->group(function () {

    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // ── File Uploads (all roles) ─────────────────────────────────────
    Route::post('upload', [UploadController::class, 'store']);
    Route::post('upload/multiple', [UploadController::class, 'storeMultiple']);

    // ── Dashboard (all roles) ────────────────────────────────────────
    Route::get('dashboard/summary', [DashboardController::class, 'summary']);

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
        Route::apiResource('violations', ViolationController::class);

        // Maintenance — report + view
        Route::get('maintenance', [MaintenanceController::class, 'index']);
        Route::get('maintenance/{maintenance}', [MaintenanceController::class, 'show']);
        Route::post('maintenance', [MaintenanceController::class, 'store']);

        // Cash Settlements — record handover
        Route::post('cash-settlements', [CashSettlementController::class, 'store']);
        Route::get('cash-settlements', [CashSettlementController::class, 'index']);
        Route::get('cash-settlements/pending', [CashSettlementController::class, 'pending']);
    });

    // ═══════════════════════════════════════════════════════════════════
    // ADMIN ONLY: Management & configuration
    // ═══════════════════════════════════════════════════════════════════
    Route::middleware('role:admin')->group(function () {

        // Clients — full CRUD
        Route::apiResource('clients', ClientController::class);

        // Contracts — full CRUD + lock
        Route::apiResource('contracts', ContractController::class);
        Route::post('contracts/{contract}/lock', [ContractController::class, 'lock']);

        // Employees — full CRUD + balance
        Route::apiResource('employees', EmployeeController::class);
        Route::get('employees/{employee}/balance', [EmployeeController::class, 'balance']);

        // Vehicles — create/update/delete (operators can only view)
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
        });
    });
});
