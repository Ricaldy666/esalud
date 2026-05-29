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
