<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Fase Seguridad 1 (2026-08-31, prerequisito de 2FA).
 *
 * Cubre el rate limiting nuevo de /auth/login (throttle:login) y de
 * /api/v1/users store+update (throttle:sensitive-user-write), y confirma
 * que el flujo normal de login/logout/me/CSRF/sesion sigue funcionando sin
 * regresion.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        RateLimiter::clear('login');
    }

    private function makeUser(string $email = 'usuario@esalud.cl', string $password = 'claveValida123'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make($password),
        ]);
    }

    public function test_valid_login_still_works(): void
    {
        $this->makeUser('ok@esalud.cl', 'claveValida123');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ok@esalud.cl',
            'password' => 'claveValida123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.email', 'ok@esalud.cl');
    }

    public function test_invalid_login_returns_422_and_does_not_authenticate(): void
    {
        $this->makeUser('ok2@esalud.cl', 'claveValida123');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ok2@esalud.cl',
            'password' => 'clave-incorrecta',
        ]);

        $response->assertStatus(422);

        $me = $this->getJson('/api/v1/auth/me');
        $me->assertStatus(401);
    }

    public function test_repeated_failed_attempts_trigger_rate_limiting(): void
    {
        $this->makeUser('bruteforce@esalud.cl', 'claveValida123');

        $lastResponse = null;

        for ($i = 0; $i < 6; $i++) {
            $lastResponse = $this->postJson('/api/v1/auth/login', [
                'email' => 'bruteforce@esalud.cl',
                'password' => 'clave-incorrecta',
            ]);
        }

        // Las primeras 5 deben ser 422 (credenciales invalidas); la 6a debe
        // quedar bloqueada por el limitador (5/min).
        $lastResponse->assertStatus(429);
        $lastResponse->assertJsonStructure(['data', 'message', 'errors']);
    }

    public function test_correct_password_is_also_blocked_once_the_limit_is_exhausted(): void
    {
        $this->makeUser('victim@esalud.cl', 'claveValida123');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'victim@esalud.cl',
                'password' => 'clave-incorrecta',
            ]);
        }

        // El 6to intento, aunque use la password correcta, debe seguir
        // bloqueado -- el limite es por email+IP, no distingue si el
        // intento actual habria sido valido.
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'victim@esalud.cl',
            'password' => 'claveValida123',
        ]);

        $response->assertStatus(429);
    }

    public function test_rate_limit_is_scoped_per_email_so_other_users_are_not_blocked(): void
    {
        $this->makeUser('atacado@esalud.cl', 'claveValida123');
        $this->makeUser('otro-usuario@esalud.cl', 'claveValida123');

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'atacado@esalud.cl',
                'password' => 'clave-incorrecta',
            ]);
        }

        // Un usuario distinto, misma IP de test, no debe verse afectado.
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'otro-usuario@esalud.cl',
            'password' => 'claveValida123',
        ]);

        $response->assertOk();
    }

    public function test_full_login_me_logout_cycle_still_works_without_regression(): void
    {
        $this->makeUser('ciclo@esalud.cl', 'claveValida123');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'ciclo@esalud.cl',
            'password' => 'claveValida123',
        ]);
        $login->assertOk();

        $me = $this->getJson('/api/v1/auth/me');
        $me->assertOk();
        $me->assertJsonPath('data.email', 'ciclo@esalud.cl');

        $logout = $this->postJson('/api/v1/auth/logout');
        $logout->assertOk();

        // El test in-process de Laravel cachea el guard 'sanctum' resuelto
        // (Illuminate\Auth\RequestGuard cachea el usuario en memoria) entre
        // llamadas simuladas dentro del mismo metodo de test -- algo que
        // nunca ocurre en produccion real, donde cada request HTTP real
        // reconstruye el contenedor y resuelve el guard desde cero. Se fuerza
        // aqui la re-resolucion (mismo mecanismo que Laravel expone para
        // este escenario) para verificar el estado real post-logout, no un
        // artefacto de cacheo del arnes de pruebas.
        $this->app['auth']->forgetGuards();

        $meAfterLogout = $this->getJson('/api/v1/auth/me');
        $meAfterLogout->assertStatus(401);
    }

    public function test_users_store_and_update_endpoints_still_work_normally_under_the_new_throttle(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Superadmin');

        $created = $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Usuario De Prueba',
            'rut' => '9876543-2',
            'email' => 'nuevo@esalud.cl',
            'password' => 'claveNueva123',
            'password_confirmation' => 'claveNueva123',
            'role' => 'Analista',
        ]);
        $created->assertCreated();

        $userId = $created->json('data.id');

        $updated = $this->actingAs($admin)->putJson("/api/v1/users/{$userId}", [
            'name' => 'Usuario De Prueba Actualizado',
        ]);
        $updated->assertOk();
        $updated->assertJsonPath('data.name', 'Usuario De Prueba Actualizado');
    }

    public function test_users_store_endpoint_is_rate_limited_after_excessive_requests(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Superadmin');

        $lastResponse = null;

        // El cuerpo es deliberadamente invalido (falta de campos requeridos)
        // -- el throttle corre como middleware de ruta, antes de la
        // validacion del FormRequest, asi que igual cuenta cada intento.
        for ($i = 0; $i < 31; $i++) {
            $lastResponse = $this->actingAs($admin)->postJson('/api/v1/users', []);
        }

        $lastResponse->assertStatus(429);
    }
}
