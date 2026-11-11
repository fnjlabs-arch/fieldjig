<?php

namespace App\Domains\Dailies\Permissions;

class DailyPermissions
{
    public const VIEW = [
        'resource' => 'dailies',
        'action' => 'view',
        'description' => 'View daily reports',
        'built_in_roles' => ['User'],
    ];

    public const VIEW_ALL = [
        'resource' => 'dailies',
        'action' => 'view-all',
        'description' => 'View all daily reports',
    ];

    public const CREATE = [
        'resource' => 'dailies',
        'action' => 'create',
        'description' => 'Create daily reports',
        'built_in_roles' => ['User'],
    ];

    public const EDIT = [
        'resource' => 'dailies',
        'action' => 'edit',
        'description' => 'Edit daily reports',
        'built_in_roles' => ['User'],
    ];

    public const DELETE = [
        'resource' => 'dailies',
        'action' => 'delete',
        'description' => 'Delete daily reports',
    ];

    public const SUBMIT = [
        'resource' => 'dailies',
        'action' => 'submit',
        'description' => 'Submit daily reports',
        'built_in_roles' => ['User'],
    ];

    public const APPROVE = [
        'resource' => 'dailies',
        'action' => 'approve',
        'description' => 'Approve daily reports',
    ];

    public const REJECT = [
        'resource' => 'dailies',
        'action' => 'reject',
        'description' => 'Reject daily reports',
    ];

    public const VIEW_REPORTS = [
        'resource' => 'dailies',
        'action' => 'view-reports',
        'description' => 'View daily reports dashboards',
    ];

    public static function all(): array
    {
        return [
            self::VIEW,
            self::VIEW_ALL,
            self::CREATE,
            self::EDIT,
            self::DELETE,
            self::SUBMIT,
            self::APPROVE,
            self::REJECT,
            self::VIEW_REPORTS,
        ];
    }
}
