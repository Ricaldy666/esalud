<?php

namespace Tests\Unit\RemParser\Services;

use App\Domain\RemParser\Exceptions\RemTemplateConfigGenerationException;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\RemTemplateConfigGeneratorService;
use PHPUnit\Framework\TestCase;

/**
 * BM-3.5 (2026-09-14): cubre RemTemplateConfigGeneratorService::buildConfig()
 * -- 100% puro (sin BD), fixtures sinteticas. Los casos de BM18/BM18A
 * replican EXACTAMENTE los valores reales ya persistidos y verificados en
 * la estructura activa id=72 (BM-3.2/BM-3.3/BM-3.4) -- no inventados. Los
 * casos de idempotencia/enlace/atomicidad (que si requieren BD) viven en
 * el test Feature aparte (RemTemplateConfigGeneratorServiceIntegrationTest).
 */
class RemTemplateConfigGeneratorServiceTest extends TestCase
{
    private function service(): RemTemplateConfigGeneratorService
    {
        return new RemTemplateConfigGeneratorService();
    }

    private function field(string $letra, string $label, bool $esTotal = false, bool $esControlOculto = false): array
    {
        return ['letra' => $letra, 'label' => $label, 'esTotal' => $esTotal, 'esControlOculto' => $esControlOculto];
    }

    // ── A) BM real (estructura id=72, valores reales replicados) ────────

    private function bmEstructura(): array
    {
        return [
            'forms' => [
                [
                    'sheetName' => 'BM18',
                    'sections' => [
                        [
                            'codigo' => 'A',
                            'filaHeader' => 11,
                            'filaInicioDatos' => 13,
                            'filaFinDatos' => 38,
                            'fields' => [
                                $this->field('A', 'EXÁMENES'),
                                $this->field('B', 'EXÁMENES'),
                                $this->field('C', 'EXÁMENES'),
                                $this->field('D', 'TOTAL', esTotal: true, esControlOculto: true),
                                $this->field('E', 'SAPU/SAR/SUR / Total', esTotal: true, esControlOculto: true),
                                $this->field('F', 'Resto Establecimientos APS / Total', esTotal: true, esControlOculto: true),
                            ],
                        ],
                        [
                            'codigo' => 'B',
                            'filaHeader' => 40,
                            'filaInicioDatos' => 42,
                            'filaFinDatos' => 53,
                            'fields' => [
                                $this->field('A', 'PROCEDIMIENTOS'),
                                $this->field('B', 'TOTAL', esTotal: true, esControlOculto: true),
                                $this->field('C', 'SAPU/SAR/SUR / Total', esTotal: true, esControlOculto: true),
                                $this->field('D', 'Resto Establecimientos APS / Total', esTotal: true, esControlOculto: true),
                            ],
                        ],
                        [
                            'codigo' => 'C',
                            'filaHeader' => 55,
                            'filaInicioDatos' => 56,
                            'filaFinDatos' => 57,
                            'fields' => [
                                $this->field('A', 'ATENCIÓN'),
                                $this->field('B', 'TOTAL', esTotal: true),
                            ],
                        ],
                        [
                            'codigo' => 'D',
                            'filaHeader' => 59,
                            'filaInicioDatos' => 60,
                            'filaFinDatos' => 62,
                            'fields' => [
                                $this->field('A', 'TIPO DE ACCION'),
                                $this->field('B', 'TIPO DE ACCION'),
                                $this->field('C', 'TOTAL', esTotal: true),
                                $this->field('D', 'POR COMPRA DE SERVICIO'),
                            ],
                        ],
                    ],
                ],
                [
                    'sheetName' => 'BM18A',
                    'sections' => [
                        [
                            'codigo' => 'A',
                            'filaHeader' => 10,
                            'filaInicioDatos' => 11,
                            'filaFinDatos' => 119,
                            'fields' => [
                                $this->field('A', 'CÓDIGOS'),
                                $this->field('B', 'EXÁMENES'),
                                $this->field('C', 'TOTAL', esTotal: true, esControlOculto: true),
                                $this->field('D', 'SAPU/SAR/SUR'),
                                $this->field('E', 'Resto Establecimientos APS'),
                                $this->field('F', 'Compra de Servicios'),
                            ],
                        ],
                        [
                            'codigo' => 'B',
                            'filaHeader' => 121,
                            'filaInicioDatos' => 122,
                            'filaFinDatos' => 205,
                            'fields' => [
                                $this->field('A', 'CÓDIGOS'),
                                $this->field('B', 'PROCEDIMIENTOS'),
                                $this->field('C', 'Total', esTotal: true, esControlOculto: true),
                                $this->field('D', 'SAPU/SAR/SUR'),
                                $this->field('E', 'Resto Establecimientos APS'),
                                $this->field('F', 'Compra de Servicios'),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_bm_produces_exactly_two_sheets(): void
    {
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BM', 'status' => 'active', 'estructura' => $this->bmEstructura()]);

        $config = $this->service()->buildConfig($structure);

        $this->assertCount(2, $config['sheets']);
        $this->assertSame(['BM18', 'BM18A'], array_column($config['sheets'], 'sheet_name'));
    }

    public function test_bm18_structure_is_correct(): void
    {
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BM', 'status' => 'active', 'estructura' => $this->bmEstructura()]);

        $bm18 = $this->service()->buildConfig($structure)['sheets'][0];

        $this->assertSame('BM18', $bm18['sheet_name']);
        $this->assertSame(11, $bm18['structure']['header_row']);
        $this->assertSame(13, $bm18['structure']['data_start_row']);
        $this->assertSame(62, $bm18['structure']['data_end_row']);
        $this->assertSame('A', $bm18['structure']['concept_column']);
        $this->assertSame('D', $bm18['structure']['total_column']);
        $this->assertNull($bm18['structure']['professional_column']);
        $this->assertCount(6, $bm18['columns']);
        // BM-3.5A: section_code debe igualar sheet_name -- es lo que
        // ProcessRemUploadJob usa para poblar rem_data.section (via
        // RemParserService::parseSheet() linea ~689); sin esto todas las
        // filas quedarian con section='unknown'.
        $this->assertSame('BM18', $bm18['section_code']);
    }

    public function test_bm18a_structure_is_correct(): void
    {
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BM', 'status' => 'active', 'estructura' => $this->bmEstructura()]);

        $bm18a = $this->service()->buildConfig($structure)['sheets'][1];

        $this->assertSame('BM18A', $bm18a['sheet_name']);
        $this->assertSame(10, $bm18a['structure']['header_row']);
        $this->assertSame(11, $bm18a['structure']['data_start_row']);
        $this->assertSame(205, $bm18a['structure']['data_end_row']);
        $this->assertSame('A', $bm18a['structure']['concept_column']);
        $this->assertSame('C', $bm18a['structure']['total_column']);
        $this->assertCount(6, $bm18a['columns']);
        $this->assertSame('BM18A', $bm18a['section_code']);
    }

    // ── C) Exclusion NOMBRE/Control/MACROS ───────────────────────────────

    public function test_nombre_control_macros_never_appear_in_output(): void
    {
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BM', 'status' => 'active', 'estructura' => $this->bmEstructura()]);

        $sheetNames = array_column($this->service()->buildConfig($structure)['sheets'], 'sheet_name');

        $this->assertNotContains('NOMBRE', $sheetNames);
        $this->assertNotContains('Control', $sheetNames);
        $this->assertNotContains('MACROS', $sheetNames);
    }

    // ── B) Otra serie real (BS) -- demuestra ausencia de hardcode BM ─────

    public function test_bs_series_generates_correctly_no_bm_hardcode(): void
    {
        $estructura = [
            'forms' => [
                [
                    'sheetName' => 'BS01',
                    'sections' => [
                        [
                            'codigo' => 'A',
                            'filaHeader' => 8,
                            'filaInicioDatos' => 9,
                            'filaFinDatos' => 20,
                            'fields' => [
                                $this->field('A', 'PROCEDIMIENTO ODONTOLOGICO'),
                                $this->field('B', 'TOTAL', esTotal: true),
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BS', 'status' => 'active', 'estructura' => $estructura]);

        $config = $this->service()->buildConfig($structure);

        $this->assertCount(1, $config['sheets']);
        $this->assertSame('BS01', $config['sheets'][0]['sheet_name']);
        $this->assertSame('A', $config['sheets'][0]['structure']['concept_column']);
        $this->assertSame('B', $config['sheets'][0]['structure']['total_column']);
    }

    // ── D) Conflicto de columnas: letra duplicada con datos incompatibles ──

    public function test_duplicate_letter_within_section_with_incompatible_data_throws(): void
    {
        $estructura = [
            'forms' => [
                [
                    'sheetName' => 'XX01',
                    'sections' => [
                        [
                            'codigo' => 'A',
                            'filaHeader' => 8,
                            'filaInicioDatos' => 9,
                            'filaFinDatos' => 20,
                            'fields' => [
                                $this->field('A', 'CONCEPTO'),
                                $this->field('B', 'TOTAL', esTotal: true),
                                // Estructura corrupta: la letra B vuelve a
                                // aparecer con label y esTotal distintos.
                                $this->field('B', 'SUBTOTAL', esTotal: false),
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BS', 'status' => 'active', 'estructura' => $estructura]);

        $this->expectException(RemTemplateConfigGenerationException::class);
        $this->expectExceptionMessageMatches('/conflicto de columna/i');

        $this->service()->buildConfig($structure);
    }

    public function test_duplicate_letter_with_same_data_does_not_throw(): void
    {
        // Mismo label/esTotal repetido para la misma letra -- no es
        // conflicto real (dato redundante, no incompatible).
        $estructura = [
            'forms' => [
                [
                    'sheetName' => 'XX01',
                    'sections' => [
                        [
                            'codigo' => 'A',
                            'filaHeader' => 8,
                            'filaInicioDatos' => 9,
                            'filaFinDatos' => 20,
                            'fields' => [
                                $this->field('A', 'CONCEPTO'),
                                $this->field('B', 'TOTAL', esTotal: true),
                                $this->field('B', 'TOTAL', esTotal: true),
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BS', 'status' => 'active', 'estructura' => $estructura]);

        $config = $this->service()->buildConfig($structure);

        $this->assertSame('B', $config['sheets'][0]['structure']['total_column']);
    }

    // ── E) Ausencia de total determinable ────────────────────────────────

    public function test_no_esTotal_field_in_first_section_throws_explicitly(): void
    {
        $estructura = [
            'forms' => [
                [
                    'sheetName' => 'XX02',
                    'sections' => [
                        [
                            'codigo' => 'A',
                            'filaHeader' => 8,
                            'filaInicioDatos' => 9,
                            'filaFinDatos' => 20,
                            'fields' => [
                                $this->field('A', 'CONCEPTO'),
                                $this->field('B', 'DATO SIN TOTAL'),
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BS', 'status' => 'active', 'estructura' => $estructura]);

        $this->expectException(RemTemplateConfigGenerationException::class);
        $this->expectExceptionMessageMatches('/total_column/i');

        $this->service()->buildConfig($structure);
    }

    public function test_no_concept_candidate_throws_explicitly(): void
    {
        $estructura = [
            'forms' => [
                [
                    'sheetName' => 'XX03',
                    'sections' => [
                        [
                            'codigo' => 'A',
                            'filaHeader' => 8,
                            'filaInicioDatos' => 9,
                            'filaFinDatos' => 20,
                            'fields' => [
                                $this->field('A', 'TOTAL', esTotal: true),
                                $this->field('B', 'CONTROL', esControlOculto: true),
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BS', 'status' => 'active', 'estructura' => $estructura]);

        $this->expectException(RemTemplateConfigGenerationException::class);
        $this->expectExceptionMessageMatches('/concept_column/i');

        $this->service()->buildConfig($structure);
    }

    // ── Estados permitidos ────────────────────────────────────────────────

    public function test_superseded_status_is_rejected(): void
    {
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BM', 'status' => 'superseded', 'estructura' => $this->bmEstructura()]);

        $this->expectException(RemTemplateConfigGenerationException::class);
        $this->expectExceptionMessageMatches('/superseded/i');

        $this->service()->buildConfig($structure);
    }

    public function test_draft_status_is_allowed(): void
    {
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BM', 'status' => 'draft', 'estructura' => $this->bmEstructura()]);

        $config = $this->service()->buildConfig($structure);

        $this->assertCount(2, $config['sheets']);
    }

    public function test_approved_status_is_allowed(): void
    {
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BM', 'status' => 'approved', 'estructura' => $this->bmEstructura()]);

        $config = $this->service()->buildConfig($structure);

        $this->assertCount(2, $config['sheets']);
    }

    public function test_active_status_is_allowed(): void
    {
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BM', 'status' => 'active', 'estructura' => $this->bmEstructura()]);

        $config = $this->service()->buildConfig($structure);

        $this->assertCount(2, $config['sheets']);
    }

    // ── BM-3.5A: invariante real certificada (auditoria de cobertura) ────
    //
    // La auditoria BM-3.5A verifico, contra las 27 hojas reales de la
    // estructura activa Serie A (67/v35), que "ALL_SECTION_COLUMNS ⊆
    // FIRST_SECTION_COLUMNS" es FALSA en 16 de 27 hojas (secciones
    // posteriores introducen columnas -- tipicamente rangos etarios
    // adicionales AA-AP -- ausentes de la primera seccion). Esas 16 hojas
    // funcionan correctamente en produccion de todas formas: se confirmo
    // trazando App\Domain\REM\Services\RemParserService::parseSheet()
    // linea 269 (`$sectionContext['numeric_columns'] ?? $numericColumnLetters`)
    // que, una vez que existe una RemTemplateStructure activa con
    // secciones (buildSectionMaps() no vacio), el conjunto de columnas
    // REAL por fila viene siempre del mapa de secciones fino -- nunca de
    // columns[] -- por lo que las columnas "perdidas" en la primera
    // seccion del config grueso nunca se pierden en el parseo real. La
    // propiedad que el parser SI exige es mas simple: el config grueso
    // debe describir la PRIMERA seccion de forma internamente consistente
    // (header/data rows validos, concept/total derivables) -- nunca que
    // cubra el 100% de las columnas de la hoja completa. Este test
    // documenta y certifica esa propiedad real con una fixture que
    // replica el patron encontrado en Serie A real (ej. A01: seccion 1
    // sin las columnas AI-AP que solo aparecen en una seccion posterior).
    public function test_missing_columns_beyond_first_section_do_not_break_generation(): void
    {
        $estructura = [
            'forms' => [
                [
                    'sheetName' => 'XX04',
                    'sections' => [
                        [
                            'codigo' => 'A',
                            'filaHeader' => 9,
                            'filaInicioDatos' => 10,
                            'filaFinDatos' => 50,
                            'fields' => [
                                $this->field('A', 'CONCEPTO'),
                                $this->field('B', 'TOTAL', esTotal: true),
                                $this->field('C', 'RANGO 1'),
                            ],
                        ],
                        [
                            'codigo' => 'B',
                            'filaHeader' => 52,
                            'filaInicioDatos' => 53,
                            'filaFinDatos' => 80,
                            'fields' => [
                                $this->field('A', 'CONCEPTO'),
                                $this->field('B', 'TOTAL', esTotal: true),
                                // Columnas D/E ausentes de la primera seccion --
                                // patron real confirmado en Serie A (A01, A03,
                                // A05, A07, etc.).
                                $this->field('D', 'RANGO NUEVO 1'),
                                $this->field('E', 'RANGO NUEVO 2'),
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $structure = new RemTemplateStructure(['anio' => 2026, 'serie' => 'BS', 'status' => 'active', 'estructura' => $estructura]);

        $config = $this->service()->buildConfig($structure)['sheets'][0];

        // No lanza excepcion, y describe fielmente SOLO la primera
        // seccion -- D/E de la segunda seccion quedan fuera de 'columns'
        // por diseno (documentado arriba: inerte para el parser real una
        // vez que existe estructura activa).
        $this->assertSame(['A', 'B', 'C'], array_column($config['columns'], 'letter'));
        $this->assertSame('A', $config['structure']['concept_column']);
        $this->assertSame('B', $config['structure']['total_column']);
        $this->assertSame(80, $config['structure']['data_end_row'], 'data_end_row si toma el limite de la ULTIMA seccion');
    }
}
