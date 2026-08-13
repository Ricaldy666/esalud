<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\REM\Models\RemValidationResult;
use App\Domain\REM\Models\RemData;
use App\Domain\RuleEngine\Services\ValidationErrorFormatterService;
use App\Domain\REM\Models\RemUpload;
use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ValidationErrorFormatterTest extends TestCase
{
    use RefreshDatabase;

    private ValidationErrorFormatterService $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new ValidationErrorFormatterService;
    }

    public function test_funcional_error_includes_required_fields(): void
    {
        $healthCenter = HealthCenter::create([
            'name' => 'CESFAM Prueba',
            'code_deis' => 'HC_001',
            'type' => 'Cesfam',
        ]);

        $upload = RemUpload::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'health_center_id' => $healthCenter->id,
            'user_id' => User::factory()->create()->id,
            'year' => 2026,
            'month' => 6,
            'rem_type' => 'A',
            'original_filename' => 'test.xlsx',
            'stored_path' => 'test.xlsx',
            'file_size' => 1000,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'status' => 'with_errors',
        ]);

        $result = RemValidationResult::create([
            'rem_upload_id' => $upload->id,
            'rule_key' => 'f_A01_11',
            'rule_type' => 'functional_rule',
            'severity' => 'error',
            'passed' => false,
            'message' => '[A01] La fila 11, Preconcepcional / Médico/a, debe registrar 0 explícitamente cuando no existan prestaciones.',
            'context' => [
                'section' => 'A01',
                'row_number' => 11,
                'row_total' => 0,
                'pending_cells_count' => 2,
                'pending_cells' => [
                    [
                        'coordinate' => 'F11',
                        'column' => 'F',
                        'label' => '10 - 14 anos',
                        'expected_value' => 0,
                        'editable' => true,
                        'blocked' => false,
                    ],
                    [
                        'coordinate' => 'G11',
                        'column' => 'G',
                        'label' => '15 - 19 anos',
                        'expected_value' => 0,
                        'editable' => true,
                        'blocked' => false,
                    ],
                ],
                'concept' => 'Preconcepcional',
                'profesional' => 'Médico/a',
                'criterio' => [
                    'empty_behavior' => 'debe_registrar_cero',
                    'functional_condition' => '',
                    'justification' => 'Test de sync.',
                    'informed_by' => 'Sistema de Calibración',
                    'informed_at' => '2026-07-18T15:26:02+00:00',
                    'status' => 'validada por Estadística',
                    'updated_by' => 'Sistema de Calibración',
                    'updated_at' => '2026-07-18T15:26:02+00:00',
                    'scope' => 'global',
                    'confidence_level' => 'Alta',
                    'acquisition_method' => 'Manual REM',
                ],
            ],
        ]);

        $formatted = $this->formatter->formatFromResult($result);

        $this->assertSame('funcional', $formatted['tipo_error'], 'tipo_error debe ser funcional');
        $this->assertSame('functional_rule', $formatted['tipo'], 'tipo debe ser functional_rule');
        $this->assertSame('error', $formatted['severidad'], 'severidad debe ser error');
        $this->assertSame('A01', $formatted['form'], 'form debe ser A01');
        $this->assertSame('A01', $formatted['section'], 'section debe ser A01');
        $this->assertStringContainsString('Fila 11', $formatted['campo'], 'campo debe contener número de fila');
        $this->assertStringContainsString('Preconcepcional', $formatted['campo'], 'campo debe contener concepto');
        $this->assertStringContainsString('Médico/a', $formatted['campo'], 'campo debe contener profesional');
        $this->assertStringContainsString('valor 0', $formatted['mensaje'], 'mensaje debe contener indicación de registrar 0');
        $this->assertStringContainsString('registre 0', $formatted['recomendacion'], 'recomendación debe indicar registrar 0');

        $this->assertNotNull($formatted['criterio'], 'criterio no debe ser null');
        $this->assertSame('debe_registrar_cero', $formatted['criterio']['empty_behavior']);
        $this->assertSame('Sistema de Calibración', $formatted['criterio']['informed_by']);
        $this->assertSame('global', $formatted['criterio']['scope']);
        $this->assertSame(0, $formatted['row_total']);
        $this->assertSame(2, $formatted['pending_cells_count']);
        $this->assertSame(['F11', 'G11'], array_column($formatted['pending_cells'], 'coordinate'));
    }

    public function test_funcional_error_resolves_rem_subsection_from_rem_data_and_active_structure(): void
    {
        $healthCenter = HealthCenter::create([
            'name' => 'CESFAM Prueba',
            'code_deis' => 'HC_003',
            'type' => 'Cesfam',
        ]);

        $upload = RemUpload::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'health_center_id' => $healthCenter->id,
            'user_id' => User::factory()->create()->id,
            'year' => 2026,
            'month' => 6,
            'rem_type' => 'A',
            'original_filename' => 'test.xlsx',
            'stored_path' => 'test.xlsx',
            'file_size' => 1000,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'status' => 'with_errors',
        ]);

        RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_validation_formatter',
            'estructura' => [
                'forms' => [[
                    'sheetName' => 'A01',
                    'sections' => [[
                        'codigo' => 'B',
                        'titulo' => 'CONTROLES DE SALUD SEGÚN CICLO VITAL',
                        'filaInicioDatos' => 35,
                        'filaFinDatos' => 39,
                        'fields' => [],
                    ]],
                ]],
            ],
            'status' => 'active',
        ]);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'A01',
            'data' => [
                'section' => 'A01',
                'rem_section_code' => 'B',
                'row_number' => 39,
                'concept' => 'De salud',
                'professional' => 'Técnico en Enfermería',
            ],
        ]);

        $result = RemValidationResult::create([
            'rem_upload_id' => $upload->id,
            'rule_key' => 'f_A01_39',
            'rule_type' => 'functional_rule',
            'severity' => 'warning',
            'passed' => false,
            'message' => '[A01] La fila 39 debe registrar 0.',
            'context' => [
                'section' => 'A01',
                'row_number' => 39,
                'concept' => 'De salud',
                'profesional' => 'Técnico en Enfermería',
                'pending_cells_count' => 1,
                'pending_cells' => [[
                    'coordinate' => 'D39',
                    'column' => 'D',
                    'label' => 'Hombres',
                    'expected_value' => 0,
                    'editable' => true,
                    'blocked' => false,
                ]],
                'criterio' => ['empty_behavior' => 'debe_registrar_cero'],
            ],
        ]);

        $formatted = $this->formatter->formatFromResult($result);

        $this->assertSame('B', $formatted['section_code']);
        $this->assertSame('CONTROLES DE SALUD SEGÚN CICLO VITAL', $formatted['section_name']);
        $this->assertSame(35, $formatted['section_order']);
        $this->assertSame(39, $formatted['row_number']);
    }

    public function test_tecnico_error_has_correct_tipo_error(): void
    {
        $healthCenter = HealthCenter::create([
            'name' => 'CESFAM Prueba',
            'code_deis' => 'HC_002',
            'type' => 'Cesfam',
        ]);

        $upload = RemUpload::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'health_center_id' => $healthCenter->id,
            'user_id' => User::factory()->create()->id,
            'year' => 2026,
            'month' => 6,
            'rem_type' => 'A',
            'original_filename' => 'test.xlsx',
            'stored_path' => 'test.xlsx',
            'file_size' => 1000,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'status' => 'with_errors',
        ]);

        $result = RemValidationResult::create([
            'rem_upload_id' => $upload->id,
            'rule_key' => 'A01_sum_equals',
            'rule_type' => 'sum_equals',
            'severity' => 'error',
            'passed' => false,
            'message' => '[A01] suma 100 no coincide con total 200',
            'context' => [
                'section' => 'A01',
                'concept' => 'Control',
                'computed_sum' => 100,
                'declared_total' => 200,
            ],
        ]);

        $formatted = $this->formatter->formatFromResult($result);

        $this->assertSame('tecnico', $formatted['tipo_error'], 'tipo_error debe ser tecnico');
        $this->assertSame('sum_equals', $formatted['tipo'], 'tipo debe ser sum_equals');
        $this->assertNull($formatted['criterio'], 'criterio debe ser null para errores técnicos');
    }

    // ─── Regresion de rendimiento (hallazgo produccion upload 8, 2026-08-13) ───

    private function makeUploadWithStructure(string $suffix): RemUpload
    {
        $healthCenter = HealthCenter::create([
            'name' => "CESFAM {$suffix}", 'code_deis' => "HC_{$suffix}", 'type' => 'Cesfam',
        ]);

        $upload = RemUpload::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'health_center_id' => $healthCenter->id,
            'user_id' => User::factory()->create()->id,
            'year' => 2026, 'month' => 6, 'rem_type' => 'A',
            'original_filename' => 'test.xlsx', 'stored_path' => 'test.xlsx',
            'file_size' => 1000,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'status' => 'with_errors',
        ]);

        RemTemplateStructure::create([
            'serie' => 'A', 'anio' => 2026, 'version_number' => 1,
            'hash_estructura' => "hash_{$suffix}",
            'estructura' => ['forms' => [[
                'sheetName' => 'A01',
                'sections' => [[
                    'codigo' => 'B', 'titulo' => 'CONTROLES DE SALUD SEGÚN CICLO VITAL',
                    'filaInicioDatos' => 35, 'filaFinDatos' => 39, 'fields' => [],
                ]],
            ]]],
            'status' => 'active',
        ]);

        return $upload;
    }

    public function test_rem_data_query_count_does_not_scale_with_number_of_distinct_rows(): void
    {
        $upload = $this->makeUploadWithStructure('QC');

        // 50 filas de RemData distintas, 50 RemValidationResult funcionales
        // distintos (una por fila) -- antes de la optimizacion, cada fila
        // disparaba su propia recarga completa de RemData::get().
        foreach (range(1, 50) as $row) {
            RemData::create([
                'rem_upload_id' => $upload->id,
                'section' => 'A01',
                'data' => [
                    'section' => 'A01', 'rem_section_code' => 'B',
                    'row_number' => $row, 'concept' => "Concepto {$row}", 'professional' => 'Prof',
                ],
            ]);
            RemValidationResult::create([
                'rem_upload_id' => $upload->id,
                'rule_key' => "f_A01_{$row}",
                'rule_type' => 'functional_rule',
                'severity' => 'error',
                'passed' => false,
                'message' => "Mensaje {$row}",
                'context' => [
                    'section' => 'A01', 'row_number' => $row,
                    'concept' => "Concepto {$row}", 'profesional' => 'Prof',
                    'criterio' => ['empty_behavior' => 'debe_registrar_cero'],
                ],
            ]);
        }

        $remDataQueries = 0;
        DB::listen(function ($query) use (&$remDataQueries) {
            if (str_contains($query->sql, '`rem_data`')) {
                $remDataQueries++;
            }
        });

        $formatter = new ValidationErrorFormatterService;
        $result = $formatter->formatErrors($upload->id);

        $this->assertCount(50, $result);
        $this->assertSame(
            1,
            $remDataQueries,
            'RemData debe consultarse exactamente UNA vez por upload, sin importar cuantas filas distintas se formateen'
        );
    }

    public function test_duplicate_rem_data_same_sheet_and_row_keeps_first_match(): void
    {
        $upload = $this->makeUploadWithStructure('DUP');

        $first = RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'A01',
            'data' => [
                'section' => 'A01', 'rem_section_code' => 'B',
                'row_number' => 39, 'concept' => 'Primero', 'professional' => 'Prof',
            ],
        ]);
        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'A01',
            'data' => [
                'section' => 'A01', 'rem_section_code' => 'SEC_DUPLICADA_NO_DEBE_GANAR',
                'row_number' => 39, 'concept' => 'Segundo', 'professional' => 'Prof',
            ],
        ]);
        $this->assertLessThan(RemData::latest('id')->first()->id, $first->id);

        $result = RemValidationResult::create([
            'rem_upload_id' => $upload->id,
            'rule_key' => 'f_A01_39',
            'rule_type' => 'functional_rule',
            'severity' => 'error',
            'passed' => false,
            'message' => 'Mensaje',
            'context' => [
                'section' => 'A01', 'row_number' => 39,
                'criterio' => ['empty_behavior' => 'debe_registrar_cero'],
            ],
        ]);

        $formatter = new ValidationErrorFormatterService;
        $formatted = $formatter->formatFromResult($result);

        $this->assertSame('B', $formatted['section_code'], 'debe resolver con el PRIMER RemData insertado, no el ultimo');
    }

    public function test_deduplication_removes_exact_duplicate_formatted_entries(): void
    {
        $upload = $this->makeUploadWithStructure('DEDUP');

        foreach ([1, 2] as $i) {
            RemValidationResult::create([
                'rem_upload_id' => $upload->id,
                'rule_key' => "f_A01_dup_{$i}",
                'rule_type' => 'sum_equals',
                'severity' => 'error',
                'passed' => false,
                'message' => 'Mensaje identico',
                'context' => ['section' => 'A01', 'concept' => 'Mismo concepto'],
            ]);
        }

        $formatter = new ValidationErrorFormatterService;
        $result = $formatter->formatErrors($upload->id);

        $this->assertCount(1, $result, 'dos errores formateados identicos deben colapsar a uno solo');
    }
}
