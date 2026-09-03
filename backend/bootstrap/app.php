<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        App\Providers\AuthServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Solo EnsureFrontendRequestsAreStateful -- su propio
        // frontendMiddleware() ya aplica EncryptCookies,
        // AddQueuedCookiesToResponse, StartSession y CSRF internamente para
        // requests stateful. Agregarlos tambien aqui (como estaba antes)
        // los duplicaba: el segundo EncryptCookies intentaba desencriptar
        // una cookie que el primer paso ya habia desencriptado/mutado en el
        // propio $request, fallaba, la ponia en null, y el segundo
        // StartSession -- sin ningun ID de sesion que leer -- creaba una
        // sesion nueva vacia que pisaba la sesion real ya cargada. Efecto
        // observado: /auth/session respondia authenticated:false en cada
        // request posterior al login, aunque la cookie llegara correcta.
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            '2fa.verified' => \App\Http\Middleware\EnsureTwoFactorVerified::class,
        ]);

        $middleware->redirectTo(
            guests: fn (Request $request) => $request->is('api/*') ? null : '/login',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'data' => null,
                'message' => 'No autenticado',
                'errors' => ['auth' => ['Debe iniciar sesión para acceder a este recurso.']],
            ], 401);
        });
    })->create();
