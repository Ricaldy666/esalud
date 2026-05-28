<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::create([
            'name' => 'Administrador Esalud',
            'rut' => '11111111-1',
            'email' => 'admin@esalud.cl',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $user->assignRole('Administrador');
    }
}
