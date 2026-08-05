<?php

namespace App\Policies;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Models\User;

class HealthCenterPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, HealthCenter $healthCenter): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['Superadmin', 'Administrador']);
    }

    public function update(User $user, HealthCenter $healthCenter): bool
    {
        return $user->hasAnyRole(['Superadmin', 'Administrador']);
    }

    public function delete(User $user, HealthCenter $healthCenter): bool
    {
        return $user->hasAnyRole(['Superadmin', 'Administrador']);
    }
}
