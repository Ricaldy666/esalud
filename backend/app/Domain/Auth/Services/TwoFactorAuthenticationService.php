<?php

namespace App\Domain\Auth\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Encapsula toda la logica TOTP (RFC 6238) y de codigos de recuperacion.
 * Fase Seguridad 2 (2026-08-31).
 *
 * Convenciones deliberadas:
 * - two_factor_secret: cifrado en reposo (cast 'encrypted' en User), nunca
 *   se expone en respuestas HTTP salvo en el paso de enrolamiento (una
 *   unica vez, antes de confirmarse).
 * - two_factor_recovery_codes: array JSON de hashes bcrypt (Hash::make por
 *   codigo, mismo patron que password) -- nunca se persiste texto plano,
 *   nunca se puede "recuperar" el codigo original, solo verificar/consumir.
 * - two_factor_last_totp_timestamp: proteccion anti-replay. Google2FA
 *   verifyKeyNewer() exige que el timestep aceptado sea estrictamente mayor
 *   al ultimo aceptado -- un codigo valido ya usado dentro de su propia
 *   ventana queda rechazado.
 * - Nada de esto se imprime nunca en logs ni en activity log (los
 *   controladores solo registran metadatos: accion, timestamp, IP).
 */
class TwoFactorAuthenticationService
{
    private const RECOVERY_CODES_COUNT = 8;

    private const ISSUER = 'ATHENEA';

    /** Ventana de tolerancia de +/-1 paso (30s cada uno = 90s totales). */
    private const VERIFY_WINDOW = 1;

    public function __construct(private readonly Google2FA $google2fa)
    {
    }

    public function generateSecretKey(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * URI otpauth:// para que el frontend renderice el QR (nunca se genera
     * una imagen ni se llama a un servicio externo desde el backend).
     */
    public function qrCodeUri(string $email, #[\SensitiveParameter] string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(self::ISSUER, $email, $secret);
    }

    /**
     * Verifica un codigo TOTP contra un secreto dado, sin proteccion
     * anti-replay -- uso exclusivo del paso de confirmacion de enrolamiento
     * (el secreto aun no esta persistido, no hay "ultimo timestep" previo
     * que rastrear). Devuelve el timestep aceptado (para persistirlo como
     * punto de partida de two_factor_last_totp_timestamp) o null si invalido.
     */
    public function verifyForEnrollment(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code): ?int
    {
        $timestamp = $this->google2fa->verifyKeyNewer($secret, $code, null, self::VERIFY_WINDOW);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * Verifica un codigo TOTP para un usuario ya enrolado, con proteccion
     * anti-replay activa (verifyKeyNewer exige timestep > ultimo aceptado).
     * Si es valido, persiste el nuevo timestep de inmediato (consumo
     * atomico -- un codigo valido nunca puede aceptarse dos veces).
     */
    public function verifyForUser(User $user, #[\SensitiveParameter] string $code): bool
    {
        if (!$user->hasTwoFactorEnabled() || $user->two_factor_secret === null) {
            return false;
        }

        $newTimestamp = $this->google2fa->verifyKeyNewer(
            $user->two_factor_secret,
            $code,
            $user->two_factor_last_totp_timestamp,
            self::VERIFY_WINDOW
        );

        if ($newTimestamp === false) {
            return false;
        }

        $user->forceFill(['two_factor_last_totp_timestamp' => $newTimestamp])->save();

        return true;
    }

    /**
     * Genera un lote nuevo de codigos de recuperacion, devuelve el texto
     * plano (para mostrarlo una unica vez) y deja los hashes listos para
     * persistir por separado (el llamador decide cuando guardar).
     *
     * @return array{plain: array<int, string>, hashed: array<int, string>}
     */
    public function generateRecoveryCodes(): array
    {
        $plain = [];
        $hashed = [];

        for ($i = 0; $i < self::RECOVERY_CODES_COUNT; $i++) {
            $code = strtolower(Str::random(4)).'-'.strtolower(Str::random(4));
            $plain[] = $code;
            $hashed[] = Hash::make($code);
        }

        return ['plain' => $plain, 'hashed' => $hashed];
    }

    /**
     * Verifica un codigo de recuperacion contra el usuario y, si coincide,
     * lo elimina de la lista (consumo de un solo uso) y persiste. Devuelve
     * true solo si hubo match y se consumio correctamente.
     */
    public function verifyAndConsumeRecoveryCode(User $user, #[\SensitiveParameter] string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes ?? [];

        if (empty($hashes)) {
            return false;
        }

        foreach ($hashes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$index]);
                $user->forceFill([
                    'two_factor_recovery_codes' => array_values($hashes),
                ])->save();

                return true;
            }
        }

        return false;
    }

    public function remainingRecoveryCodesCount(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }
}
