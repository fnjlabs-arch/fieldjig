<?php

namespace App\Core\Announcement\Permissions;

class AnnouncementPermissions
{
    public const VIEW = [
        'resource' => 'announcements',
        'action' => 'view',
        'description' => 'View announcements',
        'built_in_roles' => ['User'],
    ];

    public const CREATE = [
        'resource' => 'announcements',
        'action' => 'create',
        'description' => 'Create announcements',
    ];

    public const EDIT = [
        'resource' => 'announcements',
        'action' => 'edit',
        'description' => 'Edit announcements',
    ];

    public const DELETE = [
        'resource' => 'announcements',
        'action' => 'delete',
        'description' => 'Delete announcements',
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
