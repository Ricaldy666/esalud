<?php

namespace App\Domain\REM\Jobs;

use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Services\RemValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ValidateRemUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(public RemUpload $upload)
    {
    }

    public function handle(RemValidationService $validator): void
    {
        $results = $validator->validate($this->upload);

        $hasErrors = $results->contains(fn ($r) => $r['passed'] === false && $r['severity'] === 'error');

        if ($this->upload->status === 'success' && $hasErrors) {
            $this->upload->update(['status' => 'with_errors']);
        }
    }
}
