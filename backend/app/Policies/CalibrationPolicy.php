<?php

namespace App\Policies;

use App\Domain\Calibration\Models\Calibration;
use App\Models\User;

class CalibrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Superadmin', 'Analista', 'Revisor', 'Auditor']);
    }

    public function view(User $user, Calibration $calibration): bool
    {
        return $user->hasAnyRole(['Superadmin', 'Analista', 'Revisor', 'Auditor']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['Superadmin', 'Analista']);
    }

    public function update(User $user, Calibration $calibration): bool
    {
        if ($calibration->status !== 'draft') {
            return false;
        }

        return $user->hasAnyRole(['Superadmin', 'Analista']);
    }

    public function delete(User $user, Calibration $calibration): bool
    {
        if ($calibration->status !== 'draft') {
            return false;
        }

        return $user->hasRole('Superadmin');
    }
}
