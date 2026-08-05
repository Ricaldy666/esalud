<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Superadmin', 'Administrador']);
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasAnyRole(['Superadmin', 'Administrador']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['Superadmin', 'Administrador']);
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasAnyRole(['Superadmin', 'Administrador']);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->hasAnyRole(['Superadmin', 'Administrador']);
    }
}
