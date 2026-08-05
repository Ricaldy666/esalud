<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Superadmin', 'Administrador']);
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->hasAnyRole(['Superadmin', 'Administrador']);
    }
}
