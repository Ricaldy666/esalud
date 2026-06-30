<?php

namespace Database\Seeders;

use App\Domain\HealthCenters\Models\HealthCenter;
use Illuminate\Database\Seeder;

class HealthCenterSeeder extends Seeder
{
    public function run(): void
    {
        $centers = [
            1 => ['name' => 'CESFAM Cirujano Guzmán', 'code_deis' => '102302', 'type' => 'CESFAM', 'address' => 'Av. Héroes de la Concepción 1551', 'commune' => 'Iquique', 'is_active' => true],
            2 => ['name' => 'SAPU Guzmán', 'code_deis' => '102802', 'type' => 'SAPU', 'address' => 'Av. Héroes de la Concepción 1551', 'commune' => 'Iquique', 'is_active' => true],
            3 => ['name' => 'Posta Caleta Chanavayita', 'code_deis' => '102412', 'type' => 'POSTA', 'address' => 'Caleta Chanavayita S/N', 'commune' => 'Iquique', 'is_active' => true],
            4 => ['name' => 'Posta Caleta San Marcos', 'code_deis' => '102413', 'type' => 'POSTA', 'address' => 'Caleta San Marcos S/N', 'commune' => 'Iquique', 'is_active' => true],
        ];

        foreach ($centers as $id => $data) {
            $center = HealthCenter::withTrashed()->find($id);
            if ($center) {
                $center->restore();
                $center->update($data);
            } else {
                HealthCenter::create(array_merge($data, ['id' => $id]));
            }
        }
    }
}
