<?php

use App\Core\Billing\Middleware\EnsureSubscriptionNotCanceled;
use App\Core\Identity\Middleware\EnsurePasswordChanged;
use App\Core\Tenancy\Middleware\ResolveTenant;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\LoadSettings;
use App\Http\Middleware\RedirectMobileRoutes;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->trustHosts(subdomains: false);

        $middleware->group('mobile', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            SubstituteBindings::class,
            EnsurePasswordChanged::class,
            RedirectMobileRoutes::class,
            AddSecurityHeaders::class,
        ]);

        $middleware->web(append: [
            EnsurePasswordChanged::class,
            RedirectMobileRoutes::class,
            AddSecurityHeaders::class,
            LoadSettings::class,
            // SaaS tenancy (§1): resolves the tenant context; no-op when
            // SAAS_MODE is off or the request is a guest.
            ResolveTenant::class,
            // SaaS billing (§2): canceled orgs keep no app access except
            // the billing panel; no-op when SAAS_MODE is off.
            EnsureSubscriptionNotCanceled::class,
        ]);

        // Lemon Squeezy webhooks are authenticated by the X-Signature
        // HMAC, not by a session CSRF token (§2).
        $middleware->validateCsrfTokens(except: [
            'billing/webhook/lemonsqueezy',
        ]);

        $middleware->alias([
            'resolve-tenant' => ResolveTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A double-submitted form (e.g. login) carries a CSRF token that was
        // rotated by the first request. Recover gracefully instead of a 419 page.
        // Livewire and JSON requests keep the 419 so client-side hooks can reload.
        // Laravel converts TokenMismatchException into a 419 HttpException before
        // render callbacks run, so match on the wrapped previous exception.
        $exceptions->render(function (HttpException $exception, Request $request) {
            if (! $exception->getPrevious() instanceof TokenMismatchException) {
                return null;
            }

            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return null;
            }

            if ($request->user() !== null) {
                return redirect()->intended(config('fortify.home', '/dashboard'));
            }

            return redirect()->back()
                ->withInput($request->except('password', 'password_confirmation', 'current_password', '_token'))
                ->withErrors(['login' => __('Your session expired. Please try again.')]);
        });
    })->create();
