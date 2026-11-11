<?php

namespace App\Core\Billing\Providers;

use App\Core\Billing\Contracts\BillingProvider;
use App\Core\Billing\Exceptions\BillingNotConfiguredException;
use App\Core\Billing\Providers\LemonSqueezyProvider;
use App\Core\Billing\Providers\PaddleProvider;
use App\Core\Billing\Services\BillingManager;
use App\Core\Billing\Services\LemonSqueezyWebhookHandler;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BillingManager::class);
        $this->app->singleton(LemonSqueezyWebhookHandler::class);

        // The configured gateway. Tests override this binding with a
        // fake provider via app()->instance(BillingProvider::class, ...).
        $this->app->bind(BillingProvider::class, function (): BillingProvider {
            return match (config('billing.provider', 'lemonsqueezy')) {
                'lemonsqueezy' => app(LemonSqueezyProvider::class),
                'paddle' => app(PaddleProvider::class),
                default => throw new BillingNotConfiguredException('Unknown billing provider: '.config('billing.provider')),
            };
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadViewsFrom(__DIR__.'/../Resources/Views', 'billing');
        $this->registerRoutes();
        $this->registerUIComponents();
    }

    private function registerRoutes(): void
    {
        // Middleware lives in the routes file itself: the panel/checkout/
        // cancel routes need auth, the webhook route must not have it.
        Route::group([], __DIR__.'/../Routes/web.php');
    }

    private function registerUIComponents(): void
    {
        Livewire::addNamespace('billing', classNamespace: 'App\\Core\\Billing\\Livewire');
    }
}
