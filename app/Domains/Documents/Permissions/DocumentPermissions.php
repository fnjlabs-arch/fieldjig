<?php

namespace App\Domains\Documents\Permissions;

class DocumentPermissions
{
    public const VIEW = [
        'resource' => 'documents',
        'action' => 'view',
        'description' => 'View documents',
        'built_in_roles' => ['User'],
    ];

    public const CREATE = [
        'resource' => 'documents',
        'action' => 'create',
        'description' => 'Upload documents',
    ];

    public const UPDATE = [
        'resource' => 'documents',
        'action' => 'update',
        'description' => 'Update documents',
    ];

    public const DELETE = [
        'resource' => 'documents',
        'action' => 'delete',
        'description' => 'Delete documents',
    ];

    public const PROMOTE_GLOBAL = [
        'resource' => 'documents',
        'action' => 'promote-global',
        'description' => 'Promote private documents to global visibility',
    ];

    public const DEMOTE_PRIVATE = [
        'resource' => 'documents',
        'action' => 'demote-private',
        'description' => 'Demote global documents to private visibility',
    ];

    public const MANAGE_PROJECT = [
        'resource' => 'documents',
        'action' => 'manage-project',
        'description' => 'Manage project-owned documents',
    ];

    public const SHARE = [
        'resource' => 'documents',
        'action' => 'share',
        'description' => 'Create and manage document shares',
    ];

    /**
     * @return array<int, array<string, string>>
     */
    public static function all(): array
    {
        return [
            self::VIEW,
            self::CREATE,
            self::UPDATE,
            self::DELETE,
            self::PROMOTE_GLOBAL,
            self::DEMOTE_PRIVATE,
            self::MANAGE_PROJECT,
            self::SHARE,
        ];
    }
}
