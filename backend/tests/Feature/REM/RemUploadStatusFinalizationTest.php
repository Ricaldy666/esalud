<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Jobs\ValidateRemUploadJob;
use App\Domain\REM\Models\RemTemplate;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Models\RemValidationResult;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Jobs\ValidateWithEngineJob;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleEngineSetting;
use App\Domain\RuleEngine\Models\RuleExecutionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresion para la carrera de estados entre ValidateRemUploadJob y
 * ValidateWithEngineJob: antes de este fix, el upload entraba a un estado
 * terminal (success/with_errors) ANTES de que el motor de reglas terminara
 * de escribir sus resultados, y el frontend dejaba de hacer polling en ese
 * momento -- capturando un validation_summary parcial. Ver CLAUDE.md /
 * handoff de sesion para el contexto completo del hallazgo.
 */
class RemUploadStatusFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function createUpload(array $overrides = []): RemUpload
    {
        $template = RemTemplate::create([
            'year' => 2026,
            'rem_type' => 'A',
            'version' => '1',
            'config' => ['validation_rules' => []],
            'is_active' => true,
        ]);

        $healthCenter = HealthCenter::create([
            'name' => 'CESFAM Test',
            'code_deis' => 'HC_STATUS_TEST',
            'type' => 'Cesfam',
        ]);

        return RemUpload::create(array_merge([
            'health_center_id' => $healthCenter->id,
            'user_id' => $this->user->id,
            'rem_template_id' => $template->id,
            'year' => 2026,
            'month' => 7,
            'rem_type' => 'A',
            'original_filename' => 'test.xlsx',
            'stored_path' => 'rem/2026/07/test.xlsx',
            'file_size' => 1234,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            // Estado real que deja ProcessRemUploadJob antes de despachar
            // ValidateRemUploadJob -- ver comentario en ValidateRemUploadJob::handle().
            'status' => 'validating',
        ], $overrides));
    }

    public function test_status_stays_non_terminal_before_engine_finishes_when_engine_enabled(): void
    {
        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'true']);

        $upload = $this->createUpload();

        Queue::fake();

        app()->call([new ValidateRemUploadJob($upload), 'handle']);

        $upload->refresh();
        $this->assertSame('validating', $upload->status, 'El upload no debe entrar a estado terminal mientras el motor todavia no corrio.');

        Queue::assertPushed(ValidateWithEngineJob::class, function ($job) use ($upload) {
            return $job->remUploadId === $upload->id && in_array($job->finalStatus, ['success', 'with_errors'], true);
        });
    }

    public function test_status_becomes_terminal_after_engine_finishes(): void
    {
        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'true']);

        $upload = $this->createUpload();

        // Estructura activa para la serie/anio del upload, sin reglas
        // vinculadas -- el motor corre de verdad (camino de exito real del
        // try, no un early-return) y no encuentra nada que evaluar.
        RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_status_finalization_test',
            'status' => 'active',
            'estructura' => ['forms' => []],
        ]);

        Queue::fake();

        app()->call([new ValidateRemUploadJob($upload), 'handle']);

        $upload->refresh();
        $this->assertSame('validating', $upload->status);

        /** @var ValidateWithEngineJob|null $dispatchedJob */
        $dispatchedJob = null;
        Queue::assertPushed(ValidateWithEngineJob::class, function ($job) use (&$dispatchedJob) {
            $dispatchedJob = $job;
            return true;
        });
        $this->assertNotNull($dispatchedJob);

        // Simula el worker asincronico ejecutando el job que quedo en cola.
        app()->call([$dispatchedJob, 'handle']);

        $upload->refresh();
        $this->assertSame($dispatchedJob->finalStatus, $upload->status);
        $this->assertContains($upload->status, ['success', 'with_errors']);
    }

    public function test_status_endpoint_validation_summary_matches_definitive_validation_summary(): void
    {
        $upload = $this->createUpload(['status' => 'success']);

        // Fuente legacy: 2 filas del mismo rule_key (cuenta como 1 regla
        // aprobada agrupada) + 1 rule_key distinto fallido.
        RemValidationResult::create([
            'rem_upload_id' => $upload->id,
            'rule_key' => 'legacy_rule_ok',
            'rule_type' => 'structural',
            'severity' => 'error',
            'passed' => true,
            'message' => 'ok',
        ]);
        RemValidationResult::create([
            'rem_upload_id' => $upload->id,
            'rule_key' => 'legacy_rule_ok',
            'rule_type' => 'structural',
            'severity' => 'error',
            'passed' => true,
            'message' => 'ok fila 2',
        ]);
        RemValidationResult::create([
            'rem_upload_id' => $upload->id,
            'rule_key' => 'legacy_rule_failed',
            'rule_type' => 'structural',
            'severity' => 'error',
            'passed' => false,
            'message' => 'fallo',
        ]);

        // Fuente motor: una regla real con su log de ejecucion.
        $rule = Rule::create([
            'rule_key' => 'engine_rule_failed',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Engine Rule Failed',
            'config' => ['source_letters' => ['A'], 'target_column' => 'B'],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => ['sheet' => 'A01'],
        ]);
        RuleExecutionLog::create([
            'rule_id' => $rule->id,
            'rule_key' => $rule->rule_key,
            'rem_upload_id' => $upload->id,
            'execution_id' => 'exec-1',
            'status' => 'failed',
            'total_rows' => 1,
            'passed_rows' => 0,
            'failed_rows' => 1,
            'triggered_by' => 'job',
        ]);

        Sanctum::actingAs($this->user);

        $statusResponse = $this->getJson("/api/v1/rem-uploads/{$upload->id}/status")->json('data.validation_summary');
        $definitive = $this->getJson("/api/v1/rule-engine/uploads/{$upload->id}/validation-summary")->json('data');

        $this->assertNotNull($statusResponse);
        $this->assertSame($definitive['total_rules'], $statusResponse['total_rules']);
        $this->assertSame($definitive['applicable'], $statusResponse['applicable']);
        $this->assertSame($definitive['passed'], $statusResponse['passed']);
        $this->assertSame($definitive['failed'], $statusResponse['failed']);
        $this->assertSame($definitive['cumplimiento_porcentaje'], $statusResponse['compliance']);

        // Y confirma que la agrupacion por rule_key realmente esta pasando:
        // 2 reglas legacy (1 aprobada agrupando sus 2 filas, 1 fallida) +
        // 1 regla del motor (fallida) = 3 reglas, no el conteo crudo de
        // filas legacy (que hubiera dado 3 filas + 1 log = 4).
        $this->assertSame(3, $statusResponse['total_rules']);
        $this->assertSame(1, $statusResponse['passed']);
        $this->assertSame(2, $statusResponse['failed']);
    }

    public function test_status_becomes_terminal_immediately_when_engine_disabled(): void
    {
        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'false']);

        $upload = $this->createUpload();

        Queue::fake();

        app()->call([new ValidateRemUploadJob($upload), 'handle']);

        $upload->refresh();
        $this->assertSame('success', $upload->status);

        Queue::assertNotPushed(ValidateWithEngineJob::class);
    }

    public function test_engine_permanent_failure_does_not_leave_upload_stuck_in_validating(): void
    {
        $upload = $this->createUpload();

        $job = new ValidateWithEngineJob($upload->id, 'with_errors');
        $job->failed(new \RuntimeException('motor caido definitivamente'));

        $upload->refresh();
        $this->assertSame('with_errors', $upload->status);
    }

    public function test_finalize_does_not_clobber_an_already_terminal_status(): void
    {
        $upload = $this->createUpload(['status' => 'success']);

        // Un segundo finalize (p.ej. reintento del job) con un finalStatus
        // distinto no debe pisar el estado terminal ya aplicado.
        $job = new ValidateWithEngineJob($upload->id, 'with_errors');
        $job->failed(new \RuntimeException('reintento tardio'));

        $upload->refresh();
        $this->assertSame('success', $upload->status);
    }
}
