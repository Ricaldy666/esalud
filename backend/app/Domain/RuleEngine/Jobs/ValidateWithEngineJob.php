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

    /**
     * $finalStatus es el estado terminal (success/with_errors) ya decidido por
     * ValidateRemUploadJob (el validador principal) antes de despachar este job.
     * Null cuando el job se construye directamente sin ese contexto (p.ej. tests
     * o invocaciones manuales) -- en ese caso finalize() no toca upload.status,
     * igual que el comportamiento previo a este cambio.
     */
    public function __construct(public int $remUploadId, public ?string $finalStatus = null)
    {
    }

    public function handle(
        RuleEngineService $engine,
        StructureResolverService $resolver,
        FeatureFlagService $featureFlags,
    ): void {
        if (!$featureFlags->get('enabled')) {
            $this->finalize();
            return;
        }

        MemoryProbe::log('validate_with_engine_job.inicio', ['upload_id' => $this->remUploadId]);

        $upload = RemUpload::find($this->remUploadId);
        if (!$upload) {
            $this->finalize();
            return;
        }

        $structureId = $resolver->resolve($upload);
        if ($structureId === null) {
            Log::warning('ValidateWithEngineJob: no structure found for upload', [
                'upload_id' => $this->remUploadId,
                'rem_type' => $upload->rem_type,
                'year' => $upload->year,
            ]);
            $this->finalize();
            return;
        }

        $engine->registerEvaluator(new SumEqualsEvaluator);
        $engine->registerEvaluator(new RequiredAndLeParentEvaluator);

        try {
            // No se recalcula upload.status aqui: este motor es paralelo/complementario
            // a ValidateRemUploadJob (el validador principal), que ya decidio el estado
            // final (success/with_errors) antes de despachar este job. Recalcularlo aqui
            // podria revertir incorrectamente ese resultado (por ejemplo, si el validador
            // principal encontraba errores pero el motor nuevo, con su catalogo de reglas
            // todavia parcial, no encontraba ninguno). finalize() solo APLICA la decision
            // ya tomada, una vez que el motor termino (o determino que no corre). Sus
            // resultados (RuleExecutionLog, RemValidationResult) se siguen escribiendo igual.
            $engine->execute($upload->id, $structureId, true, triggeredBy: 'job', writeResults: true);

            MemoryProbe::log('validate_with_engine_job.fin', ['upload_id' => $this->remUploadId]);
            $this->finalize();
        } catch (\Throwable $e) {
            if (!$featureFlags->get('fail_open')) {
                throw $e;
            }

            Log::warning('ValidateWithEngineJob: engine execution failed (fail_open)', [
                'upload_id' => $this->remUploadId,
                'structure_id' => $structureId,
                'error' => $e->getMessage(),
            ]);
            $this->finalize();
        }
    }

    /**
     * Se ejecuta cuando el job agota sus reintentos sin completar (fail_open=false
     * y el motor sigue fallando). Sin esto, el upload quedaria indefinidamente en
     * 'validating' -- se aplica igualmente la decision del validador principal,
     * porque sus resultados estructurales/funcionales ya son validos y completos
     * independientemente de que el motor nuevo no haya podido correr.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->finalize();
    }

    /**
     * Aplica el estado terminal ya decidido por ValidateRemUploadJob. El guard
     * where('status','validating') hace la operacion idempotente/segura ante
     * llamadas repetidas (p.ej. reintento del job) sin sobrescribir un estado
     * terminal ya aplicado por una ejecucion anterior.
     */
    private function finalize(): void
    {
        if ($this->finalStatus === null) {
            return;
        }

        RemUpload::where('id', $this->remUploadId)
            ->where('status', 'validating')
            ->update(['status' => $this->finalStatus]);
    }
}
