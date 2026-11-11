<?php

namespace App\Domains\Clients\Permissions;

class ClientPermissions
{
    public const VIEW = [
        'resource' => 'clients',
        'action' => 'view',
        'description' => 'View clients',
        'built_in_roles' => ['User'],
    ];

    public const CREATE = [
        'resource' => 'clients',
        'action' => 'create',
        'description' => 'Create clients',
    ];

    public const EDIT = [
        'resource' => 'clients',
        'action' => 'edit',
        'description' => 'Edit clients',
    ];

    public const DELETE = [
        'resource' => 'clients',
        'action' => 'delete',
        'description' => 'Delete clients',
    ];

    public static function all(): array
    {
        return [
            self::VIEW,
            self::CREATE,
            self::EDIT,
            self::DELETE,
        ];
    }
}
