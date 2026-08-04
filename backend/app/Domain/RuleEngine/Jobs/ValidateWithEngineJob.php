<?php

namespace App\Domain\RuleEngine\Jobs;

use App\Domain\REM\Models\RemUpload;
use App\Domain\RuleEngine\Evaluators\RequiredAndLeParentEvaluator;
use App\Domain\RuleEngine\Evaluators\SumEqualsEvaluator;
use App\Domain\RuleEngine\Services\FeatureFlagService;
use App\Domain\RuleEngine\Services\RuleEngineService;
use App\Domain\RuleEngine\Services\StructureResolverService;
use App\Support\MemoryProbe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ValidateWithEngineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(public int $remUploadId)
    {
    }

    public function handle(
        RuleEngineService $engine,
        StructureResolverService $resolver,
        FeatureFlagService $featureFlags,
    ): void {
        if (!$featureFlags->get('enabled')) {
            return;
        }

        MemoryProbe::log('validate_with_engine_job.inicio', ['upload_id' => $this->remUploadId]);

        $upload = RemUpload::find($this->remUploadId);
        if (!$upload) {
            return;
        }

        $structureId = $resolver->resolve($upload);
        if ($structureId === null) {
            Log::warning('ValidateWithEngineJob: no structure found for upload', [
                'upload_id' => $this->remUploadId,
                'rem_type' => $upload->rem_type,
                'year' => $upload->year,
            ]);
            return;
        }

        $engine->registerEvaluator(new SumEqualsEvaluator);
        $engine->registerEvaluator(new RequiredAndLeParentEvaluator);

        try {
            // No se actualiza upload.status aqui: este motor es paralelo/complementario
            // a ValidateRemUploadJob (el validador principal), que ya dejo el estado
            // final (success/with_errors) antes de despachar este job. Sobrescribirlo
            // aqui podia revertir incorrectamente ese resultado (por ejemplo, si el
            // validador principal encontraba errores pero el motor nuevo, con su
            // catalogo de reglas todavia parcial, no encontraba ninguno). Sus resultados
            // (RuleExecutionLog, RemValidationResult) se siguen escribiendo igual.
            $engine->execute($upload->id, $structureId, true, triggeredBy: 'job', writeResults: true);

            MemoryProbe::log('validate_with_engine_job.fin', ['upload_id' => $this->remUploadId]);
        } catch (\Throwable $e) {
            if (!$featureFlags->get('fail_open')) {
                throw $e;
            }

            Log::warning('ValidateWithEngineJob: engine execution failed (fail_open)', [
                'upload_id' => $this->remUploadId,
                'structure_id' => $structureId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
