<?php

namespace Database\Seeders;

use App\Domain\HealthCenters\Models\HealthCenter;
use Illuminate\Database\Seeder;

class HealthCenterSeeder extends Seeder
{
    public function run(): void
    {
        HealthCenter::create([
            'name' => 'CESFAM Sur',
            'code_deis' => 'DEIS001',
            'type' => 'CESFAM',
            'address' => 'Av. Sur 1234',
            'commune' => 'Iquique',
            'is_active' => true,
        ]);

        HealthCenter::create([
            'name' => 'CESFAM Central',
            'code_deis' => 'DEIS002',
            'type' => 'CESFAM',
            'address' => 'Calle Central 567',
            'commune' => 'Iquique',
            'is_active' => true,
        ]);

        HealthCenter::create([
            'name' => 'CECOSF Norte',
            'code_deis' => 'DEIS003',
            'type' => 'CECOSF',
            'address' => 'Av. Norte 890',
            'commune' => 'Alto Hospicio',
            'is_active' => true,
        ]);

        HealthCenter::create([
            'name' => 'PSR Rural',
            'code_deis' => 'DEIS004',
            'type' => 'PSR',
            'address' => 'Camino Rural S/N',
            'commune' => 'Pica',
            'is_active' => true,
        ]);
    }
}
