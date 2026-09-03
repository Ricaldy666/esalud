<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * GET /auth/session (2026-09-03): endpoint publico de estado de sesion --
 * a diferencia de /auth/me (protegida, 401 sin sesion), esta ruta siempre
 * responde 200 con authenticated/requires_2fa explicitos. Motivo: eliminar
 * el 401 "esperado" que useAuthInit generaba en cada carga de /login sin
 * sesion (ruido en DevTools), sin cambiar el contrato de /auth/me ni
 * debilitar el gate de 2FA. Ver AuthController::resolveSessionState().
 */
class SessionStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        RateLimiter::clear('login');
    }

    private function login(string $email, string $password): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function createUserWithTwoFactor(string $password = 'claveValida123'): array
    {
        $user = User::factory()->create(['password' => Hash::make($password)]);

        $enroll = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll', [
            'current_password' => $password,
        ])->assertOk();

        $secret = $enroll->json('data.secret');
        $code = (new Google2FA())->getCurrentOtp($secret);

        $this->actingAs($user)->postJson('/api/v1/auth/2fa/confirm', ['code' => $code])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return [$user->refresh(), $secret];
    }

    private function findCookie(\Illuminate\Testing\TestResponse $response, string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        return collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $name);
    }

    /**
     * Misma tecnica que SessionRememberPolicyTest::issueLegacyRememberCookie()
     * -- reproduce a mano una cookie "recaller" como la que habria dejado un
     * navegador antes de la politica de "sin remember-me", sin pasar por
     * Auth::attempt()/login() (que ahora disparan el listener que la
     * invalidaria de inmediato, justo lo que se quiere probar despues).
     *
     * @return array{0: string, 1: string}
     */
    private function issueLegacyRememberCookie(User $user): array
    {
        $token = Str::random(60);
        $user->forceFill(['remember_token' => $token])->save();

        $cookieName = Auth::guard('web')->getRecallerName();
        $recallerValue = $user->getAuthIdentifier() . '|' . $token . '|'
            . hash_hmac('sha256', $user->getAuthPassword(), 'base-key-for-password-hash-mac');

        Cookie::queue(Cookie::make($cookieName, $recallerValue, 60 * 24 * 365));

        $response = $this->getJson('/api/v1/health');
        $response->assertOk();

        $cookie = $this->findCookie($response, $cookieName);
        $this->assertNotNull($cookie, 'La cookie recaller no se emitio en el escenario legacy simulado.');

        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();

        return [$cookieName, $cookie->getValue()];
    }

    public function test_session_without_login_returns_200_unauthenticated(): void
    {
        $response = $this->getJson('/api/v1/auth/session');

        $response->assertOk();
        $response->assertJsonPath('data.authenticated', false);
        $response->assertJsonPath('data.requires_2fa', false);
        $response->assertJsonPath('data.user', null);
    }

    public function test_session_with_valid_login_returns_authenticated_user(): void
    {
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);
        $this->login($user->email, 'claveValida123')->assertOk();

        $this->app['auth']->forgetGuards();
        $response = $this->getJson('/api/v1/auth/session');

        $response->assertOk();
        $response->assertJsonPath('data.authenticated', true);
        $response->assertJsonPath('data.requires_2fa', false);
        $response->assertJsonPath('data.user.email', $user->email);
    }

    public function test_session_with_pending_two_factor_reports_requires_2fa_without_user(): void
    {
        [$user] = $this->createUserWithTwoFactor();
        $this->login($user->email, 'claveValida123')->assertOk();

        $this->app['auth']->forgetGuards();
        $response = $this->getJson('/api/v1/auth/session');

        $response->assertOk();
        $response->assertJsonPath('data.authenticated', false);
        $response->assertJsonPath('data.requires_2fa', true);
        $response->assertJsonPath('data.user', null);
    }

    public function test_session_with_expired_two_factor_challenge_reports_unauthenticated(): void
    {
        [$user] = $this->createUserWithTwoFactor();
        $this->login($user->email, 'claveValida123')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->travel(6)->minutes();

        $response = $this->getJson('/api/v1/auth/session');

        $response->assertOk();
        $response->assertJsonPath('data.authenticated', false);
        $response->assertJsonPath('data.requires_2fa', false);
        $response->assertJsonPath('data.user', null);

        // El challenge vencido invalida lo que quedaba de la sesion -- una
        // llamada posterior a /auth/me (protegida) debe rechazar tambien.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_session_with_only_an_old_remember_cookie_reports_unauthenticated(): void
    {
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);

        [$cookieName, $cookieValue] = $this->issueLegacyRememberCookie($user);

        $response = $this->withCookie($cookieName, $cookieValue)->getJson('/api/v1/auth/session');

        $response->assertOk();
        $response->assertJsonPath('data.authenticated', false);
        $response->assertJsonPath('data.requires_2fa', false);
        $response->assertJsonPath('data.user', null);
    }

    public function test_session_with_only_an_old_remember_cookie_does_not_bypass_two_factor(): void
    {
        [$user] = $this->createUserWithTwoFactor();

        [$cookieName, $cookieValue] = $this->issueLegacyRememberCookie($user);

        $response = $this->withCookie($cookieName, $cookieValue)->getJson('/api/v1/auth/session');

        $response->assertOk();
        $response->assertJsonPath('data.authenticated', false);
        $response->assertJsonPath('data.requires_2fa', false);
        $response->assertJsonPath('data.user', null);
    }

    // --- Regresion explicita: /auth/me mantiene su contrato 401 ---

    public function test_me_without_session_still_returns_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}
