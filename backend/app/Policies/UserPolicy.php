<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Administrador');
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasRole('Administrador');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Administrador');
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasRole('Administrador');
    }

    public function delete(User $user, User $model): bool
    {
        return $user->hasRole('Administrador');
    }
}
