<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class RemInspectSheetCommand extends Command
{
    protected $signature = 'rem:inspect-sheet
                            {path : Ruta al archivo Excel}
                            {sheet : Nombre exacto de la hoja}
                            {--rows=20 : Numero de filas a mostrar}
                            {--cols=50 : Numero de columnas a mostrar}
                            {--start=1 : Fila inicial}';

    protected $description = 'Inspecciona el contenido de una hoja especifica de un archivo Excel REM';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (!file_exists($path)) {
            $altPath = base_path($path);
            if (file_exists($altPath)) {
                $path = $altPath;
            } else {
                $this->error("Archivo no encontrado: {$path}");
                return self::FAILURE;
            }
        }

        $sheetName = $this->argument('sheet');
        $maxRows = (int)$this->option('rows');
        $maxCols = (int)$this->option('cols');
        $startRow = (int)$this->option('start');

        $this->info("Archivo: " . basename($path));
        $this->info("Hoja: {$sheetName}");
        $this->newLine();

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(false);
            $reader->setIncludeCharts(false);
            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            $this->error("Error al abrir archivo: " . $e->getMessage());
            return self::FAILURE;
        }

        if (!$spreadsheet->sheetNameExists($sheetName)) {
            $this->error("La hoja '{$sheetName}' no existe.");
            $this->line("Hojas disponibles:");
            foreach ($spreadsheet->getSheetNames() as $name) {
                $this->line("  - {$name}");
            }
            return self::FAILURE;
        }

        $sheet = $spreadsheet->getSheetByName($sheetName);
        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();
        $highestColNum = Coordinate::columnIndexFromString($highestCol);

        $endRow = min($highestRow, $startRow + $maxRows - 1);
        $endColNum = min($highestColNum, $maxCols);

        $this->info("Rango real: A1:{$highestCol}{$highestRow} ({$highestColNum} cols x {$highestRow} rows)");
        $this->info("Mostrando filas {$startRow}-{$endRow}, cols 1-{$endColNum} (A-" . Coordinate::stringFromColumnIndex($endColNum) . ")");
        $this->newLine();

        $headers = ['Fila'];
        for ($col = 1; $col <= $endColNum; $col++) {
            $headers[] = Coordinate::stringFromColumnIndex($col);
        }

        $rows = [];
        for ($row = $startRow; $row <= $endRow; $row++) {
            $rowData = [(string)$row];
            for ($col = 1; $col <= $endColNum; $col++) {
                $colLetter = Coordinate::stringFromColumnIndex($col);
                $cell = $sheet->getCell($colLetter . $row);
                $value = $cell->getCalculatedValue();
                $dataType = $cell->getDataType();

                if ($value === null || $value === '') {
                    $rowData[] = '';
                } else {
                    $display = (string)$value;
                    if (mb_strlen($display) > 30) {
                        $display = mb_substr($display, 0, 30) . '...';
                    }
                    $typeChar = match ($dataType) {
                        DataType::TYPE_FORMULA => 'f',
                        DataType::TYPE_NUMERIC => 'n',
                        DataType::TYPE_STRING => 's',
                        default => '?',
                    };
                    $rowData[] = $display . " [{$typeChar}]";
                }
            }
            $rows[] = $rowData;
        }

        $this->table($headers, $rows);
        $spreadsheet->disconnectWorksheets();

        return self::SUCCESS;
    }
}
