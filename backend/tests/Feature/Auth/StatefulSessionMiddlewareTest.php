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
 * Corrige la duplicacion de middleware de sesion en bootstrap/app.php
 * (2026-09-04): EnsureFrontendRequestsAreStateful ya aplica EncryptCookies/
 * AddQueuedCookiesToResponse/StartSession/CSRF internamente para requests
 * stateful -- volver a agregarlos en $middleware->api(prepend: [...]) hacia
 * que el SEGUNDO EncryptCookies intentara desencriptar una cookie que el
 * primer paso ya habia desencriptado/mutado en el propio $request, fallara,
 * la pusiera en null, y el segundo StartSession -- sin ID de sesion que
 * leer -- generara una sesion nueva vacia que pisaba la sesion real ya
 * cargada. Sintoma exacto en produccion: /auth/session respondia
 * authenticated:false en cada request posterior al login, aunque el
 * navegador enviara la cookie de sesion correctamente.
 *
 * A diferencia de otros tests de este directorio (que dependen de que el
 * contenedor de la app persista entre llamadas dentro de un mismo metodo de
 * test), estos capturan la cookie de sesion real de la respuesta y la
 * reenvian explicitamente via withCookie() en una request nueva -- la
 * misma simulacion que hace un navegador real en un F5, y la unica forma
 * de haber detectado este bug especifico en un test.
 */
class StatefulSessionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        RateLimiter::clear('login');
    }

    private function sessionCookieName(): string
    {
        return config('session.cookie');
    }

    private function findCookie(\Illuminate\Testing\TestResponse $response, string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        return collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $name);
    }

    /**
     * Simula "otra request llega solo con la cookie": resetea el guard de
     * auth cacheado (mismo patron usado en el resto de la suite) para que
     * la autenticacion se resuelva de nuevo a partir de lo que traiga la
     * cookie explicita de esta llamada, en vez de reusar el usuario ya
     * resuelto en el contenedor de este test.
     *
     * Deliberadamente NO llama a session()->flush(): en `esalud_testing`
     * SESSION_DRIVER=array (memoria de proceso, sin tabla `sessions` --
     * production usa `database`), y flush() borraria esa memoria antes de
     * que la "request nueva" pueda releerla por ID desde la cookie,
     * invalidando la simulacion. forgetGuards() alcanza para forzar una
     * resolucion de autenticacion nueva; el propio StartSession vuelve a
     * llamar $session->start() (recarga desde el handler por ID) en cada
     * llamada sin importar el estado previo -- lo que expone el bug real
     * (el segundo EncryptCookies/StartSession corrompiendo el ID leido de
     * la cookie) sigue ocurriendo igual, ya que actua sobre el objeto
     * Request de CADA llamada, no sobre el Store cacheado.
     */
    private function resetInProcessStateKeepingOnlyTheCookie(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_login_sets_a_real_session_cookie(): void
    {
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);

        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'claveValida123']);

        $response->assertOk();
        $response->assertJsonPath('data.email', $user->email);

        $cookie = $this->findCookie($response, $this->sessionCookieName());
        $this->assertNotNull($cookie, 'El login debe emitir la cookie de sesion ('.$this->sessionCookieName().').');
        $this->assertNotEmpty($cookie->getValue());
    }

    public function test_authenticated_session_persists_across_a_later_unrelated_request_via_the_real_cookie(): void
    {
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);

        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'claveValida123']);
        $login->assertOk();
        $cookieName = $this->sessionCookieName();
        $cookieValue = $this->findCookie($login, $cookieName)->getValue();

        $this->resetInProcessStateKeepingOnlyTheCookie();

        // Request completamente nueva -- sin continuidad de contenedor via
        // guards/sesion (ya limpiada arriba), solo la cookie real capturada.
        $response = $this->withCookie($cookieName, $cookieValue)->getJson('/api/v1/auth/session');

        $response->assertOk();
        $response->assertJsonPath('data.authenticated', true);
        $response->assertJsonPath('data.user.email', $user->email);
    }

    public function test_auth_me_returns_the_authenticated_user_via_the_real_cookie(): void
    {
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);

        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'claveValida123']);
        $cookieName = $this->sessionCookieName();
        $cookieValue = $this->findCookie($login, $cookieName)->getValue();

        $this->resetInProcessStateKeepingOnlyTheCookie();

        $response = $this->withCookie($cookieName, $cookieValue)->getJson('/api/v1/auth/me');

        $response->assertOk();
        $response->assertJsonPath('data.email', $user->email);
    }

    public function test_logout_invalidates_the_session_for_the_same_cookie(): void
    {
        $user = User::factory()->create(['password' => Hash::make('claveValida123')]);

        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'claveValida123']);
        $cookieName = $this->sessionCookieName();
        $cookieValue = $this->findCookie($login, $cookieName)->getValue();

        $this->resetInProcessStateKeepingOnlyTheCookie();
        $this->withCookie($cookieName, $cookieValue)->postJson('/api/v1/auth/logout')->assertOk();

        $this->resetInProcessStateKeepingOnlyTheCookie();
        $response = $this->withCookie($cookieName, $cookieValue)->getJson('/api/v1/auth/session');

        $response->assertOk();
        $response->assertJsonPath('data.authenticated', false);

        $this->resetInProcessStateKeepingOnlyTheCookie();
        $this->withCookie($cookieName, $cookieValue)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_two_factor_challenge_session_persists_across_requests_via_the_real_cookie(): void
    {
        $password = 'claveValida123';
        $user = User::factory()->create(['password' => Hash::make($password)]);

        $enroll = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll', ['current_password' => $password])->assertOk();
        $secret = $enroll->json('data.secret');
        $code = (new Google2FA())->getCurrentOtp($secret);
        $this->actingAs($user)->postJson('/api/v1/auth/2fa/confirm', ['code' => $code])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        // Password correcta -> sesion pendiente de 2FA. Se captura la
        // cookie de ESE momento (antes de completar el challenge).
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password]);
        $login->assertOk();
        $login->assertJsonPath('data.requires_2fa', true);
        $cookieName = $this->sessionCookieName();
        $cookieValue = $this->findCookie($login, $cookieName)->getValue();

        $this->resetInProcessStateKeepingOnlyTheCookie();

        // Con SOLO esa cookie (sin continuidad de proceso), el challenge
        // pendiente debe seguir vivo -- ni autenticado ni "sin sesion".
        $sessionCheck = $this->withCookie($cookieName, $cookieValue)->getJson('/api/v1/auth/session');
        $sessionCheck->assertOk();
        $sessionCheck->assertJsonPath('data.authenticated', false);
        $sessionCheck->assertJsonPath('data.requires_2fa', true);

        $this->resetInProcessStateKeepingOnlyTheCookie();
        $totp = (new Google2FA())->getCurrentOtp($secret);
        $verify = $this->withCookie($cookieName, $cookieValue)->postJson('/api/v1/auth/2fa/verify', ['code' => $totp]);
        $verify->assertOk();
        $verify->assertJsonPath('data.email', $user->email);

        // El verify puede rotar la cookie (regenerate) -- se usa la mas
        // reciente para la comprobacion final.
        $latestCookie = $this->findCookie($verify, $cookieName)?->getValue() ?? $cookieValue;

        $this->resetInProcessStateKeepingOnlyTheCookie();
        $final = $this->withCookie($cookieName, $latestCookie)->getJson('/api/v1/auth/session');
        $final->assertOk();
        $final->assertJsonPath('data.authenticated', true);
        $final->assertJsonPath('data.user.email', $user->email);
    }
}
