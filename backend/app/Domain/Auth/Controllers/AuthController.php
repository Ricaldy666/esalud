<?php

namespace App\Domain\Auth\Controllers;

use App\Domain\Auth\Requests\LoginRequest;
use App\Domain\Auth\Services\TwoFactorSession;
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

        // Password correcta, pero si el usuario tiene 2FA activo la sesion
        // NUNCA se considera completamente autenticada aqui -- queda
        // marcada pendiente y el unico camino para desbloquearla es
        // TwoFactorController::verify(). Ninguna otra ruta debe inferir
        // autenticacion completa a partir de este punto (ver 2fa.verified).
        if ($user->hasTwoFactorEnabled()) {
            TwoFactorSession::markPending($request, $user->id);

            return response()->json([
                'data' => ['requires_2fa' => true],
                'message' => 'Se requiere verificación de doble factor.',
                'errors' => null,
            ]);
        }

        $user->update(['last_login_at' => now()]);

        return response()->json([
            'data' => new UserResource($user),
            'message' => 'Inicio de sesión exitoso',
            'errors' => null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        TwoFactorSession::clear($request);
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
        if (TwoFactorSession::isPending($request)) {
            if (TwoFactorSession::isExpired($request)) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json([
                    'data' => null,
                    'message' => 'No autenticado',
                    'errors' => ['auth' => ['Debe iniciar sesión para acceder a este recurso.']],
                ], 401);
            }

            return response()->json([
                'data' => ['requires_2fa' => true],
                'message' => 'Verificación de doble factor pendiente',
                'errors' => null,
            ]);
        }

        $user = $request->user()->load(['healthCenters']);
        return response()->json([
            'data' => new UserResource($user),
            'message' => 'Usuario autenticado',
            'errors' => null,
        ]);
    }
}
