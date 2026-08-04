<?php

namespace App\Domain\RemParser\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;

class SheetDetectorService
{
    private const EXCLUIR = ['NOMBRE', 'CONTROL', 'MACROS'];

    public function detect(Spreadsheet $spreadsheet): array
    {
        $nombres = $spreadsheet->getSheetNames();
        $validas = [];

        foreach ($nombres as $i => $name) {
            $trimmed = strtoupper(trim($name));

            $excluir = false;
            foreach (self::EXCLUIR as $ex) {
                if ($trimmed === $ex) {
                    $excluir = true;
                    break;
                }
            }

            if ($excluir) {
                continue;
            }

            $ws = $spreadsheet->getSheet($i);
            if ($ws->getSheetState() !== \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VISIBLE) {
                continue;
            }

            $validas[] = $name;
        }

        return $validas;
    }
}
