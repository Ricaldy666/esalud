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
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Superadmin') || $user->hasRole('Revisor') || $user->hasRole('Analista');
    }

    public function delete(User $user, RemUpload $remUpload): bool
    {
        return $user->hasRole('Superadmin');
    }

    public function restore(User $user, RemUpload $remUpload): bool
    {
        return $user->hasRole('Superadmin');
    }
}
