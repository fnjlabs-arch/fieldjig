<?php

declare(strict_types=1);

namespace App\Domains\Plans\Permissions;

final class PlanPermissions
{
    public const VIEW = ['resource' => 'plans', 'action' => 'view', 'description' => 'View plans', 'built_in_roles' => ['User']];

    public const UPLOAD = ['resource' => 'plans', 'action' => 'upload', 'description' => 'Upload plan sets'];

    public const UPDATE = ['resource' => 'plans', 'action' => 'update', 'description' => 'Update plans'];

    public const DELETE = ['resource' => 'plans', 'action' => 'delete', 'description' => 'Delete plans'];

    public const PUBLISH_REVISION = ['resource' => 'plans', 'action' => 'publish-revision', 'description' => 'Publish plan revisions'];

    public const ANNOTATE = ['resource' => 'plans', 'action' => 'annotate', 'description' => 'Annotate plans', 'built_in_roles' => ['User']];

    public const MANAGE_ANNOTATIONS = ['resource' => 'plans', 'action' => 'manage-annotations', 'description' => 'Manage other users plan annotations'];

    public const MANAGE_LINKS = ['resource' => 'plans', 'action' => 'manage-links', 'description' => 'Manage plan links'];

    public const COMPARE = ['resource' => 'plans', 'action' => 'compare', 'description' => 'Compare plan revisions', 'built_in_roles' => ['User']];

    public const EXPORT = ['resource' => 'plans', 'action' => 'export', 'description' => 'Export plans'];

    /** @return array<int, array<string, string>> */
    public static function all(): array
    {
        return [
            self::VIEW, self::UPLOAD, self::UPDATE, self::DELETE, self::PUBLISH_REVISION,
            self::ANNOTATE, self::MANAGE_ANNOTATIONS, self::MANAGE_LINKS, self::COMPARE, self::EXPORT,
        ];
    }
}
