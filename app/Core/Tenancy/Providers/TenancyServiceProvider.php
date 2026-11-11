<?php

namespace App\Core\Tenancy\Providers;

use App\Core\Tenancy\Services\OrganizationService;
use App\Core\Tenancy\Services\SignupService;
use App\Core\Tenancy\Services\TenantAuthorizer;
use App\Core\Tenancy\Services\TenantContext;
use App\Core\Tenancy\Services\TenantProjectCreation;
use App\Core\Tenancy\Services\TenantResolver;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(TenantResolver::class);
        $this->app->singleton(TenantAuthorizer::class);
        $this->app->singleton(TenantProjectCreation::class);
        $this->app->singleton(OrganizationService::class);
        $this->app->singleton(SignupService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        // Middleware lives in the routes file itself: signup is guest +
        // throttled, org endpoints need auth.
        Route::group([], __DIR__.'/../Routes/web.php');
    }
}
