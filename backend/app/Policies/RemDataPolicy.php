<?php

namespace App\Policies;

use App\Domain\REM\Models\RemData;
use App\Models\User;

class RemDataPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RemData $remData): bool
    {
        return $user->can('view', $remData->remUpload);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, RemData $remData): bool
    {
        return false;
    }

    public function delete(User $user, RemData $remData): bool
    {
        return false;
    }
}
