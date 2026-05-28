<?php

namespace App\Providers;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Models\User;
use App\Policies\ActivityLogPolicy;
use App\Policies\HealthCenterPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        User::class => UserPolicy::class,
        HealthCenter::class => HealthCenterPolicy::class,
        Activity::class => ActivityLogPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
