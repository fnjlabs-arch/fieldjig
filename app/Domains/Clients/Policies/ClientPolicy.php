<?php

namespace App\Domains\Clients\Policies;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Services\TenantAuthorizer;
use App\Domains\Clients\Models\Client;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('clients.view');
    }

    public function view(User $user, Client $client): bool
    {
        if (! $user->hasPermission('clients.view')) {
            return false;
        }

        // Tenancy gate: a tenant Admin role must never see another org's client.
        return app(TenantAuthorizer::class)->canAccess($client->organization_id, $user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('clients.create');
    }

    public function update(User $user, Client $client): bool
    {
        if (! $user->hasPermission('clients.edit')) {
            return false;
        }

        return app(TenantAuthorizer::class)->canAccess($client->organization_id, $user);
    }

    public function delete(User $user, Client $client): bool
    {
        if (! $user->hasPermission('clients.delete')) {
            return false;
        }

        return app(TenantAuthorizer::class)->canAccess($client->organization_id, $user);
    }
}
