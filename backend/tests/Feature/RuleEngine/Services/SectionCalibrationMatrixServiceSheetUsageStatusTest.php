<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\RemSheetUsageStatusService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cubre la integracion de RemSheetUsageStatusService dentro de
 * SectionCalibrationMatrixService::buildStructureCalibrationSummary() --
 * hojas 'no_utilizada' deben quedar completamente fuera del denominador
 * de progreso (sections_aplicables), no contarse como pendientes, y
 * reportarse aparte en 'no_utilizadas' con su motivo/responsable/fecha.
 */
class SectionCalibrationMatrixServiceSheetUsageStatusTest extends TestCase
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
            'letra' => $letra, 'label' => "Campo {$letra}", 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null,
        ], $letras);
    }

    private function dummySection(string $codigo): array
    {
        return [
            'codigo' => $codigo, 'titulo' => "SECCION {$codigo}",
            'filaHeader' => 9, 'filaInicioDatos' => 10, 'filaFinDatos' => 11,
            'fields' => $this->dummyFields(['A', 'B', 'C']),
        ];
    }

    private function lockedCell(?string $valor = null): array
    {
        return ['valor_bruto' => $valor, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null];
    }

    private function editableCell(): array
    {
        return ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null];
    }

    private function seedCellData(CellDataStorageService $cellData, string $sheet, string $section): void
    {
        $cellData->saveCellData($sheet, $section, [
            'A10' => $this->lockedCell('Item 1'), 'B10' => $this->lockedCell(), 'C10' => $this->editableCell(),
            'A11' => $this->lockedCell('Item 2'), 'B11' => $this->lockedCell(), 'C11' => $this->editableCell(),
        ]);
    }

    /**
     * Estructura con 3 hojas: NOUSE (2 secciones, se marcara no_utilizada),
     * APLIC1 (2 secciones, ambas se calibraran -> completada), APLIC2
     * (1 seccion, se deja sin tocar -> pendiente). Numeros redondos
     * elegidos para verificar la formula, no para reproducir 378/75/303.
     */
    private function createStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 1,
            'hash_estructura' => 'hash-usage-summary-' . uniqid(),
            'estructura' => [
                'forms' => [
                    ['sheetName' => 'NOUSE', 'sections' => [$this->dummySection('A'), $this->dummySection('B')]],
                    ['sheetName' => 'APLIC1', 'sections' => [$this->dummySection('A'), $this->dummySection('B')]],
                    ['sheetName' => 'APLIC2', 'sections' => [$this->dummySection('A')]],
                ],
            ],
            'metadata' => null, 'source_filename' => 'test.xlsm', 'status' => 'active',
        ]);
    }

    public function test_no_utilizada_sheet_excluded_from_aplicables_and_not_counted_as_pending(): void
    {
        $structure = $this->createStructure();
        $cellData = app(CellDataStorageService::class);
        $usageStatus = app(RemSheetUsageStatusService::class);
        $functionalRules = app(FunctionalRuleService::class);

        // NOUSE: nunca se escanea, nunca se le guarda nada -- si el motor
        // la tratara como aplicable, sus 2 secciones caerian en "pendiente".
        $usageStatus->setStatus(2026, 'A', 'NOUSE', RemSheetUsageStatusService::STATUS_NO_UTILIZADA, 'No utilizada por Estadística APS', 'Estadística APS', $structure->id);

        // APLIC1: ambas secciones calibradas normalmente.
        foreach (['A', 'B'] as $sec) {
            $this->seedCellData($cellData, 'APLIC1', $sec);
            $pattern = app(SectionCalibrationMatrixService::class)->buildPatternMatrix('APLIC1', $sec)['patterns'][0];
            $functionalRules->saveQuestions('APLIC1', $sec, [
                ['id' => "patron_{$pattern['id']}_confirmation", 'type' => 'pattern_confirmation', 'pattern_id' => $pattern['id'], 'pattern_fingerprint' => $pattern['row_fingerprint'], 'review_status' => 'reviewed', 'response' => 'confirmed', 'question' => 'Confirmacion'],
                ['id' => 'section_review', 'type' => 'section_review', 'response' => 'revisada', 'review_status' => 'section_reviewed', 'question' => 'Revisada'],
            ]);
        }

        // APLIC2: se escanea pero NUNCA se calibra -- debe quedar pendiente.
        $this->seedCellData($cellData, 'APLIC2', 'A');

        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);
        $summary = app(SectionCalibrationMatrixService::class)->buildStructureCalibrationSummary();

        // Requisito #2 y #7: NOUSE fuera del denominador de aplicables.
        $this->assertSame(3, $summary['totals']['sections_aplicables'], '2 de APLIC1 + 1 de APLIC2, NUNCA las 2 de NOUSE');
        $this->assertSame(5, $summary['totals']['sections_total_estructura']);
        $this->assertSame(2, $summary['totals']['sections_no_utilizadas']);

        // Requisito #3: NOUSE no aparece en 'sheets' (el array de hojas
        // aplicables) ni sus secciones se cuentan como pendientes.
        $sheetNames = array_column($summary['sheets'], 'sheet_name');
        $this->assertNotContains('NOUSE', $sheetNames);
        $this->assertSame(1, $summary['totals']['sections_pending'], 'solo APLIC2/A -- las 2 de NOUSE NUNCA cuentan como pendientes');

        // NOUSE debe reportarse aparte, con motivo/responsable/fecha/estructura.
        $this->assertCount(1, $summary['no_utilizadas']);
        $noUse = $summary['no_utilizadas'][0];
        $this->assertSame('NOUSE', $noUse['sheet_name']);
        $this->assertSame(2, $noUse['sections_total']);
        $this->assertSame('No utilizada por Estadística APS', $noUse['reason']);
        $this->assertSame('Estadística APS', $noUse['decided_by']);
        $this->assertNotNull($noUse['decided_at']);
        $this->assertSame($structure->id, $noUse['structure_id']);

        // Requisito #7: progress_pct = completadas / aplicables (2/3 = 67%),
        // NUNCA sobre el total bruto de 5 (que daria 40%).
        $this->assertSame(2, $summary['totals']['sections_completed']);
        $this->assertSame(67, $summary['totals']['progress_pct']);
    }

    /**
     * Requisito #4: reactivar una hoja la reincorpora al calculo de
     * aplicables sin ningun cambio de codigo adicional.
     */
    public function test_reactivated_sheet_is_reincorporated_into_aplicables(): void
    {
        $structure = $this->createStructure();
        $cellData = app(CellDataStorageService::class);
        $usageStatus = app(RemSheetUsageStatusService::class);

        $usageStatus->setStatus(2026, 'A', 'NOUSE', RemSheetUsageStatusService::STATUS_NO_UTILIZADA, 'motivo', 'Estadística APS', $structure->id);
        $this->seedCellData($cellData, 'APLIC1', 'A');
        $this->seedCellData($cellData, 'APLIC1', 'B');
        $this->seedCellData($cellData, 'APLIC2', 'A');

        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);
        $before = app(SectionCalibrationMatrixService::class)->buildStructureCalibrationSummary();
        $this->assertSame(3, $before['totals']['sections_aplicables']);

        $usageStatus->setStatus(2026, 'A', 'NOUSE', RemSheetUsageStatusService::STATUS_APLICABLE, 'vuelve a usarse', 'Estadística APS', $structure->id);

        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);
        $after = app(SectionCalibrationMatrixService::class)->buildStructureCalibrationSummary();

        $this->assertSame(5, $after['totals']['sections_aplicables'], 'NOUSE vuelve a sumar sus 2 secciones a aplicables');
        $this->assertSame(0, $after['totals']['sections_no_utilizadas']);
        $this->assertEmpty($after['no_utilizadas']);
        $this->assertContains('NOUSE', array_column($after['sheets'], 'sheet_name'));
    }

    /**
     * Requisito #9: escenario final -- todas las aplicables completadas
     * debe dar 100%, con las no_utilizadas totalmente fuera del calculo.
     */
    public function test_all_aplicables_completed_yields_100_percent(): void
    {
        $structure = $this->createStructure();
        $cellData = app(CellDataStorageService::class);
        $usageStatus = app(RemSheetUsageStatusService::class);
        $functionalRules = app(FunctionalRuleService::class);

        $usageStatus->setStatus(2026, 'A', 'NOUSE', RemSheetUsageStatusService::STATUS_NO_UTILIZADA, 'motivo', 'Estadística APS', $structure->id);

        foreach ([['APLIC1', 'A'], ['APLIC1', 'B'], ['APLIC2', 'A']] as [$sheet, $sec]) {
            $this->seedCellData($cellData, $sheet, $sec);
            $pattern = app(SectionCalibrationMatrixService::class)->buildPatternMatrix($sheet, $sec)['patterns'][0];
            $functionalRules->saveQuestions($sheet, $sec, [
                ['id' => "patron_{$pattern['id']}_confirmation", 'type' => 'pattern_confirmation', 'pattern_id' => $pattern['id'], 'pattern_fingerprint' => $pattern['row_fingerprint'], 'review_status' => 'reviewed', 'response' => 'confirmed', 'question' => 'Confirmacion'],
                ['id' => 'section_review', 'type' => 'section_review', 'response' => 'revisada', 'review_status' => 'section_reviewed', 'question' => 'Revisada'],
            ]);
        }

        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);
        $summary = app(SectionCalibrationMatrixService::class)->buildStructureCalibrationSummary();

        $this->assertSame(3, $summary['totals']['sections_aplicables']);
        $this->assertSame(3, $summary['totals']['sections_completed']);
        $this->assertSame(0, $summary['totals']['sections_pending']);
        $this->assertSame(100, $summary['totals']['progress_pct']);
        $this->assertSame(2, $summary['totals']['sections_no_utilizadas'], 'las 2 de NOUSE siguen totalmente fuera, ni suman ni restan del 100%');
    }
}
