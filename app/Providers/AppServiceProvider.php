<?php

namespace App\Providers;

use App\Models\CashSettlement;
use App\Models\Client;
use App\Models\Company;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\SalaryAdvance;
// Models
use App\Models\Vehicle;
use App\Models\Violation;
use App\Observers\CashSettlementObserver;
use App\Observers\ClientObserver;
use App\Observers\CompanyObserver;
use App\Observers\DailyLogObserver;
use App\Observers\EmployeeObserver;
use App\Observers\SalaryAdvanceObserver;
// Observers
use App\Observers\VehicleObserver;
use App\Observers\ViolationObserver;
use App\Services\ErpNext\ErpNextAccountResolver;
use App\Services\ErpNext\ErpNextClient;
use App\Services\ErpNext\ErpNextService;
use App\Validation\ExistsInCompany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ERPNext client — singleton so auth session persists within a request
        $this->app->singleton(ErpNextClient::class, function () {
            return new ErpNextClient;
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
        // ── ERPNext Sync Observers (DISABLED — building native accounting) ──
        // Full ERPNext integration preserved on branch 'feature/multi-tenant'.
        // Company::observe(CompanyObserver::class);
        // Employee::observe(EmployeeObserver::class);
        // Client::observe(ClientObserver::class);
        // Vehicle::observe(VehicleObserver::class);
        // Violation::observe(ViolationObserver::class);
        // DailyLog::observe(DailyLogObserver::class);
        DailyLog::observe(DailyLogObserver::class);
        // CashSettlement::observe(CashSettlementObserver::class);
        // SalaryAdvance::observe(SalaryAdvanceObserver::class);

        // A record picked in a form must belong to the company the request works in.
        Validator::extend(
            'exists_in_company',
            fn (string $attribute, mixed $value, array $parameters) => ExistsInCompany::validate($attribute, $value, $parameters),
            'القيمة المختارة في :attribute غير موجودة في شركتك.'
        );
    }
}
