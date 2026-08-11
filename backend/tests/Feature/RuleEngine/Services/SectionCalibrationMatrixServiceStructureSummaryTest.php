<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cubre SectionCalibrationMatrixService::buildStructureCalibrationSummary()
 * -- hallazgo de revision UX (2026-08-11): Dashboard/Plantilla/Serie
 * mostraban "sin datos suficientes" fijo en vez de progreso real, obligando
 * al funcionario a entrar seccion por seccion. Este agregado reutiliza
 * buildPatternMatrix() (misma fuente de verdad ya validada durante toda la
 * campaña para effective_section_reviewed/calibration_applicability) en un
 * solo metodo de servicio, para que el frontend pueda pedirlo en UN request
 * en vez de N requests por seccion.
 *
 * Criterio de "completada": effective_section_reviewed === true, sin
 * importar si el cierre fue normal (response='revisada') o
 * no-calibrable (response='no_calibrable') -- se cuentan por separado
 * solo para presentacion.
 */
class SectionCalibrationMatrixServiceStructureSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);
    }

    private function dummyFields(array $letras): array
    {
        return array_map(fn (string $letra) => [
            'letra' => $letra,
            'label' => "Campo {$letra}",
            'esTotal' => false,
            'esControlOculto' => false,
            'reglaDetectada' => null,
        ], $letras);
    }

    private function dummySection(string $codigo, int $filaHeader, int $filaInicioDatos, int $filaFinDatos, array $letras): array
    {
        return [
            'codigo' => $codigo,
            'titulo' => "SECCION {$codigo} DE PRUEBA",
            'filaHeader' => $filaHeader,
            'filaInicioDatos' => $filaInicioDatos,
            'filaFinDatos' => $filaFinDatos,
            'fields' => $this->dummyFields($letras),
        ];
    }

    /**
     * Estructura sintetica con 4 secciones, una por cada estado a probar:
     * REV (se calibrara normalmente), NOC (se cerrara como no-calibrable),
     * PEN (nunca se toca -- pendiente), REVAL (respuesta guardada pero con
     * fingerprint desactualizado -- requiere revalidacion).
     */
    private function createActiveStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => 'hash-summary-test-' . uniqid(),
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'TESTSUMMARY',
                        'sections' => [
                            $this->dummySection('REV', 9, 10, 11, ['A', 'B', 'C']),
                            $this->dummySection('NOC', 9, 10, 11, ['A', 'B', 'C']),
                            $this->dummySection('PEN', 9, 10, 11, ['A', 'B', 'C']),
                            $this->dummySection('REVAL', 9, 10, 11, ['A', 'B', 'C']),
                        ],
                    ],
                ],
            ],
            'metadata' => null,
            'source_filename' => 'test.xlsm',
            'status' => 'active',
        ]);
    }

    private function editableCell(?int $valor = null): array
    {
        return ['valor_bruto' => $valor, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null];
    }

    private function lockedCell(?string $valor = null): array
    {
        return ['valor_bruto' => $valor, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null];
    }

    private function seedSectionCellData(CellDataStorageService $cellData, string $sheet, string $section, bool $editable): void
    {
        $cellData->saveCellData($sheet, $section, [
            'A10' => $this->lockedCell('Item 1'),
            'B10' => $this->lockedCell(),
            'C10' => $editable ? $this->editableCell() : $this->lockedCell(),
            'A11' => $this->lockedCell('Item 2'),
            'B11' => $this->lockedCell(),
            'C11' => $editable ? $this->editableCell() : $this->lockedCell(),
        ]);
    }

    public function test_summary_counts_each_state_correctly(): void
    {
        $this->createActiveStructure();
        $cellData = app(CellDataStorageService::class);
        $functionalRules = app(FunctionalRuleService::class);

        // Nota: cada fase (descubrir el patron / guardar respuestas / leer
        // el resultado final) resuelve una instancia NUEVA de
        // SectionCalibrationMatrixService a proposito. FunctionalRuleService
        // cachea el contenido de reglas-funcionales.json a nivel de
        // instancia (deliberado, ver su propio docblock -- en produccion
        // cada request HTTP resuelve el servicio de nuevo, asi que nunca ve
        // una escritura ajena). Reutilizar la MISMA instancia de servicio
        // para leer despues de escribir desde OTRA instancia haria que el
        // test viera un cache obsoleto -- no refleja como funciona
        // realmente en produccion.

        // REV: contenido editable real, se calibra normalmente.
        $this->seedSectionCellData($cellData, 'TESTSUMMARY', 'REV', editable: true);
        $preMatrix = app(SectionCalibrationMatrixService::class)->buildPatternMatrix('TESTSUMMARY', 'REV');
        $pattern = $preMatrix['patterns'][0];
        $functionalRules->saveQuestions('TESTSUMMARY', 'REV', [
            [
                'id' => "patron_{$pattern['id']}_confirmation",
                'type' => 'pattern_confirmation',
                'pattern_id' => $pattern['id'],
                'pattern_fingerprint' => $pattern['row_fingerprint'],
                'review_status' => 'reviewed',
                'response' => 'confirmed',
                'question' => 'Confirmacion de patron',
            ],
            [
                'id' => 'section_review',
                'type' => 'section_review',
                'response' => 'revisada',
                'review_status' => 'section_reviewed',
                'question' => 'Seccion revisada funcionalmente',
            ],
        ]);

        // NOC: sin contenido calibrable, cerrada como no-calibrable.
        $this->seedSectionCellData($cellData, 'TESTSUMMARY', 'NOC', editable: false);
        $functionalRules->saveQuestions('TESTSUMMARY', 'NOC', [
            [
                'id' => 'section_review',
                'type' => 'section_review',
                'response' => 'no_calibrable',
                'review_status' => 'section_reviewed',
                'closure_reason' => 'no_calibratable_data',
                'question' => 'Sin datos capturables',
            ],
        ]);

        // PEN: contenido editable real, nunca se toca -- pendiente.
        $this->seedSectionCellData($cellData, 'TESTSUMMARY', 'PEN', editable: true);

        // REVAL: respuesta guardada con pattern_fingerprint desactualizado
        // (no coincide con el patron vigente) -- requiere revalidacion, NO
        // debe contar como completada aunque exista una respuesta guardada.
        $this->seedSectionCellData($cellData, 'TESTSUMMARY', 'REVAL', editable: true);
        $revalMatrix = app(SectionCalibrationMatrixService::class)->buildPatternMatrix('TESTSUMMARY', 'REVAL');
        $revalPattern = $revalMatrix['patterns'][0];
        $functionalRules->saveQuestions('TESTSUMMARY', 'REVAL', [
            [
                'id' => "patron_{$revalPattern['id']}_confirmation",
                'type' => 'pattern_confirmation',
                'pattern_id' => $revalPattern['id'],
                'pattern_fingerprint' => 'rowset_fingerprint_que_no_coincide',
                'review_status' => 'reviewed',
                'response' => 'confirmed',
                'question' => 'Confirmacion de patron desactualizada',
            ],
            [
                'id' => 'section_review',
                'type' => 'section_review',
                'response' => 'revisada',
                'review_status' => 'section_reviewed',
                'question' => 'Seccion revisada funcionalmente',
            ],
        ]);

        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);
        $summary = app(SectionCalibrationMatrixService::class)->buildStructureCalibrationSummary();

        $sheet = collect($summary['sheets'])->firstWhere('sheet_name', 'TESTSUMMARY');
        $this->assertNotNull($sheet);
        $this->assertSame(4, $sheet['sections_total']);
        $this->assertSame(2, $sheet['sections_completed'], 'REV y NOC deben contar como completadas');
        $this->assertSame(1, $sheet['sections_calibrated'], 'solo REV es una calibracion normal');
        $this->assertSame(1, $sheet['sections_not_calibratable'], 'solo NOC es no-calibrable');
        $this->assertSame(2, $sheet['sections_pending'], 'PEN (nunca tocada) y REVAL (requiere revalidacion) deben quedar pendientes');
        $this->assertSame(50, $sheet['progress_pct']);
        $this->assertSame('en_revision', $sheet['status'], 'ni todas completadas ni todas sin tocar');

        $totals = $summary['totals'];
        $this->assertSame(4, $totals['sections_aplicables']);
        $this->assertSame(4, $totals['sections_total_estructura']);
        $this->assertSame(0, $totals['sections_no_utilizadas'], 'ninguna hoja marcada no_utilizada en este escenario');
        $this->assertSame(2, $totals['sections_completed']);
        $this->assertSame(1, $totals['sections_calibrated']);
        $this->assertSame(1, $totals['sections_not_calibratable']);
        $this->assertSame(2, $totals['sections_pending']);
    }

    public function test_sheet_status_is_completada_when_all_sections_done(): void
    {
        RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => 'hash-summary-completa-' . uniqid(),
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'TESTFULL',
                        'sections' => [$this->dummySection('X', 9, 10, 11, ['A', 'B', 'C'])],
                    ],
                ],
            ],
            'metadata' => null,
            'source_filename' => 'test.xlsm',
            'status' => 'active',
        ]);

        $cellData = app(CellDataStorageService::class);
        $this->seedSectionCellData($cellData, 'TESTFULL', 'X', editable: false);
        app(FunctionalRuleService::class)->saveQuestions('TESTFULL', 'X', [
            [
                'id' => 'section_review',
                'type' => 'section_review',
                'response' => 'no_calibrable',
                'review_status' => 'section_reviewed',
                'closure_reason' => 'no_calibratable_data',
                'question' => 'Sin datos capturables',
            ],
        ]);

        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);
        $summary = app(SectionCalibrationMatrixService::class)->buildStructureCalibrationSummary();
        $sheet = collect($summary['sheets'])->firstWhere('sheet_name', 'TESTFULL');

        $this->assertSame('completada', $sheet['status']);
        $this->assertSame(100, $sheet['progress_pct']);
        $this->assertSame(1, $summary['totals']['sheets_completed']);
    }

    public function test_sheet_status_is_pendiente_when_nothing_touched(): void
    {
        RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => 'hash-summary-pendiente-' . uniqid(),
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'TESTEMPTY',
                        'sections' => [$this->dummySection('X', 9, 10, 11, ['A', 'B', 'C'])],
                    ],
                ],
            ],
            'metadata' => null,
            'source_filename' => 'test.xlsm',
            'status' => 'active',
        ]);

        $cellData = app(CellDataStorageService::class);
        $this->seedSectionCellData($cellData, 'TESTEMPTY', 'X', editable: true);

        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);
        $summary = app(SectionCalibrationMatrixService::class)->buildStructureCalibrationSummary();
        $sheet = collect($summary['sheets'])->firstWhere('sheet_name', 'TESTEMPTY');

        $this->assertSame('pendiente', $sheet['status']);
        $this->assertSame(0, $sheet['progress_pct']);
    }

    public function test_result_is_cached_between_calls(): void
    {
        $this->createActiveStructure();
        $cellData = app(CellDataStorageService::class);
        foreach (['REV', 'NOC', 'PEN', 'REVAL'] as $s) {
            $this->seedSectionCellData($cellData, 'TESTSUMMARY', $s, editable: true);
        }

        $svc = app(SectionCalibrationMatrixService::class);
        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);

        $this->assertFalse(Cache::has(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY));
        $first = $svc->buildStructureCalibrationSummary();
        $this->assertTrue(Cache::has(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY));

        // Cambia el cell-data SIN pasar por saveQuestions() (que invalidaria
        // el cache) -- la segunda llamada debe seguir devolviendo el mismo
        // resultado cacheado, no recalcular.
        $this->seedSectionCellData($cellData, 'TESTSUMMARY', 'PEN', editable: false);
        $second = $svc->buildStructureCalibrationSummary();

        $this->assertSame($first, $second, 'debe servir desde cache, no recalcular');
    }

    public function test_saving_questions_invalidates_the_cache(): void
    {
        $this->createActiveStructure();
        $cellData = app(CellDataStorageService::class);
        foreach (['REV', 'NOC', 'PEN', 'REVAL'] as $s) {
            $this->seedSectionCellData($cellData, 'TESTSUMMARY', $s, editable: false);
        }

        $svc = app(SectionCalibrationMatrixService::class);
        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);
        $svc->buildStructureCalibrationSummary();
        $this->assertTrue(Cache::has(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY));

        app(FunctionalRuleService::class)->saveQuestions('TESTSUMMARY', 'NOC', [
            [
                'id' => 'section_review',
                'type' => 'section_review',
                'response' => 'no_calibrable',
                'review_status' => 'section_reviewed',
                'closure_reason' => 'no_calibratable_data',
                'question' => 'Sin datos capturables',
            ],
        ]);

        $this->assertFalse(
            Cache::has(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY),
            'saveQuestions() debe invalidar el cache del agregado'
        );
    }

    public function test_no_active_structure_returns_empty_summary(): void
    {
        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);
        $summary = app(SectionCalibrationMatrixService::class)->buildStructureCalibrationSummary();

        $this->assertNull($summary['structure_id']);
        $this->assertSame([], $summary['sheets']);
        $this->assertSame([], $summary['no_utilizadas']);
        $this->assertSame(0, $summary['totals']['sections_aplicables']);
        $this->assertSame(0, $summary['totals']['sections_total_estructura']);
    }
}
