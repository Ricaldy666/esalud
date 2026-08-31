<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;

/**
 * Crea o resetea el usuario de soporte local admin@esalud.cl.
 *
 * Endurecido 2026-08-31 (Fase Seguridad 1, prerequisito de 2FA) -- la version
 * original forzaba la password literal "password" y asignaba Superadmin sin
 * ninguna guarda, en cualquier entorno. Esta version:
 * - Solo se ejecuta en entorno local/testing (aborta en cualquier otro).
 * - Nunca asigna un rol por defecto -- si el usuario no existe, exige
 *   --role= explicito (nunca Superadmin sin una confirmacion adicional).
 * - Si el usuario ya existe, jamas toca sus roles -- solo la password.
 * - Password nueva via entrada oculta con doble confirmacion, nunca
 *   hardcodeada, nunca impresa en pantalla ni en logs.
 * - Exige confirmacion explicita antes de escribir.
 * - Deja un registro de auditoria (activity log) de cada ejecucion exitosa.
 */
class ResetAdminCommand extends Command
{
    protected $signature = 'auth:reset-admin {--role= : Rol a asignar solo si el usuario se crea por primera vez (obligatorio en ese caso, nunca automatico)}';

    protected $description = 'Crea o resetea la password del usuario de soporte local admin@esalud.cl (solo entorno local/testing)';

    public function handle(): int
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->error(sprintf(
                'Este comando solo puede ejecutarse en entorno local o testing. Entorno actual: "%s". Abortado, nada escrito.',
                app()->environment()
            ));

            return self::FAILURE;
        }

        $email = 'admin@esalud.cl';
        $existingUser = User::withTrashed()->where('email', $email)->first();

        $this->line('--- Usuario objetivo ---');
        $this->line("Email: {$email}");
        $this->line('Entorno: ' . app()->environment());

        if ($existingUser) {
            $this->line("Existe: sí (id={$existingUser->id})");
            $this->line('Roles actuales (no se modificarán): ' . ($existingUser->getRoleNames()->implode(', ') ?: '(sin roles)'));
        } else {
            $this->line('Existe: no, se creará');

            $role = $this->option('role');

            if (empty($role)) {
                $this->error('Al crear un usuario nuevo se requiere --role= explícito (nunca se asigna un rol por defecto). Roles disponibles: ' . Role::query()->pluck('name')->implode(', '));

                return self::FAILURE;
            }
        }

        if (!$this->confirm('¿Confirmas que deseas continuar? Esto sobrescribirá la contraseña de este usuario.', false)) {
            $this->warn('ABORTADO_POR_USUARIO');

            return self::FAILURE;
        }

        $password = $this->secret('Nueva contraseña (oculta, mínimo 8 caracteres)');
        $confirmPassword = $this->secret('Confirmar contraseña (oculta)');

        if (empty($password) || strlen($password) < 8) {
            $password = null;
            $confirmPassword = null;
            unset($password, $confirmPassword);

            $this->error('SIN_INPUT_O_MUY_CORTA — abortado, nada escrito.');

            return self::FAILURE;
        }

        if (!hash_equals($password, $confirmPassword ?? '')) {
            $password = null;
            $confirmPassword = null;
            unset($password, $confirmPassword);

            $this->error('NO_COINCIDEN — abortado, nada escrito.');

            return self::FAILURE;
        }

        if ($existingUser) {
            if ($existingUser->trashed()) {
                $existingUser->restore();
                $this->line('Usuario soft-deleted restaurado.');
            }

            $existingUser->update([
                'password' => Hash::make($password),
                'is_active' => true,
            ]);

            $user = $existingUser;
            $action = 'password_reset';
            $this->info("Usuario {$email} actualizado (password reseteada, roles sin modificar).");
        } else {
            $role = $this->option('role');

            if (strtolower($role) === 'superadmin') {
                if (!$this->confirm('Vas a crear el usuario con rol Superadmin. ¿Confirmas explícitamente?', false)) {
                    $password = null;
                    $confirmPassword = null;
                    unset($password, $confirmPassword);

                    $this->warn('ABORTADO_POR_USUARIO (rol Superadmin no confirmado).');

                    return self::FAILURE;
                }
            }

            $user = User::create([
                'name' => 'Administrador Esalud (local)',
                'rut' => '11111111-1',
                'email' => $email,
                'password' => Hash::make($password),
                'is_active' => true,
            ]);

            try {
                $user->assignRole($role);
            } catch (RoleDoesNotExist $e) {
                $user->forceDelete();
                $password = null;
                $confirmPassword = null;
                unset($password, $confirmPassword);

                $this->error("El rol '{$role}' no existe. Roles disponibles: " . Role::query()->pluck('name')->implode(', ') . '. Abortado, usuario no creado.');

                return self::FAILURE;
            }

            $action = 'user_created';
            $this->info("Usuario {$email} creado con rol '{$role}'.");
        }

        $password = null;
        $confirmPassword = null;
        unset($password, $confirmPassword);

        activity()
            ->performedOn($user)
            ->withProperties([
                'via' => 'artisan:auth:reset-admin',
                'environment' => app()->environment(),
                'action' => $action,
            ])
            ->log('Credenciales de admin@esalud.cl modificadas vía auth:reset-admin');

        $this->newLine();
        $this->info('Operación completada. La contraseña no se muestra ni se registra en ningún log.');

        return self::SUCCESS;
    }
}
