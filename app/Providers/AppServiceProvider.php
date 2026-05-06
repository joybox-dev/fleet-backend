<?php

namespace App\Providers;

use App\Services\ErpNext\ErpNextClient;
use App\Services\ErpNext\ErpNextAccountResolver;
use App\Services\ErpNext\ErpNextService;
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
        //
    }
}
