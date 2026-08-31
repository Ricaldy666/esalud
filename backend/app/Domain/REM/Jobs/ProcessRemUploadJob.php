<?php

namespace App\Domain\REM\Jobs;

use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemTechnicalTotal;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Services\RemParserService;
use App\Support\MemoryProbe;
use Illuminate\Bus\Queueable;
use App\Domain\REM\Jobs\ValidateRemUploadJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessRemUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(public int $remUploadId) {}

    public function handle(RemParserService $parser): void
    {
        $upload = RemUpload::with('remTemplate')->find($this->remUploadId);
        if (!$upload) {
            return;
        }

        $upload->update([
            'status' => 'processing',
            'processed_at' => null,
            'error_report' => null,
        ]);

        MemoryProbe::log('process_job.antes_parse', ['upload_id' => $upload->id]);

        try {
            $result = $parser->parse($upload);

            MemoryProbe::log('process_job.despues_parse', [
                'upload_id' => $upload->id,
                'extracted_data_count' => count($result->extractedData),
            ]);

            if (empty($result->extractedData)) {
                $upload->update([
                    'status' => 'rejected',
                    'error_report' => [
                        'summary' => [
                            'total_rows_processed' => 0,
                            'error' => 'El archivo no contiene datos válidos después del procesamiento. Verifique el formato, las secciones y las columnas del archivo Excel.',
                        ],
                        'errors' => $result->errors,
                    ],
                    'processed_at' => now(),
                ]);
                return;
            }

            foreach ($result->extractedData as $entry) {
                RemData::create([
                    'rem_upload_id' => $upload->id,
                    'section' => $entry['section'] ?? 'unknown',
                    'data' => $entry,
                ]);
            }

            MemoryProbe::log('process_job.despues_persistencia_rem_data', [
                'upload_id' => $upload->id,
                'rows_persisted' => count($result->extractedData),
            ]);

            // Fase 3A (CLAUDE.md punto 17.6): persistencia auditable de las
            // filas TOTAL tecnicas excluidas de rem_data (deuda tecnica #5).
            // Aislada en su propia transaccion -- ni todo ni nada de esta
            // tabla nueva, independiente de rem_data (que no tiene esa
            // proteccion hoy, ver auditoria de lifecycle en CLAUDE.md). No
            // conecta con el motor de reglas ni con ninguna otra parte del
            // sistema (Fase 3B/3C, no implementadas).
            if (!empty($result->technicalTotals)) {
                DB::transaction(function () use ($upload, $result) {
                    foreach ($result->technicalTotals as $tt) {
                        RemTechnicalTotal::create([
                            'rem_upload_id' => $upload->id,
                            'sheet' => $tt['sheet'],
                            'rem_section_code' => $tt['rem_section_code'],
                            'row_number' => $tt['row_number'],
                            'concept' => $tt['concept'],
                            'total' => $tt['total'],
                            'values' => $tt['values'],
                            'exclusion_reason' => $tt['exclusion_reason'],
                        ]);
                    }
                });

                MemoryProbe::log('process_job.despues_persistencia_technical_totals', [
                    'upload_id' => $upload->id,
                    'rows_persisted' => count($result->technicalTotals),
                ]);
            }

            $errorReport = [
                'summary' => [
                    'total_rows_processed' => $result->totalRowsProcessed,
                    'total_cells_parsed' => $result->totalCellsParsed,
                    'total_error_cells' => $result->totalErrorCells,
                ],
                'errors' => $result->errors,
            ];

            // El estado final (success/with_errors) lo decide unicamente
            // ValidateRemUploadJob al terminar -- $result->status es solo el
            // resultado del parseo (PhpSpreadsheet), no de la validacion, y
            // escribirlo aqui como estado del upload hacia que el frontend
            // (que deja de sondear en estados terminales) mostrara un
            // resultado prematuro antes de que corriera ninguna regla.
            $upload->update([
                'status' => 'validating',
                'error_report' => $errorReport,
                'processed_at' => now(),
            ]);

            MemoryProbe::log('process_job.despues_generacion_informe', ['upload_id' => $upload->id]);

            ValidateRemUploadJob::dispatch($upload);
        } catch (\Throwable $e) {
            $upload->update([
                'status' => 'failed',
                'error_report' => [
                    'summary' => [
                        'fatal_error' => true,
                        'message' => $e->getMessage(),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine(),
                    ],
                ],
                'processed_at' => now(),
            ]);

            throw $e;
        }
    }
}
