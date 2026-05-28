<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::create(['name' => 'Administrador']);
        Role::create(['name' => 'Analista']);
        Role::create(['name' => 'Lector']);
    }
}
