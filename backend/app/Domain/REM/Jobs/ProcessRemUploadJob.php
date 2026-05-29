<?php

namespace App\Domain\REM\Jobs;

use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Services\RemParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

        try {
            $result = $parser->parse($upload);

            foreach ($result->extractedData as $entry) {
                RemData::create([
                    'rem_upload_id' => $upload->id,
                    'section' => $entry['section'] ?? 'unknown',
                    'data' => $entry,
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

            $upload->update([
                'status' => $result->status,
                'error_report' => $errorReport,
                'processed_at' => now(),
            ]);
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
