<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Fase Seguridad 2 (2026-08-31): enrolamiento, confirmacion, desactivacion
 * y regeneracion de codigos de recuperacion.
 */
class TwoFactorEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $password = 'claveActual123'): User
    {
        return User::factory()->create(['password' => Hash::make($password)]);
    }

    public function test_enroll_requires_current_password(): void
    {
        $user = $this->user('claveActual123');

        $response = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll', [
            'current_password' => 'contraseña-incorrecta',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('current_password');
        $user->refresh();
        $this->assertNull($user->two_factor_secret);
    }

    public function test_enroll_generates_secret_and_qr_uri_but_does_not_enable_2fa_yet(): void
    {
        $user = $this->user('claveActual123');

        $response = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll', [
            'current_password' => 'claveActual123',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['secret', 'otpauth_uri']]);

        $secret = $response->json('data.secret');
        $this->assertNotEmpty($secret);
        $this->assertStringStartsWith('otpauth://totp/', $response->json('data.otpauth_uri'));
        $this->assertStringContainsString($secret, $response->json('data.otpauth_uri'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        // Enrolamiento incompleto: NO debe considerarse 2FA habilitado.
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_secret_is_encrypted_at_rest(): void
    {
        $user = $this->user('claveActual123');

        $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll', [
            'current_password' => 'claveActual123',
        ])->assertOk();

        $raw = \DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $decrypted = $user->refresh()->two_factor_secret;

        // El valor crudo en BD no debe ser el secreto en texto plano, ni
        // siquiera un substring de el -- debe ser la carga cifrada de
        // Laravel (Crypt::encryptString), reversible solo con APP_KEY.
        $this->assertNotEquals($decrypted, $raw);
        $this->assertStringNotContainsString($decrypted, $raw);
    }

    public function test_confirm_with_wrong_code_does_not_enable_2fa(): void
    {
        $user = $this->user('claveActual123');

        $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll', [
            'current_password' => 'claveActual123',
        ])->assertOk();

        $response = $this->actingAs($user)->postJson('/api/v1/auth/2fa/confirm', ['code' => '000000']);

        $response->assertStatus(422);
        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
    }

    public function test_confirm_with_correct_code_enables_2fa_and_reveals_recovery_codes_once(): void
    {
        $user = $this->user('claveActual123');

        $enroll = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll', [
            'current_password' => 'claveActual123',
        ])->assertOk();

        $secret = $enroll->json('data.secret');
        $code = (new Google2FA())->getCurrentOtp($secret);

        $response = $this->actingAs($user)->postJson('/api/v1/auth/2fa/confirm', ['code' => $code]);

        $response->assertOk();
        $codes = $response->json('data.recovery_codes');
        $this->assertCount(8, $codes);
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]{4}-[a-z0-9]{4}$/', $c);
        }

        $user->refresh();
        $this->assertTrue($user->hasTwoFactorEnabled());
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertCount(8, $user->two_factor_recovery_codes);
        // Persistidos como hash, nunca el texto plano.
        $this->assertNotContains($codes[0], $user->two_factor_recovery_codes);
    }

    public function test_cannot_re_enroll_while_2fa_already_confirmed(): void
    {
        $user = $this->enrollAndConfirm();

        $response = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll', [
            'current_password' => 'claveActual123',
        ]);

        $response->assertStatus(422);
    }

    public function test_disable_requires_current_password(): void
    {
        $user = $this->enrollAndConfirm();

        $response = $this->actingAs($user)->postJson('/api/v1/auth/2fa/disable', [
            'current_password' => 'incorrecta',
        ]);

        $response->assertStatus(422);
        $user->refresh();
        $this->assertTrue($user->hasTwoFactorEnabled());
    }

    public function test_disable_clears_all_two_factor_state(): void
    {
        $user = $this->enrollAndConfirm();

        $response = $this->actingAs($user)->postJson('/api/v1/auth/2fa/disable', [
            'current_password' => 'claveActual123',
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_last_totp_timestamp);
    }

    public function test_regenerate_recovery_codes_requires_2fa_active(): void
    {
        $user = $this->user('claveActual123');

        $response = $this->actingAs($user)->postJson('/api/v1/auth/2fa/recovery-codes/regenerate', [
            'current_password' => 'claveActual123',
        ]);

        $response->assertStatus(422);
    }

    public function test_regenerate_recovery_codes_invalidates_old_ones(): void
    {
        [$user, $originalCodes] = $this->enrollConfirmAndGetCodes();

        $response = $this->actingAs($user)->postJson('/api/v1/auth/2fa/recovery-codes/regenerate', [
            'current_password' => 'claveActual123',
        ]);

        $response->assertOk();
        $newCodes = $response->json('data.recovery_codes');

        $this->assertNotEquals($originalCodes, $newCodes);

        $user->refresh();
        foreach ($originalCodes as $old) {
            $this->assertFalse(collect($user->two_factor_recovery_codes)->contains(fn ($h) => Hash::check($old, $h)));
        }
        foreach ($newCodes as $new) {
            $this->assertTrue(collect($user->two_factor_recovery_codes)->contains(fn ($h) => Hash::check($new, $h)));
        }
    }

    public function test_enrollment_and_confirmation_never_log_secret_or_code_values(): void
    {
        $user = $this->enrollAndConfirm();

        $activities = Activity::query()->where('subject_id', $user->id)->where('subject_type', User::class)->get();

        $this->assertGreaterThanOrEqual(2, $activities->count());

        foreach ($activities as $activity) {
            $payload = json_encode($activity->properties);
            $this->assertStringNotContainsString($user->two_factor_secret ?? '__nunca__', $payload);
        }
    }

    /**
     * Helper: enrola y confirma un usuario nuevo con password conocida.
     */
    private function enrollAndConfirm(): User
    {
        return $this->enrollConfirmAndGetCodes()[0];
    }

    /**
     * @return array{0: User, 1: array<int, string>}
     */
    private function enrollConfirmAndGetCodes(): array
    {
        $user = $this->user('claveActual123');

        $enroll = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll', [
            'current_password' => 'claveActual123',
        ])->assertOk();

        $secret = $enroll->json('data.secret');
        $code = (new Google2FA())->getCurrentOtp($secret);

        $confirm = $this->actingAs($user)->postJson('/api/v1/auth/2fa/confirm', ['code' => $code])->assertOk();

        return [$user->refresh(), $confirm->json('data.recovery_codes')];
    }
}
