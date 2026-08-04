<?php

namespace App\Policies;

use App\Models\User;

class FeatureFlagPolicy
{
    public function view(User $user): bool
    {
        return true;
    }

    public function update(User $user): bool
    {
        return $user->hasRole('Administrador');
    }
}
