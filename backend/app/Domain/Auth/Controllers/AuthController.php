<?php

namespace App\Domain\Auth\Controllers;

use App\Domain\Auth\Requests\LoginRequest;
use App\Domain\Auth\Services\TwoFactorSession;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        // Politica ATHENEA: "recordar sesion" fue eliminado por completo --
        // ningun login, de ningun usuario, debe emitir la cookie "recaller"
        // de Laravel (Auth::attempt() sin segundo argumento => remember=false).
        if (!Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']])) {
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
        $state = $this->resolveSessionState($request);

        if ($state['status'] === 'unauthenticated') {
            return response()->json([
                'data' => null,
                'message' => 'No autenticado',
                'errors' => ['auth' => ['Debe iniciar sesión para acceder a este recurso.']],
            ], 401);
        }

        if ($state['status'] === 'requires_2fa') {
            return response()->json([
                'data' => ['requires_2fa' => true],
                'message' => 'Verificación de doble factor pendiente',
                'errors' => null,
            ]);
        }

        return response()->json([
            'data' => new UserResource($state['user']),
            'message' => 'Usuario autenticado',
            'errors' => null,
        ]);
    }

    /**
     * Estado de sesion, publico -- a diferencia de me() (protegida por
     * auth:sanctum, 401 si no hay sesion), esta ruta esta pensada para
     * consultarse SIN sesion (ej. useAuthInit al abrir /login) y por eso
     * responde SIEMPRE 200, con authenticated:false representando
     * explicitamente "no hay sesion" en vez de una excepcion HTTP. Nunca
     * expone el usuario salvo que authenticated sea true -- mismo criterio
     * que me() para el caso de 2FA pendiente.
     */
    public function session(Request $request): JsonResponse
    {
        $state = $this->resolveSessionState($request);

        return response()->json([
            'data' => [
                'authenticated' => $state['status'] === 'authenticated',
                'requires_2fa' => $state['status'] === 'requires_2fa',
                'user' => $state['status'] === 'authenticated' ? new UserResource($state['user']) : null,
            ],
            'message' => match ($state['status']) {
                'authenticated' => 'Sesión activa',
                'requires_2fa' => 'Verificación de doble factor pendiente',
                default => 'Sin sesión activa',
            },
            'errors' => null,
        ]);
    }

    /**
     * Unico punto que decide el estado de sesion/2FA -- compartido por
     * me() y session() para que nunca puedan divergir. Un challenge 2FA
     * vencido se trata como sesion inexistente (invalida lo que quedaba de
     * ella) en ambos casos.
     *
     * @return array{status: 'authenticated'|'requires_2fa'|'unauthenticated', user: ?User}
     */
    private function resolveSessionState(Request $request): array
    {
        if (TwoFactorSession::isPending($request)) {
            if (TwoFactorSession::isExpired($request)) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return ['status' => 'unauthenticated', 'user' => null];
            }

            return ['status' => 'requires_2fa', 'user' => null];
        }

        $user = $request->user();

        if ($user === null) {
            return ['status' => 'unauthenticated', 'user' => null];
        }

        return ['status' => 'authenticated', 'user' => $user->load(['healthCenters'])];
    }
}
