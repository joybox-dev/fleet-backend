<?php

namespace App\Providers;

use App\Services\ErpNext\ErpNextClient;
use App\Services\ErpNext\ErpNextAccountResolver;
use App\Services\ErpNext\ErpNextService;
use Illuminate\Support\ServiceProvider;

// Models
use App\Models\Company;
use App\Models\Employee;
use App\Models\Client;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Models\DailyLog;
use App\Models\CashSettlement;
use App\Models\SalaryAdvance;

// Observers
use App\Observers\CompanyObserver;
use App\Observers\EmployeeObserver;
use App\Observers\ClientObserver;
use App\Observers\VehicleObserver;
use App\Observers\ViolationObserver;
use App\Observers\DailyLogObserver;
use App\Observers\CashSettlementObserver;
use App\Observers\SalaryAdvanceObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ERPNext client — singleton so auth session persists within a request
        $this->app->singleton(ErpNextClient::class, function () {
            return new ErpNextClient();
        });

        // Account resolver — singleton so the 24h cache is shared
        $this->app->singleton(ErpNextAccountResolver::class, function ($app) {
            return new ErpNextAccountResolver($app->make(ErpNextClient::class));
        });

        // Main orchestrator service
        $this->app->singleton(ErpNextService::class, function ($app) {
            return new ErpNextService(
                $app->make(ErpNextClient::class),
                $app->make(ErpNextAccountResolver::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ── ERPNext Sync Observers ──────────────────────────────────────
        // These ensure that ALL data changes (web UI, API, artisan, seeders)
        // automatically dispatch sync jobs to the erpnext-sync queue.
        Company::observe(CompanyObserver::class);
        Employee::observe(EmployeeObserver::class);
        Client::observe(ClientObserver::class);
        Vehicle::observe(VehicleObserver::class);
        Violation::observe(ViolationObserver::class);
        DailyLog::observe(DailyLogObserver::class);
        CashSettlement::observe(CashSettlementObserver::class);
        SalaryAdvance::observe(SalaryAdvanceObserver::class);
    }
}
