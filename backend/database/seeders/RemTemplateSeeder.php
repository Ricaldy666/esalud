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
                'expected_sheets' => ['A01', 'A02', 'A04', 'A05', 'A06', 'A08', 'A09', 'A11a', 'A23', 'A29', 'A30', 'A31', 'A32'],
                'min_sheets' => 13,
                'max_file_size_mb' => 10,
            ],
            'sheets' => [
                $this->sheetA01(),
                $this->sheetA02(),
                $this->sheetA04(),
                $this->sheetA05(),
                $this->sheetA06(),
                $this->sheetA08(),
                $this->sheetA09(),
                $this->sheetA11a(),
                $this->sheetA23(),
                $this->sheetA29(),
                $this->sheetA30(),
                $this->sheetA31(),
                $this->sheetA32(),
            ],
            'notes' => 'G1 (12 sheets A02-A32) mapeadas en Fase 04B-2b-1. Secciones B+ de cada hoja pendientes.',
        ];
    }

    private function colLetter(int $index): string
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = (int)($index / 26) - 1;
        }
        return $letter;
    }

    private function baseColumnsAgeSexPairs(string $startCol, array $ranges): array
    {
        $cols = [];
        $startIndex = $this->colIndex($startCol);
        $i = 0;
        foreach ($ranges as $key => $label) {
            $hLetter = $this->colLetter($startIndex + $i++);
            $mLetter = $this->colLetter($startIndex + $i++);
            $cols[] = ['letter' => $hLetter, 'label' => $label . ' Hombres', 'demographic_key' => $key . '_male'];
            $cols[] = ['letter' => $mLetter, 'label' => $label . ' Mujeres', 'demographic_key' => $key . '_female'];
        }
        return $cols;
    }

    private function baseColumnsAgeSexPairsNum(string $startCol, array $ranges): array
    {
        $cols = [];
        $startIndex = $this->colIndex($startCol);
        $i = 0;
        foreach ($ranges as $key => $label) {
            $hLetter = $this->colLetter($startIndex + $i++);
            $mLetter = $this->colLetter($startIndex + $i++);
            $cols[] = ['letter' => $hLetter, 'label' => $label . ' H', 'demographic_key' => $key . '_male'];
            $cols[] = ['letter' => $mLetter, 'label' => $label . ' M', 'demographic_key' => $key . '_female'];
        }
        return $cols;
    }

    private function colIndex(string $col): int
    {
        $index = 0;
        $len = strlen($col);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($col[$i]) - 64);
        }
        return $index - 1;
    }

    private function sheetA01(): array
    {
        return [
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
            'columns' => array_merge(
                $this->baseColumnsAgeSexPairs('D', [
                    'under_4' => 'Menos de 4',
                    'age_05_09' => '5-9',
                    'age_10_14' => '10-14',
                    'age_15_19' => '15-19',
                    'age_20_24' => '20-24',
                    'age_25_29' => '25-29',
                    'age_30_34' => '30-34',
                    'age_35_39' => '35-39',
                    'age_40_44' => '40-44',
                    'age_45_49' => '45-49',
                    'age_50_54' => '50-54',
                    'age_55_59' => '55-59',
                    'age_60_64' => '60-64',
                    'age_65_69' => '65-69',
                    'age_70_74' => '70-74',
                    'age_75_79' => '75-79',
                    'age_80_plus' => '80+',
                ]),
                [
                    ['letter' => 'U', 'label' => 'Hombres', 'demographic_key' => 'male'],
                    ['letter' => 'V', 'label' => 'Mujeres', 'demographic_key' => 'female'],
                    ['letter' => 'W', 'label' => 'Control con pareja', 'demographic_key' => 'control_with_partner'],
                    ['letter' => 'X', 'label' => 'Control de diada', 'demographic_key' => 'control_dyad'],
                    ['letter' => 'Y', 'label' => 'Espacios Amigables', 'demographic_key' => 'friendly_spaces'],
                    ['letter' => 'Z', 'label' => 'SENAME', 'demographic_key' => 'sename'],
                    ['letter' => 'AA', 'label' => 'Servicio Nac. Proteccion', 'demographic_key' => 'national_protection'],
                    ['letter' => 'AB', 'label' => 'Pueblos Originarios', 'demographic_key' => 'indigenous'],
                    ['letter' => 'AC', 'label' => 'Migrantes', 'demographic_key' => 'migrant'],
                    ['letter' => 'AD', 'label' => 'Discapacidad', 'demographic_key' => 'disability'],
                    ['letter' => 'AE', 'label' => 'Trans masculino', 'demographic_key' => 'trans_masculine'],
                    ['letter' => 'AF', 'label' => 'Trans femenina', 'demographic_key' => 'trans_feminine'],
                    ['letter' => 'AG', 'label' => 'No binarie', 'demographic_key' => 'non_binary'],
                    ['letter' => 'AH', 'label' => 'Adolescente MAS', 'demographic_key' => 'adolescent_mas'],
                ]
            ),
            'validation_rules' => $this->defaultValidationRules(),
        ];
    }

    private function sheetA02(): array
    {
        return [
            'sheet_name' => 'A02',
            'section_code' => 'A02',
            'title' => 'Evaluacion Nutricional (EMP)',
            'is_required' => true,
            'structure' => [
                'header_row' => 9,
                'data_start_row' => 11,
                'section_break_pattern' => '/^SECCI[OÓ][NÑ]/u',
                'concept_column' => 'A',
                'professional_column' => 'A',
                'total_column' => 'B',
            ],
            'columns' => array_merge(
                [
                    ['letter' => 'C', 'label' => 'Ambos Sexos Hombres', 'demographic_key' => 'both_sexes_male'],
                    ['letter' => 'D', 'label' => 'Ambos Sexos Mujeres', 'demographic_key' => 'both_sexes_female'],
                ],
                $this->baseColumnsAgeSexPairs('E', [
                    'age_15_19' => '15-19',
                    'age_20_24' => '20-24',
                    'age_25_29' => '25-29',
                    'age_30_34' => '30-34',
                    'age_35_39' => '35-39',
                    'age_40_44' => '40-44',
                    'age_45_49' => '45-49',
                    'age_50_54' => '50-54',
                    'age_55_59' => '55-59',
                    'age_60_64' => '60-64',
                    'age_65_69' => '65-69',
                    'age_70_74' => '70-74',
                    'age_75_79' => '75-79',
                    'age_80_plus' => '80+',
                ]),
                [
                    ['letter' => 'AG', 'label' => 'Pueblos Originarios', 'demographic_key' => 'indigenous'],
                    ['letter' => 'AH', 'label' => 'Migrantes', 'demographic_key' => 'migrant'],
                ]
            ),
            'validation_rules' => $this->defaultValidationRules(),
        ];
    }

    private function sheetA04(): array
    {
        return [
            'sheet_name' => 'A04',
            'section_code' => 'A04',
            'title' => 'Consultas de Morbilidad',
            'is_required' => true,
            'structure' => [
                'header_row' => 9,
                'data_start_row' => 11,
                'section_break_pattern' => '/^SECCI[OÓ][NÑ]/u',
                'concept_column' => 'A',
                'professional_column' => 'A',
                'total_column' => 'B',
            ],
            'columns' => array_merge(
                [
                    ['letter' => 'C', 'label' => 'Ambos Sexos Hombres', 'demographic_key' => 'both_sexes_male'],
                    ['letter' => 'D', 'label' => 'Ambos Sexos Mujeres', 'demographic_key' => 'both_sexes_female'],
                ],
                $this->baseColumnsAgeSexPairs('E', [
                    'under_1' => '<1',
                    'age_01_04' => '1-4',
                    'age_05_09' => '5-9',
                    'age_10_14' => '10-14',
                    'age_15_19' => '15-19',
                    'age_20_24' => '20-24',
                    'age_25_29' => '25-29',
                    'age_30_34' => '30-34',
                    'age_35_39' => '35-39',
                    'age_40_44' => '40-44',
                    'age_45_49' => '45-49',
                    'age_50_54' => '50-54',
                    'age_55_59' => '55-59',
                    'age_60_64' => '60-64',
                    'age_65_69' => '65-69',
                    'age_70_74' => '70-74',
                    'age_75_79' => '75-79',
                    'age_80_plus' => '80+',
                ]),
                [
                    ['letter' => 'AO', 'label' => 'Pueblos Originarios', 'demographic_key' => 'indigenous'],
                    ['letter' => 'AP', 'label' => 'Migrantes', 'demographic_key' => 'migrant'],
                    ['letter' => 'AQ', 'label' => 'SENAME', 'demographic_key' => 'sename'],
                    ['letter' => 'AR', 'label' => 'Servicio Nac. Proteccion', 'demographic_key' => 'national_protection'],
                    ['letter' => 'AS', 'label' => 'Campaña Invierno', 'demographic_key' => 'winter_campaign'],
                ]
            ),
            'validation_rules' => $this->defaultValidationRules(),
        ];
    }

    private function sheetA05(): array
    {
        return [
            'sheet_name' => 'A05',
            'section_code' => 'A05',
            'title' => 'Control Prenatal',
            'is_required' => true,
            'structure' => [
                'header_row' => 9,
                'data_start_row' => 11,
                'section_break_pattern' => '/^SECCI[OÓ][NÑ]/u',
                'concept_column' => 'A',
                'professional_column' => 'A',
                'total_column' => 'C',
            ],
            'columns' => [
                ['letter' => 'D', 'label' => 'Menor 14 años', 'demographic_key' => 'under_14'],
                ['letter' => 'E', 'label' => '14 años', 'demographic_key' => 'age_14'],
                ['letter' => 'F', 'label' => '15-19 años', 'demographic_key' => 'age_15_19'],
                ['letter' => 'G', 'label' => '20-24 años', 'demographic_key' => 'age_20_24'],
                ['letter' => 'H', 'label' => '25-29 años', 'demographic_key' => 'age_25_29'],
                ['letter' => 'I', 'label' => '30-34 años', 'demographic_key' => 'age_30_34'],
                ['letter' => 'J', 'label' => '35-39 años', 'demographic_key' => 'age_35_39'],
                ['letter' => 'K', 'label' => '40-44 años', 'demographic_key' => 'age_40_44'],
                ['letter' => 'L', 'label' => '45-49 años', 'demographic_key' => 'age_45_49'],
                ['letter' => 'M', 'label' => '50-54 años', 'demographic_key' => 'age_50_54'],
                ['letter' => 'N', 'label' => '55 y más años', 'demographic_key' => 'age_55_plus'],
                ['letter' => 'O', 'label' => 'Víctima Violencia Género', 'demographic_key' => 'gender_violence'],
                ['letter' => 'P', 'label' => 'Ingresos por Traslado', 'demographic_key' => 'transfer_admissions'],
                ['letter' => 'Q', 'label' => 'Control Precoz <14 sem', 'demographic_key' => 'early_control'],
                ['letter' => 'R', 'label' => 'Derivación IVE causal 3', 'demographic_key' => 'ive_referral'],
                ['letter' => 'S', 'label' => 'Trans masculino', 'demographic_key' => 'trans_masculine'],
                ['letter' => 'T', 'label' => 'No binarie', 'demographic_key' => 'non_binary'],
                ['letter' => 'U', 'label' => 'Discapacidad', 'demographic_key' => 'disability'],
                ['letter' => 'V', 'label' => 'Pueblos Originarios', 'demographic_key' => 'indigenous'],
                ['letter' => 'W', 'label' => 'Migrantes menores 20 años', 'demographic_key' => 'migrant_under_20'],
                ['letter' => 'X', 'label' => 'Migrantes 20 y más años', 'demographic_key' => 'migrant_20_plus'],
            ],
            'validation_rules' => $this->defaultValidationRules(),
        ];
    }

    private function sheetA06(): array
    {
        return [
            'sheet_name' => 'A06',
            'section_code' => 'A06',
            'title' => 'Salud Mental',
            'is_required' => true,
            'structure' => [
                'header_row' => 9,
                'data_start_row' => 11,
                'section_break_pattern' => '/^SECCI[OÓ][NÑ]/u',
                'concept_column' => 'A',
                'professional_column' => 'B',
                'total_column' => 'C',
            ],
            'columns' => array_merge(
                $this->baseColumnsAgeSexPairs('D', [
                    'under_4' => '0-4',
                    'age_05_09' => '5-9',
                    'age_10_14' => '10-14',
                    'age_15_19' => '15-19',
                    'age_20_24' => '20-24',
                    'age_25_29' => '25-29',
                    'age_30_34' => '30-34',
                    'age_35_39' => '35-39',
                    'age_40_44' => '40-44',
                    'age_45_49' => '45-49',
                    'age_50_54' => '50-54',
                    'age_55_59' => '55-59',
                    'age_60_64' => '60-64',
                    'age_65_69' => '65-69',
                    'age_70_74' => '70-74',
                    'age_75_79' => '75-79',
                    'age_80_plus' => '80+',
                ]),
                [
                    ['letter' => 'AM', 'label' => 'Beneficiarios', 'demographic_key' => 'beneficiaries'],
                    ['letter' => 'AN', 'label' => 'SENAME', 'demographic_key' => 'sename'],
                    ['letter' => 'AO', 'label' => 'Servicio Nac. Proteccion', 'demographic_key' => 'national_protection'],
                    ['letter' => 'AP', 'label' => 'Pueblos Originarios', 'demographic_key' => 'indigenous'],
                    ['letter' => 'AQ', 'label' => 'Migrantes', 'demographic_key' => 'migrant'],
                    ['letter' => 'AR', 'label' => 'Demencia', 'demographic_key' => 'dementia'],
                    ['letter' => 'AS', 'label' => 'Trans Masculino', 'demographic_key' => 'trans_masculine'],
                    ['letter' => 'AT', 'label' => 'Trans Femenino', 'demographic_key' => 'trans_feminine'],
                    ['letter' => 'AU', 'label' => 'Cuidadores Demencia', 'demographic_key' => 'dementia_caregivers'],
                ]
            ),
            'validation_rules' => $this->defaultValidationRules(),
        ];
    }

    private function sheetA08(): array
    {
        return [
            'sheet_name' => 'A08',
            'section_code' => 'A08',
            'title' => 'Urgencia',
            'is_required' => true,
            'structure' => [
                'header_row' => 9,
                'data_start_row' => 11,
                'section_break_pattern' => '/^SECCI[OÓ][NÑ]/u',
                'concept_column' => 'A',
                'professional_column' => 'A',
                'total_column' => 'B',
            ],
            'columns' => array_merge(
                [
                    ['letter' => 'C', 'label' => 'Ambos Sexos Hombres', 'demographic_key' => 'both_sexes_male'],
                    ['letter' => 'D', 'label' => 'Ambos Sexos Mujeres', 'demographic_key' => 'both_sexes_female'],
                ],
                $this->baseColumnsAgeSexPairs('E', [
                    'under_4' => '0-4',
                    'age_05_09' => '5-9',
                    'age_10_14' => '10-14',
                    'age_15_19' => '15-19',
                    'age_20_24' => '20-24',
                    'age_25_29' => '25-29',
                    'age_30_34' => '30-34',
                    'age_35_39' => '35-39',
                    'age_40_44' => '40-44',
                    'age_45_49' => '45-49',
                    'age_50_54' => '50-54',
                    'age_55_59' => '55-59',
                    'age_60_64' => '60-64',
                    'age_65_69' => '65-69',
                    'age_70_74' => '70-74',
                    'age_75_79' => '75-79',
                    'age_80_plus' => '80+',
                ]),
                [
                    ['letter' => 'AM', 'label' => 'Beneficiarios', 'demographic_key' => 'beneficiaries'],
                    ['letter' => 'AN', 'label' => 'SAPU/SAR/SUR', 'demographic_key' => 'sapu_sar_sur'],
                    ['letter' => 'AO', 'label' => 'Hospital Baja Complejidad', 'demographic_key' => 'hospital_low'],
                    ['letter' => 'AP', 'label' => 'Hospital Med/Alta Complejidad', 'demographic_key' => 'hospital_med_high'],
                    ['letter' => 'AQ', 'label' => 'Otros Establecimientos Red', 'demographic_key' => 'other_network'],
                    ['letter' => 'AR', 'label' => 'Otra Red', 'demographic_key' => 'other_network_ext'],
                    ['letter' => 'AS', 'label' => 'Demanda Urgencia', 'demographic_key' => 'urgency_demand'],
                ]
            ),
            'validation_rules' => $this->defaultValidationRules(),
        ];
    }

    private function sheetA09(): array
    {
        return [
            'sheet_name' => 'A09',
            'section_code' => 'A09',
            'title' => 'Odontologia',
            'is_required' => true,
            'structure' => [
                'header_row' => 9,
                'data_start_row' => 11,
                'section_break_pattern' => '/^SECCI[OÓ][NÑ]/u',
                'concept_column' => 'A',
                'professional_column' => 'A',
                'total_column' => 'D',
            ],
            'columns' => array_merge(
                [
                    ['letter' => 'E', 'label' => 'Ambos Sexos Hombres', 'demographic_key' => 'both_sexes_male'],
                    ['letter' => 'F', 'label' => 'Ambos Sexos Mujeres', 'demographic_key' => 'both_sexes_female'],
                ],
                $this->baseColumnsAgeSexPairs('G', [
                    'under_1' => '<1',
                    'age_01' => '1',
                    'age_02' => '2',
                    'age_03' => '3',
                    'age_04' => '4',
                    'age_05' => '5',
                    'age_06' => '6',
                    'age_07' => '7',
                    'age_08_09' => '8-9',
                    'age_10_14' => '10-14',
                    'age_15_19' => '15-19',
                    'age_20_29' => '20-29',
                    'age_30_39' => '30-39',
                    'age_40_49' => '40-49',
                    'age_50_59' => '50-59',
                    'age_60_64' => '60-64',
                    'age_65_74' => '65-74',
                    'age_75_plus' => '75+',
                ]),
                [
                    ['letter' => 'AQ', 'label' => 'Edad 12 años', 'demographic_key' => 'age_12'],
                    ['letter' => 'AR', 'label' => 'Gestante', 'demographic_key' => 'pregnant'],
                    ['letter' => 'AS', 'label' => 'Edad 60 años', 'demographic_key' => 'age_60'],
                    ['letter' => 'AT', 'label' => 'Discapacidad', 'demographic_key' => 'disability'],
                    ['letter' => 'AU', 'label' => 'Migrantes', 'demographic_key' => 'migrant'],
                    ['letter' => 'AV', 'label' => 'Ingreso Integral', 'demographic_key' => 'comprehensive_income'],
                    ['letter' => 'AW', 'label' => 'SENAME', 'demographic_key' => 'sename'],
                    ['letter' => 'AX', 'label' => 'Servicio Nac. Proteccion', 'demographic_key' => 'national_protection'],
                ]
            ),
            'validation_rules' => $this->defaultValidationRules(),
        ];
    }

    private function sheetA11a(): array
    {
        return [
            'sheet_name' => 'A11a',
            'section_code' => 'A11a',
            'title' => 'ITS VIH Sifilis',
            'is_required' => true,
            'structure' => [
                'header_row' => 9,
                'data_start_row' => 11,
                'section_break_pattern' => '/^SECCI[OÓ][NÑ]/u',
                'concept_column' => 'A',
                'professional_column' => 'A',
                'total_column' => 'B',
            ],
            'columns' => array_merge(
                $this->baseColumnsAgeSexPairs('C', [
                    'age_10_14' => '10-14',
                    'age_15_19' => '15-19',
                    'age_20_24' => '20-24',
                    'age_25_29' => '25-29',
                    'age_30_34' => '30-34',
                    'age_35_39' => '35-39',
                    'age_40_44' => '40-44',
                    'age_45_49' => '45-49',
                    'age_50_54' => '50-54',
                ]),
                [
                    ['letter' => 'L', 'label' => 'Pueblos Originarios', 'demographic_key' => 'indigenous'],
                    ['letter' => 'M', 'label' => 'Migrantes', 'demographic_key' => 'migrant'],
                ]
            ),
            'validation_rules' => $this->defaultValidationRules(),
        ];
    }

    private function sheetA23(): array
    {
        return [
            'sheet_name' => 'A23',
            'section_code' => 'A23',
            'title' => 'IRAS y ERC',
            'is_required' => true,
            'structure' => [
                'header_row' => 9,
                'data_start_row' => 11,
                'section_break_pattern' => '/^SECCI[OÓ][NÑ]/u',
                'concept_column' => 'A',
                'professional_column' => 'A',
                'total_column' => 'B',
            ],
            'columns' => array_merge(
                [
                    ['letter' => 'C', 'label' => 'Ambos Sexos Hombres', 'demographic_key' => 'both_sexes_male'],
                    ['letter' => 'D', 'label' => 'Ambos Sexos Mujeres', 'demographic_key' => 'both_sexes_female'],
                ],
                $this->baseColumnsAgeSexPairs('E', [
                    'under_1' => '<1',
                    'age_01_04' => '1-4',
                    'age_05_09' => '5-9',
                    'age_10_14' => '10-14',
                    'age_15_19' => '15-19',
                    'age_20_24' => '20-24',
                    'age_25_29' => '25-29',
                    'age_30_34' => '30-34',
                    'age_35_39' => '35-39',
                    'age_40_44' => '40-44',
                    'age_45_49' => '45-49',
                    'age_50_54' => '50-54',
                    'age_55_59' => '55-59',
                    'age_60_64' => '60-64',
                    'age_65_69' => '65-69',
                    'age_70_74' => '70-74',
                    'age_75_79' => '75-79',
                    'age_80_plus' => '80+',
                ]),
                [
                    ['letter' => 'AO', 'label' => 'Pueblos Originarios', 'demographic_key' => 'indigenous'],
                    ['letter' => 'AP', 'label' => 'Migrantes', 'demographic_key' => 'migrant'],
                    ['letter' => 'AQ', 'label' => 'Campaña Invierno', 'demographic_key' => 'winter_campaign'],
                ]
            ),
            'validation_rules' => $this->defaultValidationRules(),
        ];
    }

    private function sheetA29(): array
    {
        return [
            'sheet_name' => 'A29',
            'section_code' => 'A29',
            'title' => 'Interconsultas',
            'is_required' => true,
            'structure' => [
                'header_row' => 9,
                'data_start_row' => 11,
                'section_break_pattern' => '/^SECCI[OÓ][NÑ]/u',
                'concept_column' => 'A',
                'professional_column' => 'A',
                'total_column' => 'B',
            ],
            'columns' => [
                ['letter' => 'C', 'label' => 'Ambos Sexos Hombres', 'demographic_key' => 'both_sexes_male'],
                ['letter' => 'D', 'label' => 'Ambos Sexos Mujeres', 'demographic_key' => 'both_sexes_female'],
                ['letter' => 'E', 'label' => '<15 años Hombres', 'demographic_key' => 'under_15_male'],
                ['letter' => 'F', 'label' => '<15 años Mujeres', 'demographic_key' => 'under_15_female'],
                ['letter' => 'G', 'label' => '15-19 años Hombres', 'demographic_key' => 'age_15_19_male'],
                ['letter' => 'H', 'label' => '15-19 años Mujeres', 'demographic_key' => 'age_15_19_female'],
                ['letter' => 'I', 'label' => '20-64 años Hombres', 'demographic_key' => 'age_20_64_male'],
                ['letter' => 'J', 'label' => '20-64 años Mujeres', 'demographic_key' => 'age_20_64_female'],
                ['letter' => 'K', 'label' => '65+ años Hombres', 'demographic_key' => 'age_65_plus_male'],
                ['letter' => 'L', 'label' => '65+ años Mujeres', 'demographic_key' => 'age_65_plus_female'],
                ['letter' => 'M', 'label' => 'Interconsultas generadas <15', 'demographic_key' => 'interconsult_under_15'],
                ['letter' => 'N', 'label' => 'Interconsultas generadas 15+', 'demographic_key' => 'interconsult_15_plus'],
                ['letter' => 'O', 'label' => 'Interconsultas resueltas <15', 'demographic_key' => 'interconsult_resolved_under_15'],
                ['letter' => 'P', 'label' => 'Interconsultas resueltas 15+', 'demographic_key' => 'interconsult_resolved_15_plus'],
                ['letter' => 'Q', 'label' => 'Modalidad Presencial', 'demographic_key' => 'in_person'],
                ['letter' => 'R', 'label' => 'Modalidad Remota', 'demographic_key' => 'remote'],
                ['letter' => 'S', 'label' => 'Pueblos Originarios', 'demographic_key' => 'indigenous'],
                ['letter' => 'T', 'label' => 'Migrantes', 'demographic_key' => 'migrant'],
            ],
            'validation_rules' => $this->defaultValidationRules(),
        ];
    }

    private function sheetA30(): array
    {
        return [
            'sheet_name' => 'A30',
            'section_code' => 'A30',
            'title' => 'Teleinterconsultas',
            'is_required' => true,
            'structure' => [
                'header_row' => 9,
                'data_start_row' => 11,
                'section_break_pattern' => '/^SECCI[OÓ][NÑ]/u',
                'concept_column' => 'A',
                'professional_column' => 'A',
                'total_column' => 'B',
            ],
            'columns' => array_merge(
                $this->baseColumnsAgeSexPairsNum('B', [
                    'block1_0_14' => 'Bl1 0-14',
                    'block1_15_17' => 'Bl1 15-17',
                    'block1_18_19' => 'Bl1 18-19',
                    'block1_20_plus' => 'Bl1 20+',
                ]),
                $this->baseColumnsAgeSexPairsNum('J', [
                    'block2_0_14' => 'Bl2 0-14',
                    'block2_15_17' => 'Bl2 15-17',
                    'block2_18_19' => 'Bl2 18-19',
                    'block2_20_plus' => 'Bl2 20+',
                ]),
                $this->baseColumnsAgeSexPairsNum('R', [
                    'block3_0_14' => 'Bl3 0-14',
                    'block3_15_17' => 'Bl3 15-17',
                    'block3_18_19' => 'Bl3 18-19',
                    'block3_20_plus' => 'Bl3 20+',
                ]),
                [
                    ['letter' => 'Y', 'label' => 'Modalidad Institucional', 'demographic_key' => 'block3_institutional'],
                    ['letter' => 'Z', 'label' => 'Modalidad Compra Servicio', 'demographic_key' => 'block3_service_purchase'],
                    ['letter' => 'AA', 'label' => 'Sistema', 'demographic_key' => 'block3_system'],
                    ['letter' => 'AB', 'label' => 'Extrasistema', 'demographic_key' => 'block3_extrasystem'],
                ],
                $this->baseColumnsAgeSexPairsNum('AC', [
                    'block4_0_14' => 'Bl4 0-14',
                    'block4_15_17' => 'Bl4 15-17',
                    'block4_18_19' => 'Bl4 18-19',
                    'block4_20_plus' => 'Bl4 20+',
                ]),
                [
                    ['letter' => 'AK', 'label' => 'Modalidad Institucional', 'demographic_key' => 'block4_institutional'],
                    ['letter' => 'AL', 'label' => 'Modalidad Compra Servicio', 'demographic_key' => 'block4_service_purchase'],
                    ['letter' => 'AM', 'label' => 'Sistema', 'demographic_key' => 'block4_system'],
                    ['letter' => 'AN', 'label' => 'Extrasistema', 'demographic_key' => 'block4_extrasystem'],
                ],
                $this->baseColumnsAgeSexPairsNum('AO', [
                    'block5_0_14' => 'Bl5 0-14',
                    'block5_15_17' => 'Bl5 15-17',
                    'block5_18_19' => 'Bl5 18-19',
                    'block5_20_plus' => 'Bl5 20+',
                ])
            ),
            'validation_rules' => $this->defaultValidationRules(),
        ];
    }

    private function sheetA31(): array
    {
        return [
            'sheet_name' => 'A31',
            'section_code' => 'A31',
            'title' => 'Medicina Complementaria',
            'is_required' => true,
            'structure' => [
                'header_row' => 9,
                'data_start_row' => 11,
                'section_break_pattern' => '/^SECCI[OÓ][NÑ]/u',
                'concept_column' => 'A',
                'professional_column' => 'B',
                'total_column' => 'C',
            ],
            'columns' => array_merge(
                [
                    ['letter' => 'D', 'label' => 'Ambos Sexos Hombres', 'demographic_key' => 'both_sexes_male'],
                    ['letter' => 'E', 'label' => 'Ambos Sexos Mujeres', 'demographic_key' => 'both_sexes_female'],
                ],
                $this->baseColumnsAgeSexPairs('F', [
                    'under_4' => '0-4',
                    'age_05_09' => '5-9',
                    'age_10_14' => '10-14',
                    'age_15_19' => '15-19',
                    'age_20_24' => '20-24',
                    'age_25_29' => '25-29',
                    'age_30_34' => '30-34',
                    'age_35_39' => '35-39',
                    'age_40_44' => '40-44',
                    'age_45_49' => '45-49',
                    'age_50_54' => '50-54',
                    'age_55_59' => '55-59',
                    'age_60_64' => '60-64',
                    'age_65_69' => '65-69',
                    'age_70_74' => '70-74',
                    'age_75_79' => '75-79',
                    'age_80_plus' => '80+',
                ]),
                [
                    ['letter' => 'AN', 'label' => 'Atenciones realizadas', 'demographic_key' => 'care_visits'],
                    ['letter' => 'AO', 'label' => 'Beneficiarios', 'demographic_key' => 'beneficiaries'],
                    ['letter' => 'AP', 'label' => 'Pueblos Originarios', 'demographic_key' => 'indigenous'],
                    ['letter' => 'AQ', 'label' => 'Migrantes', 'demographic_key' => 'migrant'],
                    ['letter' => 'AR', 'label' => 'SENAME', 'demographic_key' => 'sename'],
                    ['letter' => 'AS', 'label' => 'Ingresos MC', 'demographic_key' => 'mc_admissions'],
                    ['letter' => 'AT', 'label' => 'Controles', 'demographic_key' => 'controls'],
                    ['letter' => 'AU', 'label' => 'Pacientes', 'demographic_key' => 'patients'],
                    ['letter' => 'AV', 'label' => 'Familiares/Cuidadores', 'demographic_key' => 'family_caregivers'],
                    ['letter' => 'AW', 'label' => 'Funcionarios', 'demographic_key' => 'officials'],
                    ['letter' => 'CH', 'label' => 'Columna CH', 'demographic_key' => 'extra_1'],
                    ['letter' => 'CI', 'label' => 'Columna CI', 'demographic_key' => 'extra_2'],
                    ['letter' => 'CJ', 'label' => 'Columna CJ', 'demographic_key' => 'extra_3'],
                    ['letter' => 'CK', 'label' => 'Columna CK', 'demographic_key' => 'extra_4'],
                ]
            ),
            'validation_rules' => $this->defaultValidationRules(),
        ];
    }

    private function sheetA32(): array
    {
        return [
            'sheet_name' => 'A32',
            'section_code' => 'A32',
            'title' => 'Profesionales',
            'is_required' => true,
            'structure' => [
                'header_row' => 9,
                'data_start_row' => 11,
                'section_break_pattern' => '/^SECCI[OÓ][NÑ]/u',
                'concept_column' => 'A',
                'professional_column' => 'A',
                'total_column' => 'B',
            ],
            'columns' => array_merge(
                $this->baseColumnsAgeSexPairs('C', [
                    'under_4' => '0-4',
                    'age_05_09' => '5-9',
                    'age_10_14' => '10-14',
                    'age_15_19' => '15-19',
                    'age_20_24' => '20-24',
                    'age_25_29' => '25-29',
                    'age_30_34' => '30-34',
                    'age_35_39' => '35-39',
                    'age_40_44' => '40-44',
                    'age_45_49' => '45-49',
                    'age_50_54' => '50-54',
                    'age_55_59' => '55-59',
                    'age_60_64' => '60-64',
                    'age_65_69' => '65-69',
                    'age_70_74' => '70-74',
                    'age_75_79' => '75-79',
                    'age_80_plus' => '80+',
                ]),
                [
                    ['letter' => 'T', 'label' => 'Sexo Hombres', 'demographic_key' => 'male'],
                    ['letter' => 'U', 'label' => 'Sexo Mujeres', 'demographic_key' => 'female'],
                    ['letter' => 'V', 'label' => 'SENAME', 'demographic_key' => 'sename'],
                    ['letter' => 'W', 'label' => 'Servicio Nac. Proteccion', 'demographic_key' => 'national_protection'],
                    ['letter' => 'X', 'label' => 'Pueblos Originarios', 'demographic_key' => 'indigenous'],
                    ['letter' => 'Y', 'label' => 'Migrantes', 'demographic_key' => 'migrant'],
                    ['letter' => 'Z', 'label' => 'Espacios Amigables', 'demographic_key' => 'friendly_spaces'],
                    ['letter' => 'CK', 'label' => 'Columna CK', 'demographic_key' => 'extra_1'],
                    ['letter' => 'CL', 'label' => 'Columna CL', 'demographic_key' => 'extra_2'],
                    ['letter' => 'CM', 'label' => 'Columna CM', 'demographic_key' => 'extra_3'],
                    ['letter' => 'CN', 'label' => 'Columna CN', 'demographic_key' => 'extra_4'],
                    ['letter' => 'CO', 'label' => 'Columna CO', 'demographic_key' => 'extra_5'],
                    ['letter' => 'CP', 'label' => 'Columna CP', 'demographic_key' => 'extra_6'],
                ]
            ),
            'validation_rules' => $this->defaultValidationRules(),
        ];
    }

    private function defaultValidationRules(): array
    {
        return [
            'data_type' => 'integer',
            'min' => 0,
            'max' => null,
            'allow_null' => true,
            'allow_empty_string' => true,
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
