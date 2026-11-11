<?php

namespace App\Domains\Timecards\Permissions;

class TimecardPermissions
{
    public const VIEW = [
        'resource' => 'timecards',
        'action' => 'view',
        'description' => 'View timecards',
        'built_in_roles' => ['User'],
    ];

    public const VIEW_ALL = [
        'resource' => 'timecards',
        'action' => 'view-all',
        'description' => 'View all timecards',
    ];

    public const CREATE = [
        'resource' => 'timecards',
        'action' => 'create',
        'description' => 'Create timecards',
        'built_in_roles' => ['User'],
    ];

    public const EDIT = [
        'resource' => 'timecards',
        'action' => 'edit',
        'description' => 'Edit timecards',
        'built_in_roles' => ['User'],
    ];

    public const DELETE = [
        'resource' => 'timecards',
        'action' => 'delete',
        'description' => 'Delete timecards',
    ];

    public const SUBMIT = [
        'resource' => 'timecards',
        'action' => 'submit',
        'description' => 'Submit timecards',
        'built_in_roles' => ['User'],
    ];

    public const APPROVE = [
        'resource' => 'timecards',
        'action' => 'approve',
        'description' => 'Approve timecards',
    ];

    public const REJECT = [
        'resource' => 'timecards',
        'action' => 'reject',
        'description' => 'Reject timecards',
    ];

    public const VIEW_REPORTS = [
        'resource' => 'timecards',
        'action' => 'view-reports',
        'description' => 'View timecard reports',
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
