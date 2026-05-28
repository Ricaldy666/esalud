<?php

namespace App\Domain\Users\Controllers;

use App\Domain\Users\Requests\StoreUserRequest;
use App\Domain\Users\Requests\UpdateUserRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()->with(['healthCenter', 'roles']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('rut', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->whereHas('roles', fn($q) => $q->where('name', $role));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($centerId = $request->query('health_center_id')) {
            $query->where('health_center_id', $centerId);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
            'message' => 'Usuarios obtenidos exitosamente',
            'errors' => null,
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);
        $user->load(['healthCenter', 'roles']);

        return response()->json([
            'data' => new UserResource($user),
            'message' => 'Usuario obtenido exitosamente',
            'errors' => null,
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validated();
        $role = $data['role'] ?? 'Lector';
        unset($data['role'], $data['password_confirmation']);
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);
        $user->assignRole($role);
        $user->load(['healthCenter', 'roles']);

        return response()->json([
            'data' => new UserResource($user),
            'message' => 'Usuario creado exitosamente',
            'errors' => null,
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $role = $data['role'] ?? null;
        unset($data['role']);

        $user->update($data);

        if ($role) {
            $user->syncRoles([$role]);
        }

        $user->load(['healthCenter', 'roles']);

        return response()->json([
            'data' => new UserResource($user),
            'message' => 'Usuario actualizado exitosamente',
            'errors' => null,
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        if ($user->id === $request->user()->id) {
            return response()->json([
                'data' => null,
                'message' => 'No puedes eliminar tu propia cuenta',
                'errors' => ['user' => ['Operación no permitida']],
            ], 422);
        }

        $user->delete();

        return response()->json([
            'data' => null,
            'message' => 'Usuario eliminado exitosamente',
            'errors' => null,
        ]);
    }
}
