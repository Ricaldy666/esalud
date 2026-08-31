<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Limitadores nativos de Laravel para flujos de autenticacion sensibles
     * (Fase Seguridad 1, prerequisito de 2FA, 2026-08-31).
     *
     * "login": publico, sin sesion -- se limita por email+IP para no permitir
     * fuerza bruta sobre una cuenta ni sobre una IP, sin bloquear a un
     * usuario legitimo que comparte IP con otros (oficina/VPN).
     *
     * "sensitive-user-write": autenticado, ya gateado por UserPolicy -- limite
     * generoso (30/min) pensado solo para frenar automatizacion/errores de
     * scripting, nunca para estorbar a un administrador humano.
     *
     * Reutilizar estos mismos limitadores (o el mismo criterio: "login" para
     * cualquier endpoint publico que verifique un secreto de un solo intento
     * -- ej. el futuro challenge 2FA o un futuro reset de password por email
     * -- y "sensitive-user-write" para acciones administrativas autenticadas
     * que modifiquen credenciales) cuando se implementen esos flujos.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $key = strtolower((string) $request->input('email')) . '|' . $request->ip();

            return Limit::perMinute(5)->by($key)->response(function (Request $request, array $headers) {
                return response()->json([
                    'data' => null,
                    'message' => 'Demasiados intentos de inicio de sesión. Intente nuevamente en unos minutos.',
                    'errors' => ['email' => ['Demasiados intentos. Espere antes de volver a intentar.']],
                ], 429, $headers);
            });
        });

        RateLimiter::for('sensitive-user-write', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(30)->by($key)->response(function (Request $request, array $headers) {
                return response()->json([
                    'data' => null,
                    'message' => 'Demasiadas solicitudes. Intente nuevamente en unos minutos.',
                    'errors' => ['request' => ['Límite de solicitudes alcanzado.']],
                ], 429, $headers);
            });
        });

        // Fase Seguridad 2 (2026-08-31) -- protege /auth/2fa/verify (login) y
        // /auth/2fa/confirm (enrolamiento). Mismo criterio estricto que
        // "login": espacio de busqueda pequeño (TOTP de 6 digitos), keyed
        // por usuario ya autenticado (la sesion con password ya existe en
        // ambos casos, nunca por IP sola).
        RateLimiter::for('2fa-verify', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(5)->by($key)->response(function (Request $request, array $headers) {
                return response()->json([
                    'data' => null,
                    'message' => 'Demasiados intentos. Intente nuevamente en unos minutos.',
                    'errors' => ['code' => ['Demasiados intentos. Espere antes de volver a intentar.']],
                ], 429, $headers);
            });
        });
    }
}
