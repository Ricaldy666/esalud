<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Services\TwoFactorSession;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase Seguridad 2 (2026-08-31) -- unico punto de la aplicacion que decide
 * si una sesion con password ya validada puede considerarse completamente
 * autenticada cuando el usuario tiene 2FA activo.
 *
 * Se aplica a TODAS las rutas protegidas salvo /auth/logout, /auth/me y
 * /auth/2fa/verify (las 3 unicas que deben seguir siendo alcanzables
 * mientras el challenge esta pendiente). No debe existir ninguna otra ruta
 * que permita evadir este chequeo -- fail-closed: cualquier ambiguedad
 * (sesion pendiente pero expirada, o sin usuario resuelto) rechaza.
 */
class EnsureTwoFactorVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!TwoFactorSession::isPending($request)) {
            return $next($request);
        }

        if (TwoFactorSession::isExpired($request)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException('El desafío de doble factor expiró. Inicie sesión nuevamente.');
        }

        throw new AuthenticationException('Debe completar la verificación de doble factor.');
    }
}
