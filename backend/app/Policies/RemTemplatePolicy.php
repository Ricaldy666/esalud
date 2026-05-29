<?php

namespace App\Policies;

use App\Domain\REM\Models\RemTemplate;
use App\Models\User;

class RemTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RemTemplate $remTemplate): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Administrador');
    }

    public function update(User $user, RemTemplate $remTemplate): bool
    {
        return $user->hasRole('Administrador');
    }

    public function delete(User $user, RemTemplate $remTemplate): bool
    {
        return $user->hasRole('Administrador');
    }
}
