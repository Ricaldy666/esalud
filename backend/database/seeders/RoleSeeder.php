<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['name' => 'Superadmin']);
        Role::firstOrCreate(['name' => 'Auditor']);
        Role::firstOrCreate(['name' => 'Revisor']);
        Role::firstOrCreate(['name' => 'Analista']);
    }
}
