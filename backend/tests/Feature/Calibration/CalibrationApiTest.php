<?php

namespace Tests\Feature\Calibration;

use App\Domain\Calibration\Models\Calibration;
use App\Domain\Calibration\Models\CalibrationCell;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CalibrationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $analyst;
    private User $auditor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Superadmin']);
        Role::create(['name' => 'Analista']);
        Role::create(['name' => 'Auditor']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Superadmin');

        $this->analyst = User::factory()->create();
        $this->analyst->assignRole('Analista');

        $this->auditor = User::factory()->create();
        $this->auditor->assignRole('Auditor');
    }

    private function createActiveStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_a01_active',
            'estructura' => [
                'forms' => [
                    [
                        'sheet' => 'A01',
                        'sections' => [
                            ['codigo' => 'A', 'titulo' => 'Controles', 'fila_inicio' => 10, 'fila_fin' => 22],
                            ['codigo' => 'B', 'titulo' => 'RN', 'fila_inicio' => 23, 'fila_fin' => 26],
                        ],
                    ],
                ],
            ],
            'status' => 'active',
        ]);
    }

    private function createPatternStructure(): RemTemplateStructure
    {
        $fieldsA = [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'B', 'label' => 'Profesional', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'C', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false],
        ];
        foreach (range('D', 'N') as $letter) {
            $fieldsA[] = ['letra' => $letter, 'label' => 'Rango', 'esTotal' => false, 'esControlOculto' => false];
        }

        $fieldsB = [
            ['letra' => 'A', 'label' => 'TIPO DE CONTROL', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'B', 'label' => 'PROFESIONAL', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'C', 'label' => 'Ambos sexos', 'esTotal' => true, 'esControlOculto' => false],
            ['letra' => 'D', 'label' => 'Hombres', 'esTotal' => true, 'esControlOculto' => false],
            ['letra' => 'E', 'label' => 'Mujeres', 'esTotal' => true, 'esControlOculto' => false],
        ];
        foreach ([
            'F' => 'Menos de 1 mes',
            'G' => '1 mes',
            'H' => '2 meses',
            'I' => '3 meses',
            'J' => '4 meses',
            'K' => '5 meses',
            'L' => '6 meses',
            'M' => '7 - 11 meses',
            'N' => '12 - 17 meses',
            'O' => '18 - 23 meses',
            'P' => '24 - 47 meses',
            'Q' => '48 - 59 meses',
            'R' => '60 - 71 meses',
            'S' => '6 - 9 años',
            'T' => '10 - 14 años',
            'U' => '15 - 19 años',
            'V' => '20 - 24 años',
            'W' => '25 - 29 años',
            'X' => '30 - 34 años',
            'Y' => '35 - 39 años',
            'Z' => '40 - 44 años',
            'AA' => '45 - 49 años',
            'AB' => '50 - 54 años',
            'AC' => '55 - 59 años',
            'AD' => '60 - 64 años',
            'AE' => '65 - 69 años',
            'AF' => '70 - 74 años',
            'AG' => '75 - 79 años',
            'AH' => '80 y más años',
            'AI' => 'Menor 1 año',
            'AJ' => '1 año a 3 años 11 meses 29 días',
            'AK' => 'SENAME / Servicio Nacional de Reinserción Social Juvenil',
            'AL' => 'Servicio Nacional Protección Especializada a la Niñez y Adolescencia',
            'AM' => 'Pueblos Originarios',
            'AN' => 'Migrantes',
        ] as $letter => $label) {
            $fieldsB[] = ['letra' => $letter, 'label' => $label, 'esTotal' => false, 'esControlOculto' => false];
        }

        $fieldsD = [
            ['letra' => 'A', 'label' => 'LUGAR DEL CONTROL, SEGUN EDAD', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'B', 'label' => 'PROFESIONAL', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'C', 'label' => 'Ambos Sexos', 'esTotal' => true, 'esControlOculto' => false],
            ['letra' => 'D', 'label' => 'Hombres', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'E', 'label' => 'Mujeres', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'F', 'label' => 'Ambos Sexos', 'esTotal' => true, 'esControlOculto' => false],
            ['letra' => 'G', 'label' => 'Hombres', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'H', 'label' => 'Mujeres', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'I', 'label' => 'Pueblos Originarios', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'J', 'label' => 'Migrantes', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'K', 'label' => 'SENAME / Servicio Nacional de Reinsercion Social Juvenil', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'L', 'label' => 'Servicio Nacional Proteccion Especializada', 'esTotal' => false, 'esControlOculto' => false],
        ];

        $fieldsE = [
            ['letra' => 'A', 'label' => 'CURSO', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'B', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false],
            ['letra' => 'C', 'label' => 'Hombres', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'D', 'label' => 'Mujeres', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'E', 'label' => 'Total casos gestionados', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'F', 'label' => 'Casos pesquisados con riesgo', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'G', 'label' => 'Diagnostico participativo', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'H', 'label' => 'Numero de problematicas intervenidas', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'I', 'label' => 'Intervenciones educativas finalizadas', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'J', 'label' => 'Pueblos Originarios', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'K', 'label' => 'Migrantes', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'L', 'label' => 'SENAME / Servicio Nacional de Reinsercion Social Juvenil', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'M', 'label' => 'Servicio Nacional Proteccion Especializada', 'esTotal' => false, 'esControlOculto' => false],
        ];

        $fieldsF = [
            ['letra' => 'A', 'label' => 'TIPO DE CONTROL', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'B', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false],
            ['letra' => 'C', 'label' => 'Hombres', 'esTotal' => true, 'esControlOculto' => false],
            ['letra' => 'D', 'label' => 'Mujeres', 'esTotal' => true, 'esControlOculto' => false],
        ];
        foreach ([
            'E' => '15 - 19 anos Hombres',
            'F' => '15 - 19 anos Mujeres',
            'G' => '20 - 24 anos Hombres',
            'H' => '20 - 24 anos Mujeres',
            'I' => '25 - 29 anos Hombres',
            'J' => '25 - 29 anos Mujeres',
            'K' => '30 - 34 anos Hombres',
            'L' => '30 - 34 anos Mujeres',
            'M' => '35 - 39 anos Hombres',
            'N' => '35 - 39 anos Mujeres',
            'O' => '40 - 44 anos Hombres',
            'P' => '40 - 44 anos Mujeres',
            'Q' => '45 - 49 anos Hombres',
            'R' => '45 - 49 anos Mujeres',
            'S' => '50 - 54 anos Hombres',
            'T' => '50 - 54 anos Mujeres',
            'U' => '55 - 59 anos Hombres',
            'V' => '55 - 59 anos Mujeres',
            'W' => '60 - 64 anos Hombres',
            'X' => '60 - 64 anos Mujeres',
            'Y' => '65 - 69 anos Hombres',
            'Z' => '65 - 69 anos Mujeres',
            'AA' => '70 - 74 anos Hombres',
            'AB' => '70 - 74 anos Mujeres',
            'AC' => '75 - 79 anos Hombres',
            'AD' => '75 - 79 anos Mujeres',
            'AE' => '80 y mas anos Hombres',
            'AF' => '80 y mas anos Mujeres',
            'AG' => 'Migrantes',
            'AH' => 'Pueblos Originarios',
        ] as $letter => $label) {
            $fieldsF[] = ['letra' => $letter, 'label' => $label, 'esTotal' => false, 'esControlOculto' => false];
        }

        $fieldsG = [
            ['letra' => 'A', 'label' => 'RANGO ETARIO', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'B', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false],
            ['letra' => 'C', 'label' => 'Normales', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'D', 'label' => 'Inadecuados', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'E', 'label' => 'Atipicos', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'F', 'label' => 'HPV', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'G', 'label' => 'NIE I', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'H', 'label' => 'NIE II', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'I', 'label' => 'NIE III', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'J', 'label' => 'Ca. Inv. Epidermoide', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'K', 'label' => 'Ca. Inv. Adenocarcinoma', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'L', 'label' => 'Total PAP informados en trans masculino', 'esTotal' => false, 'esControlOculto' => false],
        ];

        return RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_patterns_active',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'A01',
                        'sections' => [
                            [
                                'codigo' => 'A',
                                'titulo' => 'CONTROLES DE SALUD SEXUAL Y REPRODUCTIVA',
                                'filaInicioDatos' => 10,
                                'filaFinDatos' => 32,
                                'filaHeader' => 9,
                                'fields' => $fieldsA,
                            ],
                            [
                                'codigo' => 'B',
                                'titulo' => 'CONTROLES DE SALUD SEGÚN CICLO VITAL',
                                'filaInicioDatos' => 35,
                                'filaFinDatos' => 39,
                                'filaHeader' => 34,
                                'fields' => $fieldsB,
                            ],
                            [
                                'codigo' => 'D',
                                'titulo' => 'CONTROLES SEGUN EDAD',
                                'filaInicioDatos' => 69,
                                'filaFinDatos' => 74,
                                'filaHeader' => 68,
                                'fields' => $fieldsD,
                            ],
                            [
                                'codigo' => 'E',
                                'titulo' => 'CONTROLES EN ESTABLECIMIENTOS EDUCACIONALES',
                                'filaInicioDatos' => 77,
                                'filaFinDatos' => 83,
                                'filaHeader' => 76,
                                'fields' => $fieldsE,
                            ],
                            [
                                'codigo' => 'F',
                                'titulo' => 'CONTROLES INTEGRALES DE PERSONAS CON CONDICIONES CRONICAS',
                                'filaInicioDatos' => 86,
                                'filaFinDatos' => 93,
                                'filaHeader' => 85,
                                'fields' => $fieldsF,
                            ],
                            [
                                'codigo' => 'G.',
                                'titulo' => 'PAP TOMADOS E INFORMADOS SEGUN RESULTADO',
                                'filaInicioDatos' => 95,
                                'filaFinDatos' => 112,
                                'filaHeader' => 95,
                                'fields' => $fieldsG,
                            ],
                        ],
                    ],
                ],
            ],
            'status' => 'active',
        ]);
    }

    private function putBCellData(): void
    {
        $cells = [
            'C35' => ['valor_bruto' => 'Ambos Sexos', 'esta_bloqueada' => true],
            'D35' => ['valor_bruto' => 'Hombres', 'esta_bloqueada' => true],
            'E35' => ['valor_bruto' => 'Mujeres', 'esta_bloqueada' => true],
            'F35' => ['valor_bruto' => 'Menos de 1 mes', 'esta_bloqueada' => true],
        ];
        $additionalHeaders = [
            'G' => '1 mes',
            'H' => '2 meses',
            'I' => '3 meses',
            'J' => '4 meses',
            'K' => '5 meses',
            'L' => '6 meses',
            'M' => '7 - 11 meses',
            'N' => '12 - 17 meses',
            'O' => '18 - 23 meses',
            'P' => '24 - 47 meses',
            'Q' => '48 - 59 meses',
            'R' => '60 - 71 meses',
            'S' => '6 - 9 años',
            'T' => '10 - 14 años',
            'U' => '15 - 19 años',
            'V' => '20 - 24 años',
            'W' => '25 - 29 años',
            'X' => '30 - 34 años',
            'Y' => '35 - 39 años',
            'Z' => '40 - 44 años',
            'AA' => '45 - 49 años',
            'AB' => '50 - 54 años',
            'AC' => '55 - 59 años',
            'AD' => '60 - 64 años',
            'AE' => '65 - 69 años',
            'AF' => '70 - 74 años',
            'AG' => '75 - 79 años',
            'AH' => '80 y más años',
            'AI' => 'Menor 1 año',
            'AJ' => '1 año a 3 años 11 meses 29 días',
            'AK' => 'SENAME / Servicio Nacional de Reinserción Social Juvenil',
            'AL' => 'Servicio Nacional Protección Especializada a la Niñez y Adolescencia',
            'AM' => 'Pueblos Originarios',
            'AN' => 'Migrantes',
        ];
        foreach ($additionalHeaders as $letter => $label) {
            $cells["{$letter}35"] = ['valor_bruto' => $label, 'esta_bloqueada' => true];
        }

        foreach ([
            36 => ['De salud ', 'Médico/a'],
            37 => ['', 'Enfermera/o'],
            38 => ['', 'Matrona/ón'],
            39 => ['', 'Técnico en Enfermería'],
        ] as $row => [$concept, $professional]) {
            $cells["A{$row}"] = ['valor_bruto' => $concept, 'esta_bloqueada' => true];
            $cells["B{$row}"] = ['valor_bruto' => $professional, 'esta_bloqueada' => true];
            $cells["C{$row}"] = [
                'valor_bruto' => null,
                'formula' => "=SUM(D{$row}:E{$row})",
                'es_formula' => true,
                'dependencias' => ["D{$row}", "E{$row}"],
                'esta_bloqueada' => true,
                'color_fondo' => ['rgb' => 'FFFFFFFF', 'nombre_inferido' => 'blanco'],
            ];
            $cells["D{$row}"] = ['valor_bruto' => null, 'es_formula' => false, 'esta_bloqueada' => false, 'color_fondo' => ['rgb' => 'FFFFFFCC', 'nombre_inferido' => 'crema']];
            $cells["E{$row}"] = ['valor_bruto' => null, 'es_formula' => false, 'esta_bloqueada' => false, 'color_fondo' => ['rgb' => 'FFFFFFCC', 'nombre_inferido' => 'crema']];
            $cells["F{$row}"] = ['valor_bruto' => null, 'es_formula' => false, 'esta_bloqueada' => false, 'color_fondo' => ['rgb' => 'FFFFFFCC', 'nombre_inferido' => 'crema']];
            foreach (array_keys($additionalHeaders) as $letter) {
                $cells["{$letter}{$row}"] = ['valor_bruto' => null, 'es_formula' => false, 'esta_bloqueada' => false, 'color_fondo' => ['rgb' => 'FFFFFFCC', 'nombre_inferido' => 'crema']];
            }
        }

        Storage::disk('local')->put('certificacion/cell-data/A01-B.json', json_encode($cells));
    }

    private function putDCellData(): void
    {
        $cells = [
            'C68' => ['valor_bruto' => '10 - 14 anos', 'esta_bloqueada' => true],
            'F68' => ['valor_bruto' => '15 - 19 anos', 'esta_bloqueada' => true],
            'I68' => ['valor_bruto' => 'Pueblos Originarios', 'esta_bloqueada' => true],
            'J68' => ['valor_bruto' => 'Migrantes', 'esta_bloqueada' => true],
            'K68' => ['valor_bruto' => 'SENAME / Servicio Nacional de Reinsercion Social Juvenil', 'esta_bloqueada' => true],
            'L68' => ['valor_bruto' => 'Servicio Nacional Proteccion Especializada', 'esta_bloqueada' => true],
            'C69' => ['valor_bruto' => 'Ambos Sexos', 'esta_bloqueada' => true],
            'D69' => ['valor_bruto' => 'Hombres', 'esta_bloqueada' => true],
            'E69' => ['valor_bruto' => 'Mujeres', 'esta_bloqueada' => true],
            'F69' => ['valor_bruto' => 'Ambos Sexos', 'esta_bloqueada' => true],
            'G69' => ['valor_bruto' => 'Hombres', 'esta_bloqueada' => true],
            'H69' => ['valor_bruto' => 'Mujeres', 'esta_bloqueada' => true],
        ];

        foreach ([
            70 => ['De Salud Cardiovascular', 'Medico/a'],
            71 => ['', 'Enfermero/a'],
            72 => ['', 'Nutricionista'],
            73 => ['', 'Tecnico en Enfermeria'],
        ] as $row => [$concept, $professional]) {
            $cells["A{$row}"] = ['valor_bruto' => $concept, 'esta_bloqueada' => true];
            $cells["B{$row}"] = ['valor_bruto' => $professional, 'esta_bloqueada' => true];
            $cells["C{$row}"] = [
                'valor_bruto' => null,
                'formula' => "=SUM(D{$row}:E{$row})",
                'es_formula' => true,
                'dependencias' => ["D{$row}", "E{$row}"],
                'esta_bloqueada' => true,
            ];
            $cells["F{$row}"] = [
                'valor_bruto' => null,
                'formula' => "=SUM(G{$row}:H{$row})",
                'es_formula' => true,
                'dependencias' => ["G{$row}", "H{$row}"],
                'esta_bloqueada' => true,
            ];
            foreach (['D', 'E', 'G', 'H', 'I', 'J', 'K', 'L'] as $letter) {
                $cells["{$letter}{$row}"] = [
                    'valor_bruto' => null,
                    'es_formula' => false,
                    'esta_bloqueada' => false,
                ];
            }
        }

        foreach (['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $letter) {
            $cells["{$letter}74"] = [
                'valor_bruto' => null,
                'formula' => "=SUM({$letter}70:{$letter}73)",
                'es_formula' => true,
                'dependencias' => ["{$letter}70", "{$letter}71", "{$letter}72", "{$letter}73"],
                'esta_bloqueada' => true,
            ];
        }

        Storage::disk('local')->put('certificacion/cell-data/A01-D.json', json_encode($cells));
    }

    private function putECellData(): void
    {
        $cells = [
            'A76' => ['valor_bruto' => 'CURSO', 'esta_bloqueada' => true],
            'B76' => ['valor_bruto' => 'TOTAL', 'esta_bloqueada' => true],
            'C76' => ['valor_bruto' => 'Sexo', 'esta_bloqueada' => true],
            'E76' => ['valor_bruto' => 'Gestion de casos', 'esta_bloqueada' => true],
            'G76' => ['valor_bruto' => 'Planificacion Intervencion educativa', 'esta_bloqueada' => true],
            'J76' => ['valor_bruto' => 'Pueblos Originarios', 'esta_bloqueada' => true],
            'K76' => ['valor_bruto' => 'Migrantes', 'esta_bloqueada' => true],
            'L76' => ['valor_bruto' => 'SENAME / Servicio Nacional de Reinsercion Social Juvenil', 'esta_bloqueada' => true],
            'M76' => ['valor_bruto' => 'Servicio Nacional Proteccion Especializada', 'esta_bloqueada' => true],
            'C77' => ['valor_bruto' => 'Hombres', 'esta_bloqueada' => true],
            'D77' => ['valor_bruto' => 'Mujeres', 'esta_bloqueada' => true],
            'E77' => ['valor_bruto' => 'Total casos gestionados', 'esta_bloqueada' => true],
            'F77' => ['valor_bruto' => 'Casos pesquisados con riesgo', 'esta_bloqueada' => true],
            'G77' => ['valor_bruto' => 'Diagnostico participativo', 'esta_bloqueada' => true],
            'H77' => ['valor_bruto' => 'Numero de problematicas intervenidas', 'esta_bloqueada' => true],
            'I77' => ['valor_bruto' => 'Intervenciones educativas finalizadas', 'esta_bloqueada' => true],
        ];

        foreach ([
            78 => 'Kinder',
            79 => 'Primero basico',
            80 => 'Segundo basico',
            81 => 'Tercero basico',
            82 => 'Cuarto basico',
        ] as $row => $course) {
            $cells["A{$row}"] = ['valor_bruto' => $course, 'esta_bloqueada' => true];
            $cells["B{$row}"] = [
                'valor_bruto' => null,
                'formula' => "=SUM(C{$row}:D{$row})",
                'es_formula' => true,
                'dependencias' => ["C{$row}", "D{$row}"],
                'esta_bloqueada' => true,
            ];
            foreach (['C', 'D', 'E', 'F', 'H', 'I', 'J', 'K', 'L', 'M'] as $letter) {
                $cells["{$letter}{$row}"] = ['valor_bruto' => null, 'es_formula' => false, 'esta_bloqueada' => false];
            }
            $cells["G{$row}"] = ['valor_bruto' => null, 'es_formula' => false, 'esta_bloqueada' => true];
        }

        $cells['A83'] = ['valor_bruto' => 'Total de Establecimientos Educacionales', 'esta_bloqueada' => true];
        foreach (range('B', 'M') as $letter) {
            $cells["{$letter}83"] = ['valor_bruto' => null, 'es_formula' => false, 'esta_bloqueada' => $letter !== 'B' && $letter !== 'G' && $letter !== 'H' && $letter !== 'I'];
        }

        Storage::disk('local')->put('certificacion/cell-data/A01-E.json', json_encode($cells));
    }

    private function putFCellData(): void
    {
        $cells = [
            'A85' => ['valor_bruto' => 'TIPO DE CONTROL', 'esta_bloqueada' => true],
            'B85' => ['valor_bruto' => 'TOTAL', 'esta_bloqueada' => true],
            'E85' => ['valor_bruto' => 'RANGO ETARIO Y SEXO', 'esta_bloqueada' => true],
            'AG85' => ['valor_bruto' => 'Migrantes', 'esta_bloqueada' => true],
            'AH85' => ['valor_bruto' => 'Pueblos Originarios', 'esta_bloqueada' => true],
            'B87' => ['valor_bruto' => 'Ambos Sexos', 'esta_bloqueada' => true],
            'C87' => ['valor_bruto' => 'Hombres', 'esta_bloqueada' => true],
            'D87' => ['valor_bruto' => 'Mujeres', 'esta_bloqueada' => true],
        ];

        $agePairs = [
            ['E', 'F', '15 - 19 anos'],
            ['G', 'H', '20 - 24 anos'],
            ['I', 'J', '25 - 29 anos'],
            ['K', 'L', '30 - 34 anos'],
            ['M', 'N', '35 - 39 anos'],
            ['O', 'P', '40 - 44 anos'],
            ['Q', 'R', '45 - 49 anos'],
            ['S', 'T', '50 - 54 anos'],
            ['U', 'V', '55 - 59 anos'],
            ['W', 'X', '60 - 64 anos'],
            ['Y', 'Z', '65 - 69 anos'],
            ['AA', 'AB', '70 - 74 anos'],
            ['AC', 'AD', '75 - 79 anos'],
            ['AE', 'AF', '80 y mas anos'],
        ];

        foreach ($agePairs as [$menColumn, $womenColumn, $label]) {
            $cells["{$menColumn}86"] = ['valor_bruto' => $label, 'esta_bloqueada' => true];
            $cells["{$menColumn}87"] = ['valor_bruto' => 'Hombres', 'esta_bloqueada' => true];
            $cells["{$womenColumn}87"] = ['valor_bruto' => 'Mujeres', 'esta_bloqueada' => true];
        }

        $menColumns = array_column($agePairs, 0);
        $womenColumns = array_column($agePairs, 1);

        foreach ([
            88 => 'Control integral con riesgo leve (G1)',
            89 => 'Control integral con riesgo moderado (G2)',
            90 => 'Control integral con riesgo alto (G3)',
            91 => 'Seguimiento a distancia con riesgo leve (G1)',
            92 => 'Seguimiento a distancia con riesgo moderado (G2)',
            93 => 'Seguimiento a distancia con riesgo alto (G3)',
        ] as $row => $concept) {
            $cells["A{$row}"] = ['valor_bruto' => $concept, 'esta_bloqueada' => true];
            $cells["B{$row}"] = [
                'valor_bruto' => null,
                'formula' => "=SUM(C{$row}:D{$row})",
                'es_formula' => true,
                'dependencias' => ["C{$row}", "D{$row}"],
                'esta_bloqueada' => true,
            ];
            $cells["C{$row}"] = [
                'valor_bruto' => null,
                'formula' => '=SUM(' . implode('+', array_map(fn($column) => "{$column}{$row}", $menColumns)) . ')',
                'es_formula' => true,
                'dependencias' => array_map(fn($column) => "{$column}{$row}", $menColumns),
                'esta_bloqueada' => true,
            ];
            $cells["D{$row}"] = [
                'valor_bruto' => null,
                'formula' => '=SUM(+' . implode('+', array_map(fn($column) => "{$column}{$row}", $womenColumns)) . ')',
                'es_formula' => true,
                'dependencias' => array_map(fn($column) => "{$column}{$row}", $womenColumns),
                'esta_bloqueada' => true,
            ];
            foreach (array_merge($menColumns, $womenColumns, ['AG', 'AH']) as $letter) {
                $cells["{$letter}{$row}"] = [
                    'valor_bruto' => null,
                    'es_formula' => false,
                    'esta_bloqueada' => false,
                ];
            }
        }

        Storage::disk('local')->put('certificacion/cell-data/A01-F.json', json_encode($cells));
    }

    private function putGCellData(): void
    {
        $cells = [
            'A95' => ['valor_bruto' => 'RANGO ETARIO (en anos)', 'esta_bloqueada' => true],
            'B95' => ['valor_bruto' => 'PAP TOMADOS E INFORMADOS SEGUN RESULTADO', 'esta_bloqueada' => true],
            'L95' => ['valor_bruto' => 'Total PAP informados en trans masculino', 'esta_bloqueada' => true],
            'B96' => ['valor_bruto' => 'TOTAL', 'esta_bloqueada' => true],
            'C96' => ['valor_bruto' => 'Normales', 'esta_bloqueada' => true],
            'D96' => ['valor_bruto' => 'Inadecuados y atipicos', 'esta_bloqueada' => true],
            'F96' => ['valor_bruto' => 'Positivos', 'esta_bloqueada' => true],
            'D97' => ['valor_bruto' => 'Inadecuados', 'esta_bloqueada' => true],
            'E97' => ['valor_bruto' => 'Atipicos', 'esta_bloqueada' => true],
            'F97' => ['valor_bruto' => 'HPV', 'esta_bloqueada' => true],
            'G97' => ['valor_bruto' => 'NIE I', 'esta_bloqueada' => true],
            'H97' => ['valor_bruto' => 'NIE II', 'esta_bloqueada' => true],
            'I97' => ['valor_bruto' => 'NIE III', 'esta_bloqueada' => true],
            'J97' => ['valor_bruto' => 'Ca. Inv. Epidermoide', 'esta_bloqueada' => true],
            'K97' => ['valor_bruto' => 'Ca. Inv. Adenocarcinoma', 'esta_bloqueada' => true],
        ];

        $originColumns = ['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'];
        foreach ([
            98 => 'Menor de 25 anos',
            99 => '25 a 29 anos',
            100 => '30 a 34 anos',
            101 => '35 a 39 anos',
            102 => '40 a 44 anos',
            103 => '45 a 49 anos',
            104 => '50 a 54 anos',
            105 => '55 a 59 anos',
            106 => '60 a 64 anos',
            107 => '65 a 69 anos',
            108 => '70 a 74 anos',
            109 => '75 a 79 anos',
            110 => '80 y mas anos',
        ] as $row => $label) {
            $cells["A{$row}"] = ['valor_bruto' => $label, 'esta_bloqueada' => true];
            $cells["B{$row}"] = [
                'valor_bruto' => null,
                'formula' => "=SUM(C{$row}:K{$row})",
                'es_formula' => true,
                'dependencias' => array_map(fn($column) => "{$column}{$row}", $originColumns),
                'esta_bloqueada' => true,
            ];
            foreach (array_merge($originColumns, ['L']) as $letter) {
                $cells["{$letter}{$row}"] = [
                    'valor_bruto' => null,
                    'es_formula' => false,
                    'dependencias' => [],
                    'esta_bloqueada' => false,
                ];
            }
        }

        $cells['A111'] = ['valor_bruto' => 'TOTAL', 'esta_bloqueada' => true];
        $cells['B111'] = [
            'valor_bruto' => null,
            'formula' => '=SUM(C111:K111)',
            'es_formula' => true,
            'dependencias' => array_map(fn($column) => "{$column}111", $originColumns),
            'esta_bloqueada' => true,
        ];
        foreach (array_merge($originColumns, ['L']) as $letter) {
            $cells["{$letter}111"] = [
                'valor_bruto' => null,
                'formula' => "=SUM({$letter}98:{$letter}110)",
                'es_formula' => true,
                'dependencias' => array_map(fn($row) => "{$letter}{$row}", range(98, 110)),
                'esta_bloqueada' => true,
            ];
        }
        $cells['A112'] = [
            'valor_bruto' => '(*) Fuente de informacion: Citoweb u otro sistema de registro informatico.',
            'esta_bloqueada' => true,
        ];

        Storage::disk('local')->put('certificacion/cell-data/A01-G_.json', json_encode($cells));
    }

    public function test_admin_can_create_calibration_draft(): void
    {
        Sanctum::actingAs($this->admin);

        $structure = $this->createActiveStructure();

        $response = $this->postJson('/api/v1/rule-engine/calibrations');

        $response->assertCreated();
        $response->assertJsonPath('data.hoja', 'A01');
        $response->assertJsonPath('data.serie', 'A');
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.structure_id', $structure->id);
        $response->assertJsonPath('data.created_by', $this->admin->id);
        $response->assertJsonStructure([
            'data' => [
                'id', 'structure_id', 'serie', 'hoja', 'anio',
                'version', 'status', 'progress_pct',
                'total_sections', 'ready_sections',
                'created_by', 'created_at', 'updated_at',
                'structure', 'creator',
            ],
        ]);
    }

    public function test_analyst_can_create_calibration_draft(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->createActiveStructure();

        $response = $this->postJson('/api/v1/rule-engine/calibrations');

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');
    }

    public function test_auditor_cannot_create_calibration(): void
    {
        Sanctum::actingAs($this->auditor);

        $this->createActiveStructure();

        $response = $this->postJson('/api/v1/rule-engine/calibrations');

        $response->assertForbidden();
    }

    public function test_prevents_duplicate_draft_for_same_structure_and_user(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createActiveStructure();

        $this->postJson('/api/v1/rule-engine/calibrations')->assertCreated();

        $response = $this->postJson('/api/v1/rule-engine/calibrations');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.calibration.0', fn($msg) => str_contains($msg, 'Ya existe un borrador'));
    }

    public function test_lists_calibrations(): void
    {
        Sanctum::actingAs($this->admin);

        $structure = $this->createActiveStructure();
        $calibration = Calibration::create([
            'structure_id' => $structure->id,
            'serie' => 'A',
            'hoja' => 'A01',
            'anio' => 2026,
            'version' => 1,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/rule-engine/calibrations');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $calibration->id);
    }

    public function test_analyst_only_sees_own_calibrations(): void
    {
        Sanctum::actingAs($this->admin);

        $structure = $this->createActiveStructure();

        Calibration::create([
            'structure_id' => $structure->id,
            'serie' => 'A',
            'hoja' => 'A01',
            'anio' => 2026,
            'version' => 1,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->analyst);

        $response = $this->getJson('/api/v1/rule-engine/calibrations');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_shows_calibration_detail(): void
    {
        Sanctum::actingAs($this->admin);

        $structure = $this->createActiveStructure();
        $calibration = Calibration::create([
            'structure_id' => $structure->id,
            'serie' => 'A',
            'hoja' => 'A01',
            'anio' => 2026,
            'version' => 1,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson("/api/v1/rule-engine/calibrations/{$calibration->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $calibration->id);
        $response->assertJsonPath('data.structure.id', $structure->id);
    }

    public function test_returns_section_cells(): void
    {
        Sanctum::actingAs($this->admin);

        $structure = $this->createActiveStructure();
        $calibration = Calibration::create([
            'structure_id' => $structure->id,
            'serie' => 'A',
            'hoja' => 'A01',
            'anio' => 2026,
            'version' => 1,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson(
            "/api/v1/rule-engine/calibrations/{$calibration->id}/sections/A/cells"
        );

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'cells',
                'matrix' => [
                    'rows',
                    'summary',
                    'aggregated_rules',
                ],
            ],
        ]);
    }

    public function test_saves_pattern_questions_with_review_metadata(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/rule-engine/catalog/A01/sections/A/pattern-questions', [
            'questions' => [
                [
                    'id' => 'patron_1_empty',
                    'type' => 'pattern_question',
                    'question' => 'Si no existen datos, ¿debe registrarse 0 o puede quedar vacío?',
                    'response' => 'debe_registrar_cero',
                    'observation' => '',
                    'pattern_id' => 1,
                    'pattern_key' => 'pattern_1',
                    'review_status' => 'reviewed',
                    'reviewed_at' => '2026-07-21T12:00:00.000Z',
                    'reviewed_by' => 'Superadmin',
                    'status' => 'answered',
                    'responsible' => 'Estadística APS',
                    'date' => '2026-07-21',
                ],
            ],
        ]);

        $response->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $question = $stored['_questions']['A01_A'][0];

        $this->assertSame('debe_registrar_cero', $question['response']);
        $this->assertSame('reviewed', $question['review_status']);
        $this->assertSame('Superadmin', $question['reviewed_by']);
        $this->assertSame('pattern_1', $question['pattern_key']);
        $this->assertSame('answered', $question['status']);
    }

    public function test_pattern_questions_merge_without_losing_existing_fields_or_legacy_response(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [
                'A01_A' => [
                    [
                        'id' => 'patron_1_empty',
                        'type' => 'pattern_question',
                        'question' => 'Pregunta legacy',
                        'response' => 'texto libre anterior',
                        'observation' => 'Observación previa',
                        'pattern_id' => 1,
                        'review_status' => 'pending',
                        'reviewed_by' => 'Usuario anterior',
                    ],
                ],
            ],
        ]));
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/rule-engine/catalog/A01/sections/A/pattern-questions', [
            'questions' => [
                [
                    'id' => 'patron_1_empty',
                    'type' => 'pattern_question',
                    'question' => 'Pregunta legacy',
                    'response' => 'texto libre anterior',
                    'pattern_id' => 1,
                    'review_status' => 'reviewed',
                    'reviewed_at' => '2026-07-21T12:00:00.000Z',
                ],
            ],
        ]);

        $response->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $question = $stored['_questions']['A01_A'][0];

        $this->assertSame('texto libre anterior', $question['response']);
        $this->assertSame('Observación previa', $question['observation']);
        $this->assertSame('Usuario anterior', $question['reviewed_by']);
        $this->assertSame('reviewed', $question['review_status']);
    }

    public function test_pattern_questions_do_not_persist_unapproved_client_fields(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/rule-engine/catalog/A01/sections/A/pattern-questions', [
            'questions' => [
                [
                    'id' => 'patron_1_empty',
                    'type' => 'pattern_question',
                    'question' => 'Pregunta estructurada',
                    'response' => 'debe_registrar_cero',
                    'pattern_id' => 1,
                    'internal_hash' => 'no_debe_guardarse',
                ],
            ],
        ]);

        $response->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);

        $this->assertArrayNotHasKey('internal_hash', $stored['_questions']['A01_A'][0]);
    }

    public function test_row_functional_decisions_endpoint_returns_effective_decisions_and_inconsistencies(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createPatternStructure();
        Storage::disk('local')->put('certificacion/cell-data/A01-A.json', json_encode([
            'A17' => ['valor_bruto' => 'Post Aborto', 'esta_bloqueada' => true],
            'B17' => ['valor_bruto' => 'Medico/a', 'esta_bloqueada' => true],
            'A18' => ['valor_bruto' => 'Post Aborto', 'esta_bloqueada' => true],
            'B18' => ['valor_bruto' => 'Matrona/on', 'esta_bloqueada' => true],
            'A23' => ['valor_bruto' => 'Recien nacido hasta 10 dias de vida', 'esta_bloqueada' => true],
            'B23' => ['valor_bruto' => 'Medico/a', 'esta_bloqueada' => true],
            'A24' => ['valor_bruto' => 'Recien nacido hasta 10 dias de vida', 'esta_bloqueada' => true],
            'B24' => ['valor_bruto' => 'Enfermero/a', 'esta_bloqueada' => true],
        ]));
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            'A01_A_17' => [
                'sheet' => 'A01',
                'section' => 'A',
                'row' => 17,
                'empty_behavior' => 'puede_quedar_vacio',
                'status' => 'aprobada',
                'updated_by' => 'Analista Estadistica APS',
                'updated_at' => '2026-07-17T19:26:15+00:00',
                'functional_condition' => 'Puede quedar vacia segun criterio de Estadistica',
            ],
            'A01_A_18' => [
                'sheet' => 'A01',
                'section' => 'A',
                'row' => 18,
                'empty_behavior' => 'debe_registrar_cero',
                'status' => 'aprobada',
                'updated_by' => 'Analista Estadistica APS',
                'updated_at' => '2026-07-17T19:26:52+00:00',
                'functional_condition' => 'Debe registrar 0 segun criterio de Estadistica',
            ],
            'A01_A_23' => [
                'sheet' => 'A01',
                'section' => 'A',
                'row' => 23,
                'empty_behavior' => 'debe_registrar_cero',
                'status' => 'aprobada',
                'updated_by' => 'Analista Estadistica APS',
                'updated_at' => '2026-07-17T20:03:11+00:00',
                'functional_condition' => 'Debe registrar 0 segun criterio de Estadistica',
            ],
        ]));

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/A/row-functional-decisions');

        $response->assertOk();
        $rows = collect($response->json('data.rows'))->keyBy('row');

        $this->assertSame('puede_quedar_vacio', $rows[17]['effective_decision']);
        $this->assertSame('row', $rows[17]['origin']);
        $this->assertTrue($rows[17]['possible_inconsistency']);
        $this->assertSame('debe_registrar_cero', $rows[18]['effective_decision']);
        $this->assertTrue($rows[18]['possible_inconsistency']);
        $this->assertSame('debe_registrar_cero', $rows[24]['effective_decision']);
        $this->assertSame('pattern', $rows[24]['origin']);
        $this->assertSame(23, $rows[24]['source_row']);
    }

    public function test_row_functional_decision_can_be_cleared_for_inheritance_with_history(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createPatternStructure();
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            'A01_A_19' => [
                'sheet' => 'A01',
                'section' => 'A',
                'row' => 19,
                'empty_behavior' => 'puede_quedar_vacio',
                'status' => 'aprobada',
            ],
        ]));

        $response = $this->postJson('/api/v1/rule-engine/catalog/A01/sections/A/rows/19/functional-rules', [
            'empty_behavior' => 'heredar_seccion',
            'updated_by' => 'Analista Estadistica APS',
        ]);

        $response->assertOk();
        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);

        $this->assertArrayNotHasKey('A01_A_19', $stored);
        $this->assertSame('clear_for_inheritance', $stored['_row_versions'][0]['change_type']);
        $this->assertSame('heredar_seccion', $stored['_row_versions'][0]['inheritance_mode']);
    }

    public function test_rows_17_and_19_can_be_changed_to_debe_registrar_cero_without_changing_other_rows(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createPatternStructure();
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            'A01_A_17' => [
                'sheet' => 'A01',
                'section' => 'A',
                'row' => 17,
                'empty_behavior' => 'puede_quedar_vacio',
                'status' => 'aprobada',
            ],
            'A01_A_18' => [
                'sheet' => 'A01',
                'section' => 'A',
                'row' => 18,
                'empty_behavior' => 'debe_registrar_cero',
                'status' => 'aprobada',
            ],
            'A01_A_19' => [
                'sheet' => 'A01',
                'section' => 'A',
                'row' => 19,
                'empty_behavior' => 'puede_quedar_vacio',
                'status' => 'aprobada',
            ],
        ]));

        $this->postJson('/api/v1/rule-engine/catalog/A01/sections/A/rows/17/functional-rules', [
            'empty_behavior' => 'debe_registrar_cero',
            'status' => 'aprobada',
            'updated_by' => 'Analista Estadistica APS',
            'functional_condition' => 'Debe registrar 0 segun criterio de Estadistica',
        ])->assertOk();
        $this->postJson('/api/v1/rule-engine/catalog/A01/sections/A/rows/19/functional-rules', [
            'empty_behavior' => 'debe_registrar_cero',
            'status' => 'aprobada',
            'updated_by' => 'Analista Estadistica APS',
            'functional_condition' => 'Debe registrar 0 segun criterio de Estadistica',
        ])->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);

        $this->assertSame('debe_registrar_cero', $stored['A01_A_17']['empty_behavior']);
        $this->assertSame('debe_registrar_cero', $stored['A01_A_19']['empty_behavior']);
        $this->assertSame('debe_registrar_cero', $stored['A01_A_18']['empty_behavior']);
        $this->assertCount(2, $stored['_row_versions']);
    }

    public function test_a01_a_patterns_keep_legacy_compatibility(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createPatternStructure();

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/A/patterns');

        $response->assertOk();
        $response->assertJsonCount(4, 'data.patterns');
        $response->assertJsonPath('data.patterns.0.id', 1);
        $response->assertJsonPath('data.patterns.0.nombre', 'Estándar (F:N)');
        $response->assertJsonPath('data.patterns.0.source', 'legacy_a01_a');

        $response->assertJsonPath('data.columnas_especiales.0', 'U');
        $response->assertJsonPath('data.columnas_especiales.13', 'AH');
        $response->assertJsonPath('data.column_groups.0.key', 'legacy_special_columns');
        $response->assertJsonPath('data.column_groups.0.source', 'legacy_a01_a');

        $patterns = $response->json('data.patterns');
        $this->assertSame(22, array_sum(array_column($patterns, 'cantidad_filas')));
        $this->assertSame([11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22], $patterns[0]['filas']);
    }

    public function test_a01_b_patterns_are_dynamic_and_do_not_reuse_section_a(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createPatternStructure();
        $this->putBCellData();

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/B/patterns');

        $response->assertOk();
        $response->assertJsonPath('data.section.codigo', 'B');
        $response->assertJsonPath('data.rows.0.row', 35);
        $response->assertJsonPath('data.rows.4.row', 39);

        $patterns = $response->json('data.patterns');
        $names = implode(' ', array_column($patterns, 'nombre'));
        $allPatternRows = array_merge(...array_map(fn($pattern) => $pattern['filas'], $patterns));

        $this->assertStringNotContainsString('Estándar (F:N)', $names);
        $this->assertStringNotContainsString('RN conteo directo', $names);
        $this->assertStringNotContainsString('Climaterio', $names);
        $this->assertEmpty(array_intersect(range(11, 32), $allPatternRows));
        $this->assertSame([36, 37, 38, 39], array_values(array_unique($allPatternRows)));
        $this->assertGreaterThan(0, array_sum(array_column($patterns, 'cantidad_filas')));
        $this->assertSame(['C'], $patterns[0]['total_columns']);
        $this->assertSame(['D', 'E'], $patterns[0]['origin_columns']);
        $this->assertSame('cell_data', $patterns[0]['source']);
        $this->assertContains('De salud ', $patterns[0]['conceptos']);
        $this->assertContains('Médico/a', $patterns[0]['profesionales']);
        $this->assertSame('=SUM(D{fila}:E{fila})', $patterns[0]['formula_templates']['C']);
        $this->assertSame([], $response->json('data.columnas_especiales'));
        $this->assertSame('main_rule', $response->json('data.column_groups.0.key'));
        $this->assertSame('C', $response->json('data.column_groups.0.total'));
        $this->assertSame(['D', 'E'], $response->json('data.column_groups.0.components'));
        $this->assertSame('C = D + E', $response->json('data.column_groups.0.formula'));
        $this->assertSame('age_range', $response->json('data.column_groups.1.key'));
        $this->assertSame('F', $response->json('data.column_groups.1.start_column'));
        $this->assertSame('AJ', $response->json('data.column_groups.1.end_column'));
        $this->assertSame('complementary', $response->json('data.column_groups.2.key'));
        $this->assertSame('AK', $response->json('data.column_groups.2.start_column'));
        $this->assertSame('AN', $response->json('data.column_groups.2.end_column'));
        $this->assertSame('Migrantes', $response->json('data.column_groups.2.columns.3.label'));
    }

    public function test_a01_b_row_decisions_inherit_from_reviewed_pattern_questions(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createPatternStructure();
        $this->putBCellData();
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [
                'A01_B' => [
                    [
                        'id' => 'pattern_1_empty',
                        'type' => 'pattern_question',
                        'response' => 'debe_registrar_cero',
                        'pattern_id' => 1,
                        'pattern_key' => 'pattern_1',
                        'review_status' => 'reviewed',
                        'reviewed_by' => 'Administrador Esalud',
                        'reviewed_at' => '2026-07-22T13:01:42.752Z',
                        'status' => 'answered',
                    ],
                    [
                        'id' => 'pattern_1_exceptions',
                        'type' => 'pattern_question',
                        'response' => 'no',
                        'pattern_id' => 1,
                        'pattern_key' => 'pattern_1',
                        'review_status' => 'reviewed',
                        'status' => 'answered',
                    ],
                    [
                        'id' => 'pattern_2_empty',
                        'type' => 'pattern_question',
                        'response' => 'debe_registrar_cero',
                        'pattern_id' => 2,
                        'pattern_key' => 'pattern_2',
                        'review_status' => 'reviewed',
                        'reviewed_by' => 'Administrador Esalud',
                        'reviewed_at' => '2026-07-22T13:01:40.705Z',
                        'status' => 'answered',
                    ],
                    [
                        'id' => 'pattern_3_empty',
                        'type' => 'pattern_question',
                        'response' => 'debe_registrar_cero',
                        'pattern_id' => 3,
                        'pattern_key' => 'pattern_3',
                        'review_status' => 'reviewed',
                        'reviewed_by' => 'Administrador Esalud',
                        'reviewed_at' => '2026-07-22T13:01:37.282Z',
                        'status' => 'answered',
                    ],
                ],
            ],
        ]));

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/B/row-functional-decisions');

        $response->assertOk();
        $rows = collect($response->json('data.rows'))->keyBy('row');

        $this->assertSame('debe_registrar_cero', $rows[36]['effective_decision']);
        $this->assertSame('pattern', $rows[36]['origin']);
        $this->assertSame('pattern_1', $rows[36]['source_pattern']);
        $this->assertSame('debe_registrar_cero', $rows[37]['effective_decision']);
        $this->assertSame('pattern_1', $rows[37]['source_pattern']);
        $this->assertSame('debe_registrar_cero', $rows[38]['effective_decision']);
        $this->assertSame('pattern_1', $rows[38]['source_pattern']);
        $this->assertSame('debe_registrar_cero', $rows[39]['effective_decision']);
        $this->assertSame('pattern_1', $rows[39]['source_pattern']);
        $this->assertSame('Administrador Esalud', $rows[39]['reviewed_by']);
        $this->assertFalse($rows[39]['has_explicit_decision']);
    }

    public function test_a01_d_detects_multiple_sex_rules_without_treating_complementary_columns_as_totals(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createPatternStructure();
        $this->putDCellData();

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/D/patterns');

        $response->assertOk();
        $response->assertJsonPath('data.section.codigo', 'D');
        $response->assertJsonPath('data.column_groups.0.key', 'main_rule');
        $response->assertJsonPath('data.column_groups.0.total', 'C');
        $response->assertJsonPath('data.column_groups.0.components', ['D', 'E']);
        $response->assertJsonPath('data.column_groups.0.formula', 'C = D + E');
        $response->assertJsonPath('data.column_groups.1.key', 'main_rule_2');
        $response->assertJsonPath('data.column_groups.1.total', 'F');
        $response->assertJsonPath('data.column_groups.1.components', ['G', 'H']);
        $response->assertJsonPath('data.column_groups.1.formula', 'F = G + H');
        $response->assertJsonPath('data.column_groups.2.key', 'complementary');
        $response->assertJsonPath('data.column_groups.2.start_column', 'I');
        $response->assertJsonPath('data.column_groups.2.end_column', 'L');

        $patterns = $response->json('data.patterns');
        $allPatternRows = array_merge(...array_map(fn($pattern) => $pattern['filas'], $patterns));

        $this->assertSame([70, 71, 72, 73], array_values(array_unique($allPatternRows)));
        $this->assertSame(['C', 'F'], $patterns[0]['total_columns']);
        $this->assertSame(['D', 'E', 'G', 'H'], $patterns[0]['origin_columns']);
        $this->assertNotContains('I', $patterns[0]['total_columns']);
        $this->assertSame('C = D + E', $patterns[0]['rows'][0]['functional_rules'][0]['label']);
        $this->assertSame(['D', 'E'], $patterns[0]['rows'][0]['functional_rules'][0]['origin_columns']);
        $this->assertSame('F = G + H', $patterns[0]['rows'][0]['functional_rules'][1]['label']);
        $this->assertSame(['G', 'H'], $patterns[0]['rows'][0]['functional_rules'][1]['origin_columns']);
        $this->assertNotContains('I', $patterns[0]['rows'][0]['functional_rules'][0]['origin_columns']);
        $this->assertNotContains('I', $patterns[0]['rows'][0]['functional_rules'][1]['origin_columns']);

        $matrixResponse = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/D/matrix');

        $matrixResponse->assertOk();
        $matrixRows = $matrixResponse->json('data.rows');
        $this->assertSame([69, 70, 71, 72, 73], array_column($matrixRows, 'row'));
        $this->assertSame('header', $matrixRows[0]['row_type']);
        $this->assertSame('data', $matrixRows[1]['row_type']);
        $this->assertSame('C = D + E', $matrixRows[1]['functional_rules'][0]['label']);
        $this->assertSame(['D', 'E'], $matrixRows[1]['functional_rules'][0]['origin_columns']);
        $this->assertSame('F = G + H', $matrixRows[1]['functional_rules'][1]['label']);
        $this->assertSame(['G', 'H'], $matrixRows[1]['functional_rules'][1]['origin_columns']);
        $this->assertNotContains('I', $matrixRows[1]['columnas_sumadas']);
        $this->assertNotContains(74, array_column($matrixRows, 'row'));
    }

    public function test_a01_e_detects_course_pattern_and_separates_special_total_row(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createPatternStructure();
        $this->putECellData();

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/E/patterns');

        $response->assertOk();
        $response->assertJsonPath('data.warnings', []);
        $response->assertJsonPath('data.column_groups.0.key', 'main_rule');
        $response->assertJsonPath('data.column_groups.0.start_column', 'B');
        $response->assertJsonPath('data.column_groups.0.end_column', 'D');
        $response->assertJsonPath('data.column_groups.0.total', 'B');
        $response->assertJsonPath('data.column_groups.0.components', ['C', 'D']);
        $response->assertJsonPath('data.column_groups.0.formula', 'B = C + D');
        $response->assertJsonPath('data.column_groups.1.key', 'complementary');
        $response->assertJsonPath('data.column_groups.1.start_column', 'E');
        $response->assertJsonPath('data.column_groups.1.end_column', 'M');

        $patterns = $response->json('data.patterns');
        $this->assertCount(1, $patterns);
        $this->assertSame([78, 79, 80, 81, 82], $patterns[0]['filas']);
        $this->assertSame(['B'], $patterns[0]['total_columns']);
        $this->assertSame(['C', 'D'], $patterns[0]['origin_columns']);
        $this->assertNotContains(77, $patterns[0]['filas']);
        $this->assertNotContains(83, $patterns[0]['filas']);

        $matrixResponse = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/E/matrix');
        $matrixResponse->assertOk();

        $matrixRows = $matrixResponse->json('data.rows');
        $this->assertSame([77, 78, 79, 80, 81, 82, 83], array_column($matrixRows, 'row'));
        $this->assertSame('header', $matrixRows[0]['row_type']);
        $this->assertSame('data', $matrixRows[1]['row_type']);
        $this->assertSame('special', $matrixRows[6]['row_type']);
        $this->assertSame(5, $matrixResponse->json('data.summary.total_filas_datos'));
        $this->assertSame(1, $matrixResponse->json('data.summary.total_subtotales'));
        $this->assertSame('B = C + D', $matrixRows[1]['functional_rules'][0]['label']);
        $this->assertSame(['C', 'D'], $matrixRows[1]['functional_rules'][0]['origin_columns']);
        $this->assertSame([], $matrixRows[6]['functional_rules']);
        $this->assertNull($matrixRows[6]['rule_key']);
        $this->assertNull($matrixRows[6]['aggregated_rule_key']);
        $this->assertNull($matrixRows[6]['destino_funcional']);
        $this->assertNull($matrixRows[6]['descripcion_funcional_origen']);
        $this->assertNull($matrixRows[6]['regla_funcional_label']);
    }

    public function test_a01_f_exposes_three_horizontal_rules_and_keeps_age_range_grouped_by_sex(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createPatternStructure();
        $this->putFCellData();

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/F/patterns');

        $response->assertOk();
        $response->assertJsonPath('data.warnings', []);
        $response->assertJsonPath('data.patterns.0.source', 'cell_data');
        $response->assertJsonPath('data.patterns.0.filas', [88, 89, 90, 91, 92, 93]);
        $response->assertJsonPath('data.patterns.0.cantidad_filas', 6);
        $response->assertJsonPath('data.patterns.0.total_columns', ['B', 'C', 'D']);
        $response->assertJsonPath('data.patterns.0.formula_templates.B', '=SUM(C{fila}:D{fila})');

        $patterns = $response->json('data.patterns');
        $rowRules = $patterns[0]['rows'][0]['functional_rules'];
        $this->assertCount(3, $rowRules);
        $this->assertSame('B = C + D', $rowRules[0]['label']);
        $this->assertSame(['C', 'D'], $rowRules[0]['origin_columns']);
        $this->assertSame('C', $rowRules[1]['total_column']);
        $this->assertSame(['E', 'G', 'I', 'K', 'M', 'O', 'Q', 'S', 'U', 'W', 'Y', 'AA', 'AC', 'AE'], $rowRules[1]['origin_columns']);
        $this->assertSame('D', $rowRules[2]['total_column']);
        $this->assertSame(['F', 'H', 'J', 'L', 'N', 'P', 'R', 'T', 'V', 'X', 'Z', 'AB', 'AD', 'AF'], $rowRules[2]['origin_columns']);

        $this->assertSame('main_rule', $response->json('data.column_groups.0.key'));
        $this->assertSame('B', $response->json('data.column_groups.0.total'));
        $this->assertSame(['C', 'D'], $response->json('data.column_groups.0.components'));
        $this->assertSame('age_range', $response->json('data.column_groups.1.key'));
        $this->assertSame('E', $response->json('data.column_groups.1.start_column'));
        $this->assertSame('AF', $response->json('data.column_groups.1.end_column'));
        $this->assertSame(['E', 'G', 'I', 'K', 'M', 'O', 'Q', 'S', 'U', 'W', 'Y', 'AA', 'AC', 'AE'], array_column($response->json('data.column_groups.1.subgroups.0.columns'), 'letter'));
        $this->assertSame(['F', 'H', 'J', 'L', 'N', 'P', 'R', 'T', 'V', 'X', 'Z', 'AB', 'AD', 'AF'], array_column($response->json('data.column_groups.1.subgroups.1.columns'), 'letter'));
        $this->assertSame('complementary', $response->json('data.column_groups.2.key'));
        $this->assertSame('AG', $response->json('data.column_groups.2.start_column'));
        $this->assertSame('AH', $response->json('data.column_groups.2.end_column'));

        $matrixResponse = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/F/matrix');
        $matrixResponse->assertOk();
        $matrixRows = $matrixResponse->json('data.rows');
        $this->assertSame([86, 87, 88, 89, 90, 91, 92, 93], array_column($matrixRows, 'row'));
        $this->assertSame('data', $matrixRows[2]['row_type']);
        $this->assertCount(3, $matrixRows[2]['functional_rules']);
        $this->assertSame('B = C + D', $matrixRows[2]['functional_rules'][0]['label']);
        $this->assertSame('C', $matrixRows[2]['functional_rules'][1]['total_column']);
        $this->assertSame('D', $matrixRows[2]['functional_rules'][2]['total_column']);
        $this->assertFalse($matrixRows[2]['es_excepcion']);
        $this->assertFalse($matrixRows[7]['es_excepcion']);
    }

    public function test_a01_g_uses_cell_data_to_build_repeated_pattern_and_excludes_headers_and_vertical_total(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createPatternStructure();
        $this->putGCellData();

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/G./patterns');

        $response->assertOk();
        $response->assertJsonPath('data.warnings', []);
        $response->assertJsonPath('data.patterns.0.source', 'cell_data');
        $response->assertJsonPath('data.patterns.0.filas', [98, 99, 100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110]);
        $response->assertJsonPath('data.patterns.0.cantidad_filas', 13);
        $response->assertJsonPath('data.patterns.0.total_columns', ['B']);
        $response->assertJsonPath('data.patterns.0.origin_columns', ['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K']);
        $response->assertJsonPath('data.patterns.0.formula_templates.B', '=SUM(C{fila}:K{fila})');

        $patterns = $response->json('data.patterns');
        $this->assertCount(1, $patterns);
        $this->assertNotContains(95, $patterns[0]['filas']);
        $this->assertNotContains(96, $patterns[0]['filas']);
        $this->assertNotContains(97, $patterns[0]['filas']);
        $this->assertNotContains(111, $patterns[0]['filas']);
        $this->assertNotContains(112, $patterns[0]['filas']);
        $this->assertSame('Menor de 25 anos', $patterns[0]['conceptos'][0]);
        $this->assertSame('80 y mas anos', $patterns[0]['conceptos'][12]);

        $rowRules = $patterns[0]['rows'][0]['functional_rules'];
        $this->assertCount(1, $rowRules);
        $this->assertSame('B = C + D + E + F + G + H + I + J + K', $rowRules[0]['label']);
        $this->assertSame('=SUM(C98:K98)', $rowRules[0]['formula_exacta']);

        $this->assertSame('main_rule', $response->json('data.column_groups.0.key'));
        $this->assertSame('B', $response->json('data.column_groups.0.total'));
        $this->assertSame(['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'], $response->json('data.column_groups.0.components'));
        $this->assertSame('complementary', $response->json('data.column_groups.1.key'));
        $this->assertSame('L', $response->json('data.column_groups.1.start_column'));
        $this->assertSame('L', $response->json('data.column_groups.1.end_column'));

        $matrixResponse = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/G./matrix');
        $matrixResponse->assertOk();
        $matrixRows = $matrixResponse->json('data.rows');
        $this->assertSame([95, 96, 97, 98, 99, 100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 112], array_column($matrixRows, 'row'));
        $this->assertSame('header', $matrixRows[0]['row_type']);
        $this->assertSame('header', $matrixRows[1]['row_type']);
        $this->assertSame('header', $matrixRows[2]['row_type']);
        $this->assertSame('data', $matrixRows[3]['row_type']);
        $this->assertCount(1, $matrixRows[3]['functional_rules']);
        $this->assertSame('B', $matrixRows[3]['functional_rules'][0]['total_column']);
        $this->assertFalse($matrixRows[3]['es_excepcion']);
    }

    public function test_a01_b_without_cell_data_returns_structure_inferred_warning(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createPatternStructure();

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/B/patterns');

        $response->assertOk();
        $response->assertJsonPath('data.patterns.0.source', 'structure_inferred');
        $response->assertJsonPath('data.patterns.0.total_columns', ['C', 'D', 'E']);
        $response->assertJsonPath('data.column_groups.0.source', 'structure_inferred');
        $response->assertJsonPath('data.column_groups.1.start_column', 'F');
        $response->assertJsonPath('data.column_groups.1.end_column', 'AJ');
        $response->assertJsonPath('data.column_groups.2.start_column', 'AK');
        $response->assertJsonPath('data.column_groups.2.end_column', 'AN');
        $response->assertJsonPath('data.warnings.0', 'No existe evidencia de celdas escaneadas para esta sección. Los patrones mostrados son preliminares y no pueden certificarse todavía.');

        $names = implode(' ', array_column($response->json('data.patterns'), 'nombre'));
        $this->assertStringNotContainsString('Estándar (F:N)', $names);
    }

    public function test_saves_cell_configuration(): void
    {
        Sanctum::actingAs($this->admin);

        $structure = $this->createActiveStructure();
        $calibration = Calibration::create([
            'structure_id' => $structure->id,
            'serie' => 'A',
            'hoja' => 'A01',
            'anio' => 2026,
            'version' => 1,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->putJson(
            "/api/v1/rule-engine/calibrations/{$calibration->id}/cells",
            [
                'section' => 'A',
                'coordinate' => 'C11',
                'row' => 11,
                'column_letter' => 'C',
                'behavior' => 'required_numeric',
                'formula' => '=SUM(F11:N11)',
                'formula_status' => 'correct',
                'justification' => 'Suma de rango etario',
                'source' => 'manual',
            ]
        );

        $response->assertOk();
        $response->assertJsonPath('data.section', 'A');
        $response->assertJsonPath('data.coordinate', 'C11');
        $response->assertJsonPath('data.behavior', 'required_numeric');
        $response->assertJsonPath('data.formula', '=SUM(F11:N11)');
        $response->assertJsonPath('data.formula_status', 'correct');
        $response->assertJsonPath('data.created_by', $this->admin->id);
    }

    public function test_updates_existing_cell_configuration(): void
    {
        Sanctum::actingAs($this->admin);

        $structure = $this->createActiveStructure();
        $calibration = Calibration::create([
            'structure_id' => $structure->id,
            'serie' => 'A',
            'hoja' => 'A01',
            'anio' => 2026,
            'version' => 1,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        CalibrationCell::create([
            'calibration_id' => $calibration->id,
            'section' => 'A',
            'coordinate' => 'C11',
            'behavior' => 'required',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->putJson(
            "/api/v1/rule-engine/calibrations/{$calibration->id}/cells",
            [
                'section' => 'A',
                'coordinate' => 'C11',
                'behavior' => 'calculated',
                'formula' => '=SUM(F11:N11)',
                'formula_status' => 'correct',
            ]
        );

        $response->assertOk();
        $response->assertJsonPath('data.behavior', 'calculated');
        $this->assertEquals(1, $calibration->cells()->where('coordinate', 'C11')->count());
    }

    public function test_saves_cell_with_rule_correction(): void
    {
        Sanctum::actingAs($this->admin);

        $structure = $this->createActiveStructure();
        $calibration = Calibration::create([
            'structure_id' => $structure->id,
            'serie' => 'A',
            'hoja' => 'A01',
            'anio' => 2026,
            'version' => 1,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $rule = \App\Domain\RuleEngine\Models\Rule::create([
            'rule_key' => 'a01_a_c_sum_equals',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Test',
            'config' => ['source_letters' => ['A', 'B'], 'target_column' => 'C'],
            'status' => 'active',
            'version' => '1.0.0',
        ]);

        $response = $this->putJson(
            "/api/v1/rule-engine/calibrations/{$calibration->id}/cells",
            [
                'section' => 'A',
                'coordinate' => 'C11',
                'behavior' => 'calculated',
                'rule_correction' => [
                    'rule_id' => $rule->id,
                    'target_original' => 'W',
                    'target_corrected' => 'V',
                    'reason' => 'W está bloqueada',
                ],
            ]
        );

        $response->assertOk();
        $response->assertJsonPath('data.rule_correction.rule_id', $rule->id);
        $response->assertJsonPath('data.rule_correction.target_corrected', 'V');
    }

    public function test_rejects_invalid_behavior(): void
    {
        Sanctum::actingAs($this->admin);

        $structure = $this->createActiveStructure();
        $calibration = Calibration::create([
            'structure_id' => $structure->id,
            'serie' => 'A',
            'hoja' => 'A01',
            'anio' => 2026,
            'version' => 1,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->putJson(
            "/api/v1/rule-engine/calibrations/{$calibration->id}/cells",
            [
                'section' => 'A',
                'coordinate' => 'C11',
                'behavior' => 'invalid_behavior',
            ]
        );

        $response->assertStatus(422);
    }

    public function test_rejects_save_on_nonexistent_rule_for_correction(): void
    {
        Sanctum::actingAs($this->admin);

        $structure = $this->createActiveStructure();
        $calibration = Calibration::create([
            'structure_id' => $structure->id,
            'serie' => 'A',
            'hoja' => 'A01',
            'anio' => 2026,
            'version' => 1,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->putJson(
            "/api/v1/rule-engine/calibrations/{$calibration->id}/cells",
            [
                'section' => 'A',
                'coordinate' => 'C11',
                'behavior' => 'calculated',
                'rule_correction' => [
                    'rule_id' => 99999,
                    'target_original' => 'W',
                    'target_corrected' => 'V',
                ],
            ]
        );

        $response->assertStatus(422);
    }

    public function test_activity_log_is_created_on_calibration_creation(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createActiveStructure();

        $calibration = $this->postJson('/api/v1/rule-engine/calibrations')->json('data');

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Calibration::class,
            'subject_id' => $calibration['id'],
            'causer_id' => $this->admin->id,
            'description' => 'calibration_created',
        ]);
    }

    public function test_activity_log_is_created_on_cell_save(): void
    {
        Sanctum::actingAs($this->admin);

        $structure = $this->createActiveStructure();
        $calibration = Calibration::create([
            'structure_id' => $structure->id,
            'serie' => 'A',
            'hoja' => 'A01',
            'anio' => 2026,
            'version' => 1,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $this->putJson(
            "/api/v1/rule-engine/calibrations/{$calibration->id}/cells",
            [
                'section' => 'A',
                'coordinate' => 'C15',
                'behavior' => 'blocked',
            ]
        );

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => CalibrationCell::class,
            'description' => 'calibration_cell_saved',
        ]);
    }
}
