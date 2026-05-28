<?php

namespace App\Domain\Auth\Controllers;

use App\Domain\Auth\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (!Auth::attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            true
        )) {
            return response()->json([
                'data' => null,
                'message' => 'Credenciales inválidas',
                'errors' => ['email' => ['Las credenciales no coinciden con nuestros registros.']],
            ], 422);
        }

        $request->session()->regenerate();
        $user = Auth::user();
        $user->update(['last_login_at' => now()]);

        return response()->json([
            'data' => new UserResource($user),
            'message' => 'Inicio de sesión exitoso',
            'errors' => null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'data' => null,
            'message' => 'Sesión cerrada exitosamente',
            'errors' => null,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
            'message' => 'Usuario autenticado',
            'errors' => null,
        ]);
    }
}
