<?php

namespace App\Providers;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Services\TenantAuthorizer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();
        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $this->shouldRecordEntry($entry, $isLocal);
        });
    }

    protected function shouldRecordEntry(IncomingEntry $entry, bool $isLocal): bool
    {
        return $isLocal
            || $this->canAccessTelescope(Auth::user())
            || $entry->isReportableException()
            || $entry->isFailedRequest()
            || $entry->isFailedJob()
            || $entry->isScheduledTask()
            || $entry->hasMonitoredTag();
    }

    protected function canAccessTelescope(mixed $user): bool
    {
        // SaaS mode (§2): Telescope is platform-operator only (raw
        // is_admin flag), not the Admin role. When SaaS mode is off
        // this is the unchanged isAdmin() check.
        return $user instanceof User && TenantAuthorizer::isPlatformAreaAdmin($user);
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function (User $user) {
            return $this->canAccessTelescope($user);
        });
    }
}
