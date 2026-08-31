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
    /**
     * Filas TOTAL/subtotal tecnicas excluidas de $extractedData por los
     * mecanismos #6/#8/#11/#12 (ver RemParserService::parseSheet()), pero
     * ya calculadas en memoria -- Fase 3A (CLAUDE.md punto 17.6). Cada
     * entrada trae 'sheet', 'rem_section_code', 'row_number', 'concept',
     * 'total', 'values', 'exclusion_reason'. No se persisten en rem_data.
     */
    public array $technicalTotals;

    public function __construct(
        string $status = 'failed',
        array $extractedData = [],
        array $errors = [],
        int $totalRowsProcessed = 0,
        int $totalCellsParsed = 0,
        int $totalErrorCells = 0,
        array $technicalTotals = []
    ) {
        $this->status = $status;
        $this->extractedData = $extractedData;
        $this->errors = $errors;
        $this->totalRowsProcessed = $totalRowsProcessed;
        $this->totalCellsParsed = $totalCellsParsed;
        $this->totalErrorCells = $totalErrorCells;
        $this->technicalTotals = $technicalTotals;
    }
}
