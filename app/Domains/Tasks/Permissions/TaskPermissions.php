<?php

namespace App\Domains\Tasks\Permissions;

class TaskPermissions
{
    public const VIEW = [
        'resource' => 'tasks',
        'action' => 'view',
        'description' => 'View tasks',
        'built_in_roles' => ['User'],
    ];

    public const CREATE = [
        'resource' => 'tasks',
        'action' => 'create',
        'description' => 'Create tasks',
        'built_in_roles' => ['User'],
    ];

    public const EDIT = [
        'resource' => 'tasks',
        'action' => 'edit',
        'description' => 'Edit tasks',
        'built_in_roles' => ['User'],
    ];

    public const EDIT_STATUS = [
        'resource' => 'tasks',
        'action' => 'edit-status',
        'description' => 'Edit task status',
        'built_in_roles' => ['User'],
    ];

    public const EDIT_PRIORITY = [
        'resource' => 'tasks',
        'action' => 'edit-priority',
        'description' => 'Edit task priority',
    ];

    public const EDIT_ASSIGNEE = [
        'resource' => 'tasks',
        'action' => 'edit-assignee',
        'description' => 'Edit task assignee',
    ];

    public const EDIT_PROGRESS = [
        'resource' => 'tasks',
        'action' => 'edit-progress',
        'description' => 'Edit task progress',
        'built_in_roles' => ['User'],
    ];

    public const EDIT_NOTES = [
        'resource' => 'tasks',
        'action' => 'edit-notes',
        'description' => 'Edit task notes',
        'built_in_roles' => ['User'],
    ];

    public const DELETE = [
        'resource' => 'tasks',
        'action' => 'delete',
        'description' => 'Delete tasks',
    ];

    public static function all(): array
    {
        return [
            self::VIEW,
            self::CREATE,
            self::EDIT,
            self::EDIT_STATUS,
            self::EDIT_PRIORITY,
            self::EDIT_ASSIGNEE,
            self::EDIT_PROGRESS,
            self::EDIT_NOTES,
            self::DELETE,
        ];
    }
}
