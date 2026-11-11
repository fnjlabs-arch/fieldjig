<?php

use App\Core\Announcement\Providers\AnnouncementServiceProvider;
use App\Core\Assets\Providers\AssetsServiceProvider;
use App\Core\Billing\Providers\BillingServiceProvider;
use App\Core\Audit\Providers\AuditServiceProvider;
use App\Core\Auth\Permission\Providers\PermissionServiceProvider;
use App\Core\Auth\Role\Providers\RoleServiceProvider;
use App\Core\Auth\User\Providers\AuthUserServiceProvider;
use App\Core\Cpanel\Providers\CpanelServiceProvider;
use App\Core\Dashboard\Providers\DashboardServiceProvider;
use App\Core\Files\Providers\FilesServiceProvider;
use App\Core\Identity\Providers\FortifyServiceProvider;
use App\Core\Identity\Providers\UserServiceProvider;
use App\Core\Notification\Providers\NotificationServiceProvider;
use App\Core\Queue\Providers\QueueManagerServiceProvider;
use App\Core\Scheduler\Providers\SchedulerServiceProvider;
use App\Core\Settings\Providers\SettingServiceProvider;
use App\Core\Tenancy\Providers\TenancyServiceProvider;
use App\Core\WeatherApi\Providers\WeatherApiServiceProvider;
use App\Core\Zoom\Providers\ZoomServiceProvider;
use App\Domains\Providers\DomainServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\TelescopeServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AnnouncementServiceProvider::class,
    AuditServiceProvider::class,
    PermissionServiceProvider::class,
    RoleServiceProvider::class,
    AuthUserServiceProvider::class,
    CpanelServiceProvider::class,
    DashboardServiceProvider::class,
    FilesServiceProvider::class,
    FortifyServiceProvider::class,
    UserServiceProvider::class,
    NotificationServiceProvider::class,
    QueueManagerServiceProvider::class,
    SchedulerServiceProvider::class,
    SettingServiceProvider::class,
    TenancyServiceProvider::class,
    WeatherApiServiceProvider::class,
    ZoomServiceProvider::class,
    DomainServiceProvider::class,
    AppServiceProvider::class,
    TelescopeServiceProvider::class,
    AssetsServiceProvider::class,
    BillingServiceProvider::class,
    VoltServiceProvider::class,
];
