<?php

namespace App\Domain\RemParser\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;

class MetadataExtractorService
{
    private const TIPOS_REM = ['A', 'BM', 'BS', 'D', 'P'];

    private const NOMBRE_DEIS_COLS = ['C', 'D', 'E', 'F', 'G', 'H'];

    private const SERIES_MAP = [
        'SA' => 'A',
        'SBM' => 'BM',
        'SBS' => 'BS',
        'SD' => 'D',
        'SP' => 'P',
    ];

    public function extractFromFilename(string $filename): array
    {
        $anio = null;
        $serie = null;
        $tipo = null;

        $baseName = pathinfo($filename, PATHINFO_FILENAME);

        if (preg_match('/(\d{4})/', $baseName, $m)) {
            $anio = (int) $m[1];
        }

        if (preg_match('/REM[_-]?([A-Z]{1,2})/i', $baseName, $m)) {
            $found = strtoupper($m[1]);
            if (in_array($found, self::TIPOS_REM, true)) {
                $tipo = $found;
            }
        }

        foreach (self::SERIES_MAP as $prefix => $value) {
            if (preg_match('/' . $prefix . '/i', $baseName)) {
                $serie = $value;
                break;
            }
        }

        if ($serie === null) {
            if (preg_match('/(\d+)$/', $baseName, $m)) {
                $serie = $m[1];
            }
        }

        return ['anio' => $anio, 'serie' => $serie, 'tipo' => $tipo];
    }

    public function extractFromSheetName(string $sheetName): array
    {
        $anio = null;
        $serie = null;
        $tipo = null;

        if (preg_match('/(\d{4})/', $sheetName, $m)) {
            $anio = (int) $m[1];
        }

        if (preg_match('/REM[_-]?([A-Z]{1,2})/i', $sheetName, $m)) {
            $found = strtoupper($m[1]);
            if (in_array($found, self::TIPOS_REM, true)) {
                $tipo = $found;
            }
        }

        return ['anio' => $anio, 'serie' => $serie, 'tipo' => $tipo];
    }

    public function extract(Spreadsheet $spreadsheet, string $filePath): array
    {
        $filename = basename($filePath);
        $meta = $this->extractFromFilename($filename);

        if ($meta['tipo'] === null) {
            $metaFromSheet = $this->extractFromSheetName($spreadsheet->getActiveSheet()->getTitle());
            $meta = array_merge($meta, array_filter($metaFromSheet, fn($v) => $v !== null));
        }

        $nombres = $spreadsheet->getSheetNames();
        foreach ($nombres as $name) {
            if (strtoupper(trim($name)) === 'NOMBRE') {
                $ws = $spreadsheet->getSheetByName($name);
                if ($ws) {
                    if ($meta['anio'] === null) {
                        $anioVal = $ws->getCell('B7')->getCalculatedValue();
                        if (is_numeric($anioVal)) {
                            $meta['anio'] = (int) $anioVal;
                        }
                    }
                    if ($meta['serie'] === null) {
                        $serieVal = $ws->getCell('B17')->getValue();
                        if (is_string($serieVal) && preg_match('/SERIE\s+(.+)/i', $serieVal, $m)) {
                            $meta['serie'] = trim($m[1]);
                        }
                    }

                    $b3 = $ws->getCell('B3')->getCalculatedValue();
                    if ($b3 !== null && trim((string) $b3) !== '') {
                        $meta['establecimiento'] = trim((string) $b3);
                    }

                    $deisParts = [];
                    foreach (self::NOMBRE_DEIS_COLS as $col) {
                        $val = $ws->getCell($col . '3')->getCalculatedValue();
                        $deisParts[] = is_numeric($val) ? (string) (int) $val : (string) $val;
                    }
                    $code = implode('', $deisParts);
                    if (strlen($code) === 6 && is_numeric($code)) {
                        $meta['codigo_deis'] = $code;
                    }

                    $b6 = $ws->getCell('B6')->getCalculatedValue();
                    if ($b6 !== null) {
                        $monthStr = strtoupper(trim((string) $b6));
                        $monthMap = [
                            'ENERO' => 1, 'ENE' => 1,
                            'FEBRERO' => 2, 'FEB' => 2,
                            'MARZO' => 3, 'MAR' => 3,
                            'ABRIL' => 4, 'ABR' => 4,
                            'MAYO' => 5, 'MAY' => 5,
                            'JUNIO' => 6, 'JUN' => 6,
                            'JULIO' => 7, 'JUL' => 7,
                            'AGOSTO' => 8, 'AGO' => 8,
                            'SEPTIEMBRE' => 9, 'SEP' => 9, 'SETIEMBRE' => 9,
                            'OCTUBRE' => 10, 'OCT' => 10,
                            'NOVIEMBRE' => 11, 'NOV' => 11,
                            'DICIEMBRE' => 12, 'DIC' => 12,
                        ];
                        $monthNum = $monthMap[$monthStr] ?? null;
                        if (!$monthNum && is_numeric($monthStr)) {
                            $monthNum = (int) $monthStr;
                            if ($monthNum < 1 || $monthNum > 12) $monthNum = null;
                        }
                        if ($monthNum) {
                            $meta['mes'] = $monthNum;
                        }
                    }
                }
                break;
            }
        }

        return $meta;
    }
}
