<?php

namespace App\Domains\Tasks\Permissions;

class TaskCategoryPermissions
{
    public const VIEW = [
        'resource' => 'task-categories',
        'action' => 'view',
        'description' => 'View task categories',
        'built_in_roles' => ['User'],
    ];

    public const CREATE = [
        'resource' => 'task-categories',
        'action' => 'create',
        'description' => 'Create task categories',
    ];

    public const EDIT = [
        'resource' => 'task-categories',
        'action' => 'edit',
        'description' => 'Edit task categories',
    ];

    public const DELETE = [
        'resource' => 'task-categories',
        'action' => 'delete',
        'description' => 'Delete task categories',
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
