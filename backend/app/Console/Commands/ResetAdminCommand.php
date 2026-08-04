<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ResetAdminCommand extends Command
{
    protected $signature = 'auth:reset-admin';
    protected $description = 'Reset or create admin test user';

    public function handle(): int
    {
        $email = 'admin@esalud.cl';
        $password = 'password';
        $name = 'Administrador Esalud';

        $user = User::withTrashed()->where('email', $email)->first();

        if ($user) {
            if ($user->trashed()) {
                $user->restore();
                $this->line("Restored soft-deleted user.");
            }
            $user->update([
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
            ]);
            $this->info("User {$email} updated.");
        } else {
            $user = User::create([
                'name' => $name,
                'rut' => '11111111-1',
                'email' => $email,
                'password' => Hash::make($password),
                'is_active' => true,
            ]);
            $this->info("User {$email} created.");
        }

        $user->syncRoles(['Superadmin']);
        $this->info("Role 'Superadmin' assigned.");

        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['Email', $email],
                ['Password', $password],
                ['Name', $name],
                ['Role', 'Superadmin'],
            ]
        );

        return self::SUCCESS;
    }
}
