<?php

namespace App\Domain\Auth\Services;

use Illuminate\Http\Request;

/**
 * Estado de sesion del "challenge" 2FA pendiente (Fase Seguridad 2).
 *
 * Se establece en AuthController::login() inmediatamente despues de validar
 * la password de un usuario con 2FA activo -- Auth::login() ya se ejecuto
 * (existe sesion real, necesaria para CSRF/Sanctum), pero la sesion queda
 * marcada "pendiente" hasta que TwoFactorController::verify() la resuelva.
 * EnsureTwoFactorVerified es el unico punto que lee este estado para
 * bloquear rutas -- ningun otro codigo debe inferir autenticacion completa
 * sin pasar por ahi.
 */
class TwoFactorSession
{
    private const KEY_PENDING_USER_ID = 'auth.2fa_pending_user_id';

    private const KEY_EXPIRES_AT = 'auth.2fa_pending_expires_at';

    /** Minutos de validez del challenge antes de exigir login nuevamente. */
    public const CHALLENGE_TTL_MINUTES = 5;

    public static function markPending(Request $request, int $userId): void
    {
        $request->session()->put(self::KEY_PENDING_USER_ID, $userId);
        $request->session()->put(self::KEY_EXPIRES_AT, now()->addMinutes(self::CHALLENGE_TTL_MINUTES)->timestamp);
    }

    public static function clear(Request $request): void
    {
        $request->session()->forget([self::KEY_PENDING_USER_ID, self::KEY_EXPIRES_AT]);
    }

    public static function isPending(Request $request): bool
    {
        return $request->session()->has(self::KEY_PENDING_USER_ID);
    }

    public static function pendingUserId(Request $request): ?int
    {
        return $request->session()->get(self::KEY_PENDING_USER_ID);
    }

    public static function isExpired(Request $request): bool
    {
        $expiresAt = $request->session()->get(self::KEY_EXPIRES_AT);

        return $expiresAt === null || now()->timestamp > $expiresAt;
    }
}
