<?php

namespace Database\Seeders;

use App\Domain\REM\Models\RemTemplate;
use Illuminate\Database\Seeder;

class RemTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'year' => 2026, 'rem_type' => 'A', 'version' => 'V1.2',
                'description' => 'REM A - Consultas Médicas',
                'official_filename_pattern' => 'SA_26_V*.xlsm',
            ],
            [
                'year' => 2026, 'rem_type' => 'BM', 'version' => 'V1.1',
                'description' => 'REM BM - Salud Mental',
                'official_filename_pattern' => 'SBM_26_V*.xlsm',
            ],
            [
                'year' => 2026, 'rem_type' => 'BS', 'version' => 'V1.1',
                'description' => 'REM BS - Salud Bucal',
                'official_filename_pattern' => 'SBS_26_V*.xlsm',
            ],
            [
                'year' => 2026, 'rem_type' => 'D', 'version' => 'V1.1',
                'description' => 'REM D - Discapacidad',
                'official_filename_pattern' => 'SD_26_V*.xlsm',
            ],
            [
                'year' => 2026, 'rem_type' => 'P', 'version' => 'V1.2',
                'description' => 'REM P - Programas',
                'official_filename_pattern' => 'SP_26_V*.xlsm',
            ],
        ];

        foreach ($templates as $t) {
            RemTemplate::create([
                'year' => $t['year'],
                'rem_type' => $t['rem_type'],
                'version' => $t['version'],
                'is_active' => true,
                'config' => [
                    'description' => $t['description'],
                    'official_filename_pattern' => $t['official_filename_pattern'],
                    'source' => 'Servicio de Salud Tarapacá - sstarapaca.redsalud.gob.cl',
                    'sheets' => [],
                    'notes' => 'Mapping de celdas pendiente - Fase 04B con archivo real.',
                ],
            ]);
        }
    }
}
