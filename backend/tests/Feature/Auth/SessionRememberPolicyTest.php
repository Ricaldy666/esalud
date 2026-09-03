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
 * Politica de sesion ATHENEA (2026-09-03): "recordar sesion" fue eliminado
 * por completo. Cubre: AuthController::login() ya nunca emite la cookie
 * "recaller" de Laravel (para ningun usuario, con o sin 2FA); y el listener
 * de AuthServiceProvider::preventRememberedReauthentication() neutraliza
 * cualquier cookie "recaller" que ya existiera de antes de esta politica --
 * el escenario real que motivo el cambio (ver CLAUDE.md).
 */
class SessionRememberPolicyTest extends TestCase
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

    private function findCookie(\Illuminate\Testing\TestResponse $response, string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        return collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $name);
    }

    /**
     * Simula una cookie "recaller" que ya existia ANTES de esta politica
     * (creada por Auth::attempt(..., true), como hacia AuthController::login()
     * hasta este cambio) -- deliberadamente sin pasar por Auth::attempt()/
     * login() aqui, porque ahora esos SI disparan el listener
     * AuthServiceProvider::preventRememberedReauthentication() (evento Login
     * con remember=true), que la invalidaria de inmediato -- exactamente lo
     * que se quiere probar en el siguiente paso, no en la preparacion del
     * escenario. En su lugar se reproduce a mano el mismo formato que usa
     * Illuminate\Auth\SessionGuard::queueRecallerCookie()/hashPasswordForCookie(),
     * y se deja que una request real (a una ruta publica) pase la cookie
     * encolada por el pipeline completo (AddQueuedCookiesToResponse +
     * EncryptCookies) para obtener el mismo valor cifrado que un navegador
     * real habria guardado.
     *
     * @return array{0: string, 1: string} nombre y valor (ya cifrado) de la
     *                                      cookie recaller
     */
    private function issueLegacyRememberCookie(User $user): array
    {
        $token = Str::random(60);
        $user->forceFill(['remember_token' => $token])->save();

        $cookieName = Auth::guard('web')->getRecallerName();
        $recallerValue = $user->getAuthIdentifier() . '|' . $token . '|'
            . hash_hmac('sha256', $user->getAuthPassword(), 'base-key-for-password-hash-mac');

        Cookie::queue(Cookie::make($cookieName, $recallerValue, 60 * 24 * 365));

        // Cualquier ruta publica sirve solo para que el pipeline real
        // (StartSession/AddQueuedCookiesToResponse/EncryptCookies) procese
        // la cookie ya encolada -- no crea sesion ni dispara login alguno.
        $response = $this->getJson('/api/v1/health');
        $response->assertOk();

        $cookie = $this->findCookie($response, $cookieName);
        $this->assertNotNull($cookie, 'La cookie recaller no se emitio en el escenario legacy simulado.');

        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();

        return [$cookieName, $cookie->getValue()];
    }

    // --- Login normal (sin 2FA): nunca emite cookie recaller ---

    public function test_login_without_two_factor_works_and_never_issues_remember_cookie(): void
    {
        // El factory ya asigna un remember_token aleatorio por defecto (no
        // relacionado con login) -- lo relevante es que un login normal no
        // lo toque en absoluto, no que sea null.
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);
        $originalRememberToken = $user->remember_token;

        $response = $this->login($user->email, 'claveValida123');

        $response->assertOk();
        $response->assertJsonPath('data.email', $user->email);

        $recallerName = Auth::guard('web')->getRecallerName();
        $this->assertNull(
            $this->findCookie($response, $recallerName),
            'No debe emitirse ninguna cookie recaller -- "recordar sesion" fue eliminado.'
        );
        $this->assertSame(
            $originalRememberToken,
            $user->fresh()->remember_token,
            'Un login normal no debe crear ni ciclar remember_token bajo la politica actual.'
        );
    }

    // --- Login con 2FA: exige TOTP, tampoco emite cookie recaller ---

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

    public function test_login_with_two_factor_requires_totp_and_never_issues_remember_cookie(): void
    {
        [$user, $secret] = $this->createUserWithTwoFactor();

        $response = $this->login($user->email, 'claveValida123');
        $response->assertOk();
        $response->assertJsonPath('data.requires_2fa', true);

        $recallerName = Auth::guard('web')->getRecallerName();
        $this->assertNull($this->findCookie($response, $recallerName));

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/users')->assertStatus(401);

        $code = (new Google2FA())->getCurrentOtp($secret);
        $verify = $this->postJson('/api/v1/auth/2fa/verify', ['code' => $code]);
        $verify->assertOk();

        $this->assertNull(
            $this->findCookie($verify, $recallerName),
            'La verificacion 2FA tampoco debe emitir cookie recaller.'
        );
    }

    // --- Logout invalida acceso ---

    public function test_logout_invalidates_access(): void
    {
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);
        $this->login($user->email, 'claveValida123')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->getJson('/api/v1/users')->assertStatus(401);
    }

    // --- Expiracion/perdida de sesion exige login nuevo ---

    public function test_invalidated_session_requires_new_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);
        $this->login($user->email, 'claveValida123')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertOk();

        // Simula la sesion cayendose (vencimiento/limpieza) sin pasar por
        // logout explicito.
        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->getJson('/api/v1/users')->assertStatus(401);
    }

    // --- /auth/me y acceso directo, con y sin sesion ---

    public function test_me_with_valid_session_returns_the_user(): void
    {
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);
        $this->login($user->email, 'claveValida123')->assertOk();

        $this->app['auth']->forgetGuards();
        $me = $this->getJson('/api/v1/auth/me');

        $me->assertOk();
        $me->assertJsonPath('data.email', $user->email);
    }

    public function test_me_without_session_returns_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_protected_route_without_session_is_rejected(): void
    {
        $this->getJson('/api/v1/users')->assertStatus(401);
    }

    // --- El escenario que motivo este cambio: una cookie recaller antigua ---

    public function test_old_remember_cookie_does_not_bypass_two_factor(): void
    {
        [$user] = $this->createUserWithTwoFactor();

        [$cookieName, $cookieValue] = $this->issueLegacyRememberCookie($user);

        $response = $this->withCookie($cookieName, $cookieValue)->getJson('/api/v1/auth/me');
        $response->assertStatus(401);

        $this->withCookie($cookieName, $cookieValue)
            ->getJson('/api/v1/users')
            ->assertStatus(401);
    }

    public function test_old_remember_cookie_does_not_grant_access_for_user_without_two_factor_either(): void
    {
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);

        [$cookieName, $cookieValue] = $this->issueLegacyRememberCookie($user);

        $response = $this->withCookie($cookieName, $cookieValue)->getJson('/api/v1/auth/me');
        $response->assertStatus(401);
    }
}
