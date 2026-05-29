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
                'description' => 'REM A - Consultas Medicas',
                'official_filename_pattern' => 'SA_26_V*.xlsm',
                'config' => $this->getConfigA(),
            ],
            [
                'year' => 2026, 'rem_type' => 'BM', 'version' => 'V1.1',
                'description' => 'REM BM - Salud Mental',
                'official_filename_pattern' => 'SBM_26_V*.xlsm',
                'config' => $this->getPlaceholderConfig('REM BM - Salud Mental'),
            ],
            [
                'year' => 2026, 'rem_type' => 'BS', 'version' => 'V1.1',
                'description' => 'REM BS - Salud Bucal',
                'official_filename_pattern' => 'SBS_26_V*.xlsm',
                'config' => $this->getPlaceholderConfig('REM BS - Salud Bucal'),
            ],
            [
                'year' => 2026, 'rem_type' => 'D', 'version' => 'V1.1',
                'description' => 'REM D - Discapacidad',
                'official_filename_pattern' => 'SD_26_V*.xlsm',
                'config' => $this->getPlaceholderConfig('REM D - Discapacidad'),
            ],
            [
                'year' => 2026, 'rem_type' => 'P', 'version' => 'V1.2',
                'description' => 'REM P - Programas',
                'official_filename_pattern' => 'SP_26_V*.xlsm',
                'config' => $this->getPlaceholderConfig('REM P - Programas'),
            ],
        ];

        foreach ($templates as $t) {
            RemTemplate::updateOrCreate(
                ['year' => $t['year'], 'rem_type' => $t['rem_type']],
                [
                    'version' => $t['version'],
                    'is_active' => true,
                    'config' => $t['config'],
                ]
            );
        }
    }

    private function getConfigA(): array
    {
        return [
            'metadata' => [
                'year' => 2026,
                'rem_type' => 'A',
                'version' => 'V1.2',
                'official_filename_pattern' => 'SA_26_V*.xlsm',
                'source' => 'Servicio de Salud Tarapaca - sstarapaca.redsalud.gob.cl',
                'description' => 'REM A - Consultas y Controles',
            ],
            'validation' => [
                'expected_sheets' => ['A01'],
                'min_sheets' => 1,
                'max_file_size_mb' => 10,
            ],
            'sheets' => [
                [
                    'sheet_name' => 'A01',
                    'section_code' => 'A01',
                    'title' => 'Controles de Salud',
                    'is_required' => true,
                    'structure' => [
                        'header_row' => 9,
                        'sub_header_row' => 10,
                        'data_start_row' => 11,
                        'section_break_pattern' => '/^SECCI[OÓ][NÑ]/u',
                        'concept_column' => 'A',
                        'professional_column' => 'B',
                        'total_column' => 'C',
                    ],
                    'column_groups' => [
                        ['label' => 'RANGO ETARIO', 'start_col' => 'D', 'end_col' => 'T'],
                        ['label' => 'Sexo', 'start_col' => 'U', 'end_col' => 'V'],
                        ['label' => 'Control con pareja', 'start_col' => 'W', 'end_col' => 'W'],
                        ['label' => 'Control de diada', 'start_col' => 'X', 'end_col' => 'X'],
                        ['label' => 'Espacios Amigables', 'start_col' => 'Y', 'end_col' => 'Y'],
                        ['label' => 'SENAME', 'start_col' => 'Z', 'end_col' => 'Z'],
                        ['label' => 'Servicio Nac. Proteccion', 'start_col' => 'AA', 'end_col' => 'AA'],
                        ['label' => 'Pueblos Originarios', 'start_col' => 'AB', 'end_col' => 'AB'],
                        ['label' => 'Migrantes', 'start_col' => 'AC', 'end_col' => 'AC'],
                        ['label' => 'Personas con discapacidad', 'start_col' => 'AD', 'end_col' => 'AD'],
                        ['label' => 'Identificacion de genero', 'start_col' => 'AE', 'end_col' => 'AG'],
                        ['label' => 'Adolescente MAS', 'start_col' => 'AH', 'end_col' => 'AH'],
                    ],
                    'columns' => [
                        ['letter' => 'D', 'label' => 'Menos de 4 anios', 'demographic_key' => 'under_4'],
                        ['letter' => 'E', 'label' => '5 - 9 anios', 'demographic_key' => 'age_05_09'],
                        ['letter' => 'F', 'label' => '10 - 14 anios', 'demographic_key' => 'age_10_14'],
                        ['letter' => 'G', 'label' => '15 - 19 anios', 'demographic_key' => 'age_15_19'],
                        ['letter' => 'H', 'label' => '20 - 24 anios', 'demographic_key' => 'age_20_24'],
                        ['letter' => 'I', 'label' => '25 - 29 anios', 'demographic_key' => 'age_25_29'],
                        ['letter' => 'J', 'label' => '30 - 34 anios', 'demographic_key' => 'age_30_34'],
                        ['letter' => 'K', 'label' => '35 - 39 anios', 'demographic_key' => 'age_35_39'],
                        ['letter' => 'L', 'label' => '40 - 44 anios', 'demographic_key' => 'age_40_44'],
                        ['letter' => 'M', 'label' => '45 - 49 anios', 'demographic_key' => 'age_45_49'],
                        ['letter' => 'N', 'label' => '50 - 54 anios', 'demographic_key' => 'age_50_54'],
                        ['letter' => 'O', 'label' => '55 - 59 anios', 'demographic_key' => 'age_55_59'],
                        ['letter' => 'P', 'label' => '60 - 64 anios', 'demographic_key' => 'age_60_64'],
                        ['letter' => 'Q', 'label' => '65 - 69 anios', 'demographic_key' => 'age_65_69'],
                        ['letter' => 'R', 'label' => '70 - 74 anios', 'demographic_key' => 'age_70_74'],
                        ['letter' => 'S', 'label' => '75 - 79 anios', 'demographic_key' => 'age_75_79'],
                        ['letter' => 'T', 'label' => '80 y mas anios', 'demographic_key' => 'age_80_plus'],
                        ['letter' => 'U', 'label' => 'Hombres', 'demographic_key' => 'male'],
                        ['letter' => 'V', 'label' => 'Mujeres', 'demographic_key' => 'female'],
                        ['letter' => 'W', 'label' => 'Control con pareja, familiar u otro significativo', 'demographic_key' => 'control_with_partner'],
                        ['letter' => 'X', 'label' => 'Control de diada con presencia de ambos padres', 'demographic_key' => 'control_dyad'],
                        ['letter' => 'Y', 'label' => 'Espacios Amigables / Adolescentes', 'demographic_key' => 'friendly_spaces'],
                        ['letter' => 'Z', 'label' => 'SENAME / Servicio Nacional de Reinsercion Social', 'demographic_key' => 'sename'],
                        ['letter' => 'AA', 'label' => 'Servicio Nacional Proteccion Especializada', 'demographic_key' => 'national_protection'],
                        ['letter' => 'AB', 'label' => 'Pueblos Originarios', 'demographic_key' => 'indigenous'],
                        ['letter' => 'AC', 'label' => 'Migrantes', 'demographic_key' => 'migrant'],
                        ['letter' => 'AD', 'label' => 'Personas con discapacidad', 'demographic_key' => 'disability'],
                        ['letter' => 'AE', 'label' => 'Trans masculino', 'demographic_key' => 'trans_masculine'],
                        ['letter' => 'AF', 'label' => 'Trans femenina', 'demographic_key' => 'trans_feminine'],
                        ['letter' => 'AG', 'label' => 'No binarie', 'demographic_key' => 'non_binary'],
                        ['letter' => 'AH', 'label' => 'Adolescente acude a control MAS otro facultativo', 'demographic_key' => 'adolescent_mas'],
                    ],
                    'validation_rules' => [
                        'data_type' => 'integer',
                        'min' => 0,
                        'max' => null,
                        'allow_null' => true,
                        'allow_empty_string' => true,
                    ],
                ],
            ],
            'notes' => 'Piloto Fase 04B-2a: solo hoja A01 configurada. Secciones A02-A34 pendientes.',
        ];
    }

    private function getPlaceholderConfig(string $description): array
    {
        return [
            'description' => $description,
            'official_filename_pattern' => 'pendiente',
            'source' => 'Servicio de Salud Tarapaca - sstarapaca.redsalud.gob.cl',
            'sheets' => [],
            'notes' => 'Mapping de celdas pendiente - Fase 04B-2b en adelante.',
        ];
    }
}
