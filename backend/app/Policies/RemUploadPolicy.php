<?php

namespace App\Policies;

use App\Domain\REM\Models\RemUpload;
use App\Models\User;

class RemUploadPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RemUpload $remUpload): bool
    {
        if ($user->hasRole('Administrador')) {
            return true;
        }

        return $remUpload->health_center_id === $user->health_center_id
            || $remUpload->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Administrador') || $user->hasRole('Analista');
    }

    public function delete(User $user, RemUpload $remUpload): bool
    {
        return $user->hasRole('Administrador');
    }

    public function restore(User $user, RemUpload $remUpload): bool
    {
        return $user->hasRole('Administrador');
    }
}
