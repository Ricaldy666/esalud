<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemTechnicalTotal;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Evaluators\SumEqualsEvaluator;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\RuleEngineService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CLAUDE.md punto 17.49 -- pilota el flujo completo para el patron
 * leading formula-based (regla 461, A30/F): total_row configurado en
 * filaInicioDatos-1, fila capturada en rem_technical_totals (nunca
 * rem_data) por el mecanismo nuevo -- confirma que RuleEngineService/
 * SumEqualsEvaluator (ya implementados en Fase 3B/17.22, sin ningun
 * cambio de codigo requerido para este patron) recuperan el total tecnico
 * correctamente, igual que ya hacian para el patron trailing (piloto
 * original, RuleEngineServiceTechnicalTotalPilotTest.php).
 *
 * Todos los fixtures son 100% sinteticos.
 */
class RuleEngineServiceLeadingFormulaBasedTotalPilotTest extends TestCase
{
    use RefreshDatabase;

    private RuleEngineService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $functionalMock = $this->createMock(FunctionalRuleService::class);
        $functionalMock->method('getFunctionalRulesForEngine')->willReturn([]);

        $this->service = new RuleEngineService($functionalMock);
        $this->service->registerEvaluator(new SumEqualsEvaluator);
    }

    private function createUpload(): RemUpload
    {
        return RemUpload::create([
            'rem_type' => 'A',
            'year' => 2101,
            'month' => 1,
            'status' => 'pending',
            'health_center_id' => HealthCenter::create([
                'name' => 'Centro Piloto Leading 17.49',
                'code_deis' => 'PL49' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'piloto_leading_461.xlsx',
            'stored_path' => 'rem/2101/01/piloto_leading_461.xlsx',
            'file_size' => 1000,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function createStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2101,
            'version_number' => 1,
            'estructura' => ['forms' => []],
            'hash_estructura' => 'hash_piloto_leading_461_' . uniqid(),
            'status' => 'active',
        ]);
    }

    private function createVerticalRule(string $sheet, string $section, string $column, int $rowFrom, int $rowTo, ?int $totalRow, string $key): Rule
    {
        $config = [
            'sheet' => $sheet,
            'section' => $section,
            'column' => $column,
            'row_range' => ['from' => $rowFrom, 'to' => $rowTo],
            'rule_logic' => "Suma({$column}) = Columna {$column}",
        ];
        if ($totalRow !== null) {
            $config['total_row'] = $totalRow;
        }

        return Rule::create([
            'rule_key' => $key,
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => $key,
            'description' => 'Piloto leading formula-based (461/17.49)',
            'severity' => 'error',
            'scope' => 'row_range',
            'config' => $config,
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => ['sheet' => $sheet],
        ]);
    }

    private function bind(Rule $rule, RemTemplateStructure $structure): void
    {
        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'structure',
            'bindable_id' => $structure->id,
            'serie' => 'A',
            'anio' => 2101,
            'active' => true,
        ]);
    }

    private function seedRemData(RemUpload $upload, string $sheet, string $section, int $row, string $column, int $value): void
    {
        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => $sheet,
            'data' => [
                'row_number' => $row,
                'concept' => "Concepto {$row}",
                'total' => null,
                'values' => [$column => $value],
                'rem_section_code' => $section,
            ],
        ]);
    }

    private function seedTechnicalTotal(RemUpload $upload, string $sheet, string $section, int $row, string $column, int $value): void
    {
        RemTechnicalTotal::create([
            'rem_upload_id' => $upload->id,
            'sheet' => $sheet,
            'rem_section_code' => $section,
            'row_number' => $row,
            'concept' => null,
            'total' => (string) $value,
            'values' => [$column => $value],
            'exclusion_reason' => 'leading_formula_total_beyond_bounds',
        ]);
    }

    public function test_461_pattern_produces_correct_result_matching_excel_formula(): void
    {
        // Replica el patron real de la regla 461 (A30/F columna B):
        // row_range=[124:129], total_row=123 (leading, filaInicioDatos-1).
        // Aqui con filas mas pequenas para el fixture: row_range=[20:25],
        // total_row=19.
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('P49LFB', 'F', 'B', 20, 25, 19, 'piloto_461_leading');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'P49LFB', 'F', 20, 'B', 5);
        $this->seedRemData($upload, 'P49LFB', 'F', 21, 'B', 3);
        // Excel real: B19 = SUM(B20:B25) = 8 (resto de filas sin dato real).
        $this->seedTechnicalTotal($upload, 'P49LFB', 'F', 19, 'B', 8);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'piloto_461_leading');
        $this->assertSame('passed', $detail['status']);
        $this->assertSame(0, $detail['failed_rows']);
    }

    public function test_461_pattern_fails_when_technical_total_does_not_match_components(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('P49LFB', 'F', 'B', 20, 25, 19, 'piloto_461_mismatch');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'P49LFB', 'F', 20, 'B', 5);
        $this->seedRemData($upload, 'P49LFB', 'F', 21, 'B', 3);
        $this->seedTechnicalTotal($upload, 'P49LFB', 'F', 19, 'B', 999); // no coincide con 5+3=8

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'piloto_461_mismatch');
        $this->assertSame('failed', $detail['status']);
        $this->assertSame(1, $detail['failed_rows']);
    }

    public function test_absence_of_technical_total_falls_back_to_safe_missing_total_row_behavior(): void
    {
        // Sin fila en rem_technical_totals (ej. total_row=123 configurado
        // en la 461 pero el mecanismo formula-based aun no habia capturado
        // nada para esta carga especifica, o la carga es historica anterior
        // a este cambio): debe comportarse de forma SEGURA -- skip
        // explicito con motivo missing_total_row, NUNCA un fallback
        // silencioso ni un "passed" falso.
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('P49LFB', 'F', 'B', 20, 25, 19, 'piloto_461_sin_tecnico');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'P49LFB', 'F', 20, 'B', 5);
        $this->seedRemData($upload, 'P49LFB', 'F', 21, 'B', 3);
        // Sin seedTechnicalTotal() -- deliberado.

        $this->assertSame(0, RemTechnicalTotal::where('rem_upload_id', $upload->id)->count());

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'piloto_461_sin_tecnico');
        $this->assertSame('skipped', $detail['status']);
        $this->assertSame('missing_total_row', $detail['reason']);
    }

    public function test_technical_total_row_never_appears_in_rem_data(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('P49LFB', 'F', 'B', 20, 25, 19, 'piloto_461_sin_writeback');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'P49LFB', 'F', 20, 'B', 5);
        $this->seedRemData($upload, 'P49LFB', 'F', 21, 'B', 3);
        $this->seedTechnicalTotal($upload, 'P49LFB', 'F', 19, 'B', 8);

        $this->service->execute($upload->id, $structure->id, write: true);

        $this->assertSame(
            0,
            RemData::where('rem_upload_id', $upload->id)->whereRaw("JSON_EXTRACT(data, '$.row_number') = 19")->count(),
            'la fila tecnica (19) nunca debe escribirse de vuelta en rem_data tras la evaluacion'
        );
    }
}
