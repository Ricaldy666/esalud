<?php

namespace App\Providers;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemTemplate;
use App\Domain\REM\Models\RemUpload;
use App\Models\User;
use App\Policies\ActivityLogPolicy;
use App\Policies\HealthCenterPolicy;
use App\Policies\RemDataPolicy;
use App\Policies\RemTemplatePolicy;
use App\Policies\RemUploadPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        User::class => UserPolicy::class,
        HealthCenter::class => HealthCenterPolicy::class,
        Activity::class => ActivityLogPolicy::class,
        RemUpload::class => RemUploadPolicy::class,
        RemTemplate::class => RemTemplatePolicy::class,
        RemData::class => RemDataPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
