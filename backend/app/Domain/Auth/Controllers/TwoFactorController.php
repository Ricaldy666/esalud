<?php

namespace App\Domain\Auth\Controllers;

use App\Domain\Auth\Requests\TwoFactorChallengeRequest;
use App\Domain\Auth\Requests\TwoFactorConfirmRequest;
use App\Domain\Auth\Requests\TwoFactorSensitiveActionRequest;
use App\Domain\Auth\Services\TwoFactorAuthenticationService;
use App\Domain\Auth\Services\TwoFactorSession;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Fase Seguridad 2 (2026-08-31) -- enrolamiento, confirmacion, desactivacion,
 * regeneracion de codigos de recuperacion, y el challenge de login en si.
 *
 * Ningun metodo de este controlador imprime/loguea el secreto TOTP, un
 * codigo TOTP recibido, ni un codigo de recuperacion -- ni en la respuesta
 * (salvo la unica revelacion intencional de enroll()/confirm()) ni en
 * activity_log (solo se registran metadatos de la accion, nunca el valor).
 */
class TwoFactorController
{
    public function __construct(private readonly TwoFactorAuthenticationService $twoFactor)
    {
    }

    /**
     * Inicia un enrolamiento nuevo. Exige la password actual (defensa ante
     * secuestro de sesion) y rechaza si el usuario ya tiene 2FA confirmado
     * -- debe desactivarlo primero, nunca se sobreescribe un secreto activo
     * silenciosamente.
     */
    public function enroll(TwoFactorSensitiveActionRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'data' => null,
                'message' => 'Ya tiene doble factor activo. Desactívelo antes de volver a enrolar.',
                'errors' => ['two_factor' => ['2FA ya está activo.']],
            ], 422);
        }

        $secret = $this->twoFactor->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_totp_timestamp' => null,
        ])->save();

        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties(['action' => '2fa_enrollment_started'])
            ->log('Enrolamiento de doble factor iniciado');

        return response()->json([
            'data' => [
                'secret' => $secret,
                'otpauth_uri' => $this->twoFactor->qrCodeUri($user->email, $secret),
            ],
            'message' => 'Escanee el código QR con su aplicación autenticadora y confirme con el primer código.',
            'errors' => null,
        ]);
    }

    /**
     * Confirma el enrolamiento con el primer código TOTP real. Solo aquí se
     * activa 2FA (two_factor_confirmed_at) y se generan los códigos de
     * recuperación -- se revelan en texto plano una única vez, en esta
     * misma respuesta, nunca más.
     */
    public function confirm(TwoFactorConfirmRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->two_factor_secret === null || $user->hasTwoFactorEnabled()) {
            return response()->json([
                'data' => null,
                'message' => 'No hay un enrolamiento pendiente de confirmar.',
                'errors' => ['two_factor' => ['Inicie el enrolamiento primero.']],
            ], 422);
        }

        $timestamp = $this->twoFactor->verifyForEnrollment($user->two_factor_secret, $request->validated('code'));

        if ($timestamp === null) {
            return response()->json([
                'data' => null,
                'message' => 'Código incorrecto.',
                'errors' => ['code' => ['El código ingresado no es válido.']],
            ], 422);
        }

        $recovery = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_totp_timestamp' => $timestamp,
            'two_factor_recovery_codes' => $recovery['hashed'],
        ])->save();

        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties(['action' => '2fa_confirmed'])
            ->log('Doble factor confirmado y activado');

        return response()->json([
            'data' => [
                'recovery_codes' => $recovery['plain'],
            ],
            'message' => 'Doble factor activado. Guarde estos códigos de recuperación en un lugar seguro — no volverán a mostrarse.',
            'errors' => null,
        ]);
    }

    public function disable(TwoFactorSensitiveActionRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_totp_timestamp' => null,
        ])->save();

        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties(['action' => '2fa_disabled'])
            ->log('Doble factor desactivado');

        return response()->json([
            'data' => null,
            'message' => 'Doble factor desactivado.',
            'errors' => null,
        ]);
    }

    public function regenerateRecoveryCodes(TwoFactorSensitiveActionRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasTwoFactorEnabled()) {
            return response()->json([
                'data' => null,
                'message' => 'Debe tener doble factor activo para regenerar códigos de recuperación.',
                'errors' => ['two_factor' => ['2FA no está activo.']],
            ], 422);
        }

        $recovery = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $recovery['hashed']])->save();

        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties(['action' => '2fa_recovery_codes_regenerated'])
            ->log('Códigos de recuperación regenerados');

        return response()->json([
            'data' => ['recovery_codes' => $recovery['plain']],
            'message' => 'Códigos de recuperación regenerados. Los anteriores dejaron de ser válidos.',
            'errors' => null,
        ]);
    }

    /**
     * Resuelve el challenge de login. Requiere una sesion ya autenticada
     * por password (auth:sanctum) marcada como pendiente -- nunca se aplica
     * el middleware 2fa.verified aqui, es precisamente la ruta que lo
     * "desbloquea". Verificado el codigo (TOTP o recuperacion), recien ahi
     * se considera la sesion completamente autenticada.
     */
    public function verify(TwoFactorChallengeRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!TwoFactorSession::isPending($request) || TwoFactorSession::pendingUserId($request) !== $user->id) {
            return response()->json([
                'data' => null,
                'message' => 'No hay un desafío de doble factor pendiente.',
                'errors' => ['auth' => ['Inicie sesión nuevamente.']],
            ], 409);
        }

        if (TwoFactorSession::isExpired($request)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'data' => null,
                'message' => 'El desafío de doble factor expiró. Inicie sesión nuevamente.',
                'errors' => ['code' => ['Desafío expirado.']],
            ], 419);
        }

        $code = $request->validated('code');

        $passed = $this->twoFactor->verifyForUser($user, $code)
            || $this->twoFactor->verifyAndConsumeRecoveryCode($user, $code);

        if (!$passed) {
            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->withProperties(['action' => '2fa_challenge_failed'])
                ->log('Intento de verificación de doble factor fallido');

            return response()->json([
                'data' => null,
                'message' => 'Código incorrecto.',
                'errors' => ['code' => ['El código ingresado no es válido.']],
            ], 422);
        }

        TwoFactorSession::clear($request);
        $request->session()->regenerate();
        $user->update(['last_login_at' => now()]);

        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties(['action' => '2fa_challenge_passed'])
            ->log('Verificación de doble factor exitosa');

        return response()->json([
            'data' => new UserResource($user->load(['healthCenters'])),
            'message' => 'Inicio de sesión exitoso',
            'errors' => null,
        ]);
    }
}
