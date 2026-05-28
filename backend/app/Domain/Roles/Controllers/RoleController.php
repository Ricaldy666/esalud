<?php

namespace App\Domain\Roles\Controllers;

use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

class RoleController
{
    public function index(): JsonResponse
    {
        $roles = Role::query()->orderBy('name')->pluck('name');

        return response()->json([
            'data' => $roles,
            'message' => 'Roles obtenidos exitosamente',
            'errors' => null,
        ]);
    }
}
