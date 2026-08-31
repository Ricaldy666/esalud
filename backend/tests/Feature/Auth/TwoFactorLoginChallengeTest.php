<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Fase Seguridad 2 (2026-08-31): flujo completo de login con 2FA -- el
 * challenge en si, anti-bypass, expiracion, throttling, recovery codes,
 * y no-regresion de logout/me/roles para cuentas sin 2FA.
 */
class TwoFactorLoginChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        RateLimiter::clear('login');
    }

    /**
     * @return array{0: User, 1: string, 2: array<int, string>} usuario, secreto TOTP, codigos de recuperacion en texto plano
     */
    private function createUserWithTwoFactor(string $password = 'claveValida123'): array
    {
        $user = User::factory()->create(['password' => Hash::make($password)]);

        $enroll = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll', [
            'current_password' => $password,
        ])->assertOk();

        $secret = $enroll->json('data.secret');
        $code = (new Google2FA())->getCurrentOtp($secret);

        $confirm = $this->actingAs($user)->postJson('/api/v1/auth/2fa/confirm', ['code' => $code])->assertOk();

        // Illuminate\Auth\Middleware\Authenticate::authenticate() llama
        // Auth::shouldUse('sanctum') cada vez que una ruta auth:sanctum se
        // autentica con exito -- efecto secundario real de Laravel que deja
        // 'sanctum' como guard por defecto para el resto del contenedor
        // (nunca ocurre en produccion real: cada request HTTP autentico es
        // un proceso nuevo, config('auth.defaults.guard') vuelve a 'web'
        // desde cero). Se revierte aqui explicitamente para que el flujo
        // real de /auth/login (Auth::attempt(), sin nombre de guard) siga
        // resolviendo el guard 'web' -- el mismo que resolveria en
        // produccion -- y no el guard 'sanctum' (RequestGuard, que ni
        // siquiera implementa attempt()).
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return [$user->refresh(), $secret, $confirm->json('data.recovery_codes')];
    }

    private function login(string $email, string $password): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    // --- 1/2: no-regresion para usuarios sin 2FA ---

    public function test_user_without_two_factor_still_logs_in_normally(): void
    {
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);

        $response = $this->login($user->email, 'claveValida123');

        $response->assertOk();
        $response->assertJsonPath('data.email', $user->email);
        $response->assertJsonMissingPath('data.requires_2fa');
    }

    public function test_incorrect_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);

        $response = $this->login($user->email, 'clave-incorrecta');

        $response->assertStatus(422);
    }

    // --- 3: password sola no autentica una cuenta con 2FA ---

    public function test_user_with_two_factor_does_not_get_full_authentication_from_password_alone(): void
    {
        [$user] = $this->createUserWithTwoFactor();

        $response = $this->login($user->email, 'claveValida123');

        $response->assertOk();
        $response->assertJsonPath('data.requires_2fa', true);

        // No debe existir ya un usuario "completamente" autenticado.
        $this->app['auth']->forgetGuards();
        $me = $this->getJson('/api/v1/auth/me');
        $me->assertOk();
        $me->assertJsonPath('data.requires_2fa', true);
        $me->assertJsonMissingPath('data.email');
    }

    public function test_bypass_attempt_hitting_protected_routes_directly_after_password_step_fails(): void
    {
        [$user] = $this->createUserWithTwoFactor();
        $this->login($user->email, 'claveValida123')->assertOk();

        $this->app['auth']->forgetGuards();

        // Ninguna ruta protegida debe ser alcanzable mientras el challenge
        // esta pendiente -- probado contra varios endpoints distintos.
        $this->getJson('/api/v1/users')->assertStatus(401);
        $this->getJson('/api/v1/health-centers')->assertStatus(401);
        $this->getJson('/api/v1/roles')->assertStatus(401);
        $this->getJson('/api/v1/activity-log')->assertStatus(401);
        $this->postJson('/api/v1/auth/2fa/enroll', ['current_password' => 'claveValida123'])->assertStatus(401);
        $this->postJson('/api/v1/auth/2fa/disable', ['current_password' => 'claveValida123'])->assertStatus(401);
    }

    // --- 4/5: TOTP correcto / incorrecto ---

    public function test_correct_totp_code_completes_authentication(): void
    {
        [$user, $secret] = $this->createUserWithTwoFactor();
        $this->login($user->email, 'claveValida123')->assertOk();
        $this->app['auth']->forgetGuards();

        $code = (new Google2FA())->getCurrentOtp($secret);
        $response = $this->postJson('/api/v1/auth/2fa/verify', ['code' => $code]);

        $response->assertOk();
        $response->assertJsonPath('data.email', $user->email);

        $me = $this->getJson('/api/v1/auth/me');
        $me->assertOk();
        $me->assertJsonPath('data.email', $user->email);
    }

    public function test_incorrect_totp_code_is_rejected_and_stays_pending(): void
    {
        [$user] = $this->createUserWithTwoFactor();
        $this->login($user->email, 'claveValida123')->assertOk();
        $this->app['auth']->forgetGuards();

        $response = $this->postJson('/api/v1/auth/2fa/verify', ['code' => '000000']);
        $response->assertStatus(422);

        $me = $this->getJson('/api/v1/auth/me');
        $me->assertJsonPath('data.requires_2fa', true);
    }

    // --- 6: challenge expirado ---

    public function test_expired_challenge_forces_login_again(): void
    {
        [$user, $secret] = $this->createUserWithTwoFactor();
        $this->login($user->email, 'claveValida123')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->travel(6)->minutes();

        $code = (new Google2FA())->getCurrentOtp($secret);
        $response = $this->postJson('/api/v1/auth/2fa/verify', ['code' => $code]);

        $response->assertStatus(419);

        $this->app['auth']->forgetGuards();
        $me = $this->getJson('/api/v1/auth/me');
        $me->assertStatus(401);
    }

    // --- 7: throttling del challenge ---

    public function test_repeated_failed_challenge_attempts_are_throttled(): void
    {
        [$user] = $this->createUserWithTwoFactor();
        $this->login($user->email, 'claveValida123')->assertOk();
        $this->app['auth']->forgetGuards();

        $last = null;
        for ($i = 0; $i < 6; $i++) {
            $last = $this->postJson('/api/v1/auth/2fa/verify', ['code' => '000000']);
        }

        $last->assertStatus(429);
    }

    // --- 8/9: recovery codes ---

    public function test_valid_recovery_code_completes_authentication(): void
    {
        [$user, , $recoveryCodes] = $this->createUserWithTwoFactor();
        $this->login($user->email, 'claveValida123')->assertOk();
        $this->app['auth']->forgetGuards();

        $response = $this->postJson('/api/v1/auth/2fa/verify', ['code' => $recoveryCodes[0]]);

        $response->assertOk();
        $response->assertJsonPath('data.email', $user->email);
    }

    public function test_reused_recovery_code_is_rejected(): void
    {
        [$user, , $recoveryCodes] = $this->createUserWithTwoFactor();

        $this->login($user->email, 'claveValida123')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/2fa/verify', ['code' => $recoveryCodes[0]])->assertOk();

        // Segundo login, mismo codigo ya usado.
        $this->postJson('/api/v1/auth/logout')->assertOk();
        // Revertir el efecto secundario de Authenticate::authenticate()
        // (ver comentario en createUserWithTwoFactor()) antes de volver a
        // llamar Auth::attempt() via /auth/login.
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        $this->login($user->email, 'claveValida123')->assertOk();
        $this->app['auth']->forgetGuards();
        $response = $this->postJson('/api/v1/auth/2fa/verify', ['code' => $recoveryCodes[0]]);

        $response->assertStatus(422);
    }

    // --- 14/15/16: logout, me, códigos HTTP ---

    public function test_logout_clears_pending_challenge_state(): void
    {
        [$user] = $this->createUserWithTwoFactor();
        $this->login($user->email, 'claveValida123')->assertOk();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $me = $this->getJson('/api/v1/auth/me');
        $me->assertStatus(401);
    }

    public function test_full_two_factor_cycle_then_logout_then_me_is_401(): void
    {
        [$user, $secret] = $this->createUserWithTwoFactor();
        $this->login($user->email, 'claveValida123')->assertOk();
        $this->app['auth']->forgetGuards();

        $code = (new Google2FA())->getCurrentOtp($secret);
        $this->postJson('/api/v1/auth/2fa/verify', ['code' => $code])->assertOk();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    // --- 17: roles/permisos intactos tras completar 2FA ---

    public function test_roles_and_permissions_remain_intact_after_completing_two_factor(): void
    {
        [$user, $secret] = $this->createUserWithTwoFactor();
        $user->assignRole('Superadmin');

        $this->login($user->email, 'claveValida123')->assertOk();
        $this->app['auth']->forgetGuards();

        // Mientras esta pendiente, el rol no importa -- sigue bloqueado.
        $this->getJson('/api/v1/users')->assertStatus(401);

        $code = (new Google2FA())->getCurrentOtp($secret);
        $this->postJson('/api/v1/auth/2fa/verify', ['code' => $code])->assertOk();

        // Completado el challenge, el rol Superadmin ya asignado sigue
        // funcionando exactamente igual (UserPolicy sin cambios).
        $this->getJson('/api/v1/users')->assertOk();
    }

    public function test_incomplete_enrollment_never_triggers_a_challenge_on_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);

        // Enrola pero NUNCA confirma.
        $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll', [
            'current_password' => 'claveValida123',
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        $response = $this->login($user->email, 'claveValida123');

        $response->assertOk();
        $response->assertJsonMissingPath('data.requires_2fa');
        $response->assertJsonPath('data.email', $user->email);
    }
}
