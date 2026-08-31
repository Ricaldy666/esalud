<?php

namespace App\Console\Commands;

use App\Domain\REM\Jobs\ProcessRemUploadJob;
use App\Domain\REM\Models\RemUpload;
use Illuminate\Console\Command;

class RemReprocessCommand extends Command
{
    protected $signature = 'rem:reprocess {uploadId : ID del rem_upload a reprocesar}';
    protected $description = 'Re-encola un RemUpload para procesamiento desde cero';

    public function handle(): int
    {
        $uploadId = (int)$this->argument('uploadId');
        $upload = RemUpload::find($uploadId);

        if (!$upload) {
            $this->error("Upload #{$uploadId} no encontrado");
            return self::FAILURE;
        }

        $existingDataCount = $upload->remData()->count();
        if ($existingDataCount > 0) {
            $upload->remData()->delete();
            $this->line("Datos previos eliminados: {$existingDataCount} registros");
        }

        // Fase 3A (CLAUDE.md punto 17.6): rem_technical_totals debe limpiarse
        // igual que rem_data antes de reprocesar -- de lo contrario, el
        // reparseo insertaria filas duplicadas y violaria la restriccion
        // unique(rem_upload_id, sheet, rem_section_code, row_number).
        $existingTechnicalTotalsCount = $upload->technicalTotals()->count();
        if ($existingTechnicalTotalsCount > 0) {
            $upload->technicalTotals()->delete();
            $this->line("TOTAL tecnicos previos eliminados: {$existingTechnicalTotalsCount} registros");
        }

        $upload->update([
            'status' => 'pending',
            'error_report' => null,
            'processed_at' => null,
        ]);

        ProcessRemUploadJob::dispatch($upload->id);

        $this->info("Job re-encolado para upload #{$upload->id}");
        $this->line("  Archivo: {$upload->original_filename}");
        $this->line("  Status: pending -> processing (cuando corra queue:work)");

        return self::SUCCESS;
    }
}
