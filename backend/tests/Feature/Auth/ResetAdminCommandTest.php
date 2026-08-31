<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Fase Seguridad 1 (2026-08-31, prerequisito de 2FA).
 *
 * Cubre el endurecimiento de `auth:reset-admin`: ya no fuerza una password
 * conocida, ya no asigna Superadmin sin confirmacion explicita, ya no toca
 * los roles de un usuario existente, solo corre en local/testing, y deja
 * auditoria.
 */
class ResetAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_refuses_to_run_outside_local_or_testing_environment(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('auth:reset-admin')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseMissing('users', ['email' => 'admin@esalud.cl']);
    }

    public function test_creating_new_user_requires_explicit_role_option(): void
    {
        $this->artisan('auth:reset-admin')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseMissing('users', ['email' => 'admin@esalud.cl']);
    }

    public function test_creating_new_user_with_role_option_assigns_only_that_role_never_superadmin_by_default(): void
    {
        $this->artisan('auth:reset-admin', ['--role' => 'Analista'])
            ->expectsConfirmation('¿Confirmas que deseas continuar? Esto sobrescribirá la contraseña de este usuario.', 'yes')
            ->expectsQuestion('Nueva contraseña (oculta, mínimo 8 caracteres)', 'unaClave123')
            ->expectsQuestion('Confirmar contraseña (oculta)', 'unaClave123')
            ->assertExitCode(Command::SUCCESS);

        $user = User::where('email', 'admin@esalud.cl')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('Analista'));
        $this->assertFalse($user->hasRole('Superadmin'));
        $this->assertCount(1, $user->getRoleNames());
    }

    public function test_creating_new_user_with_superadmin_requires_additional_confirmation_and_can_be_declined(): void
    {
        $this->artisan('auth:reset-admin', ['--role' => 'Superadmin'])
            ->expectsConfirmation('¿Confirmas que deseas continuar? Esto sobrescribirá la contraseña de este usuario.', 'yes')
            ->expectsQuestion('Nueva contraseña (oculta, mínimo 8 caracteres)', 'unaClave123')
            ->expectsQuestion('Confirmar contraseña (oculta)', 'unaClave123')
            ->expectsConfirmation('Vas a crear el usuario con rol Superadmin. ¿Confirmas explícitamente?', 'no')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseMissing('users', ['email' => 'admin@esalud.cl']);
    }

    public function test_creating_new_user_with_superadmin_confirmed_explicitly_grants_it(): void
    {
        $this->artisan('auth:reset-admin', ['--role' => 'Superadmin'])
            ->expectsConfirmation('¿Confirmas que deseas continuar? Esto sobrescribirá la contraseña de este usuario.', 'yes')
            ->expectsQuestion('Nueva contraseña (oculta, mínimo 8 caracteres)', 'unaClave123')
            ->expectsQuestion('Confirmar contraseña (oculta)', 'unaClave123')
            ->expectsConfirmation('Vas a crear el usuario con rol Superadmin. ¿Confirmas explícitamente?', 'yes')
            ->assertExitCode(Command::SUCCESS);

        $user = User::where('email', 'admin@esalud.cl')->first();
        $this->assertTrue($user->hasRole('Superadmin'));
    }

    public function test_resetting_existing_user_never_modifies_roles(): void
    {
        $existing = User::factory()->create([
            'email' => 'admin@esalud.cl',
            'password' => Hash::make('vieja-clave-conocida'),
        ]);
        $existing->assignRole('Auditor');

        $this->artisan('auth:reset-admin')
            ->expectsConfirmation('¿Confirmas que deseas continuar? Esto sobrescribirá la contraseña de este usuario.', 'yes')
            ->expectsQuestion('Nueva contraseña (oculta, mínimo 8 caracteres)', 'claveNuevaSegura1')
            ->expectsQuestion('Confirmar contraseña (oculta)', 'claveNuevaSegura1')
            ->assertExitCode(Command::SUCCESS);

        $existing->refresh();

        $this->assertTrue($existing->hasRole('Auditor'));
        $this->assertFalse($existing->hasRole('Superadmin'));
        $this->assertCount(1, $existing->getRoleNames());
    }

    public function test_reset_no_longer_forces_the_previously_hardcoded_known_password(): void
    {
        $existing = User::factory()->create(['email' => 'admin@esalud.cl']);

        $this->artisan('auth:reset-admin')
            ->expectsConfirmation('¿Confirmas que deseas continuar? Esto sobrescribirá la contraseña de este usuario.', 'yes')
            ->expectsQuestion('Nueva contraseña (oculta, mínimo 8 caracteres)', 'miClaveCustom99')
            ->expectsQuestion('Confirmar contraseña (oculta)', 'miClaveCustom99')
            ->assertExitCode(Command::SUCCESS);

        $existing->refresh();

        $this->assertFalse(Hash::check('password', $existing->password));
        $this->assertTrue(Hash::check('miClaveCustom99', $existing->password));
    }

    public function test_aborts_when_confirmation_declined(): void
    {
        $existing = User::factory()->create([
            'email' => 'admin@esalud.cl',
            'password' => Hash::make('clave-original'),
        ]);

        $this->artisan('auth:reset-admin')
            ->expectsConfirmation('¿Confirmas que deseas continuar? Esto sobrescribirá la contraseña de este usuario.', 'no')
            ->assertExitCode(Command::FAILURE);

        $existing->refresh();
        $this->assertTrue(Hash::check('clave-original', $existing->password));
    }

    public function test_aborts_when_passwords_do_not_match(): void
    {
        $existing = User::factory()->create([
            'email' => 'admin@esalud.cl',
            'password' => Hash::make('clave-original'),
        ]);

        $this->artisan('auth:reset-admin')
            ->expectsConfirmation('¿Confirmas que deseas continuar? Esto sobrescribirá la contraseña de este usuario.', 'yes')
            ->expectsQuestion('Nueva contraseña (oculta, mínimo 8 caracteres)', 'claveUno1234')
            ->expectsQuestion('Confirmar contraseña (oculta)', 'claveDistinta5678')
            ->assertExitCode(Command::FAILURE);

        $existing->refresh();
        $this->assertTrue(Hash::check('clave-original', $existing->password));
    }

    public function test_aborts_when_password_too_short(): void
    {
        $existing = User::factory()->create([
            'email' => 'admin@esalud.cl',
            'password' => Hash::make('clave-original'),
        ]);

        $this->artisan('auth:reset-admin')
            ->expectsConfirmation('¿Confirmas que deseas continuar? Esto sobrescribirá la contraseña de este usuario.', 'yes')
            ->expectsQuestion('Nueva contraseña (oculta, mínimo 8 caracteres)', 'corta')
            ->expectsQuestion('Confirmar contraseña (oculta)', 'corta')
            ->assertExitCode(Command::FAILURE);

        $existing->refresh();
        $this->assertTrue(Hash::check('clave-original', $existing->password));
    }

    public function test_successful_reset_creates_activity_log_entry(): void
    {
        $existing = User::factory()->create(['email' => 'admin@esalud.cl']);

        $this->artisan('auth:reset-admin')
            ->expectsConfirmation('¿Confirmas que deseas continuar? Esto sobrescribirá la contraseña de este usuario.', 'yes')
            ->expectsQuestion('Nueva contraseña (oculta, mínimo 8 caracteres)', 'claveAuditada123')
            ->expectsQuestion('Confirmar contraseña (oculta)', 'claveAuditada123')
            ->assertExitCode(Command::SUCCESS);

        $activity = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $existing->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('artisan:auth:reset-admin', $activity->getExtraProperty('via'));
        $this->assertSame('password_reset', $activity->getExtraProperty('action'));
    }

    public function test_invalid_role_option_aborts_without_creating_user(): void
    {
        $this->artisan('auth:reset-admin', ['--role' => 'RolQueNoExiste'])
            ->expectsConfirmation('¿Confirmas que deseas continuar? Esto sobrescribirá la contraseña de este usuario.', 'yes')
            ->expectsQuestion('Nueva contraseña (oculta, mínimo 8 caracteres)', 'claveCualquiera1')
            ->expectsQuestion('Confirmar contraseña (oculta)', 'claveCualquiera1')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseMissing('users', ['email' => 'admin@esalud.cl']);
    }
}
