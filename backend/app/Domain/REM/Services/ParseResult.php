<?php

namespace App\Domain\REM\Services;

class ParseResult
{
    public string $status;
    public array $extractedData;
    public array $errors;
    public int $totalRowsProcessed;
    public int $totalCellsParsed;
    public int $totalErrorCells;

    public function __construct(
        string $status = 'failed',
        array $extractedData = [],
        array $errors = [],
        int $totalRowsProcessed = 0,
        int $totalCellsParsed = 0,
        int $totalErrorCells = 0
    ) {
        $this->status = $status;
        $this->extractedData = $extractedData;
        $this->errors = $errors;
        $this->totalRowsProcessed = $totalRowsProcessed;
        $this->totalCellsParsed = $totalCellsParsed;
        $this->totalErrorCells = $totalErrorCells;
    }
}
