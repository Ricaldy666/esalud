<?php

namespace App\Domain\REM\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemTemplate;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\File;

class RemUploadPreviewService
{
    private const SHEET_PATTERNS = [
        'A' => ['/^A\d{2,3}$/', '/^A\d{2,3}[A-Z]?$/'],
        'BM' => ['/^BM/', '/^B[M]\s/'],
        'BS' => ['/^BS/', '/^B[S]\s/'],
        'D' => ['/^D\d/', '/^D\s/'],
        'P' => ['/^P\d/', '/^P\s/'],
    ];

    private const REM_TYPE_LABELS = [
        'A' => 'Serie A - Consultas Médicas',
        'BM' => 'Serie BM - Salud Mental',
        'BS' => 'Serie BS - Salud Bucal',
        'D' => 'Serie D - Discapacidad',
        'P' => 'Serie P - Programas',
    ];

    private const NOMBRE_DEIS_COLS = ['C', 'D', 'E', 'F', 'G', 'H'];

    private const MONTH_NAMES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    private const MONTH_ALIASES = [
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

    public function preview(UploadedFile $file): array
    {
        $warnings = [];
        $errors = [];

        $filename = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $sizeMb = round($file->getSize() / 1048576, 1);

        // --- 1. Extension and macros detection ---
        if (!in_array($extension, ['xlsx', 'xlsm', 'xls'])) {
            $errors[] = 'Formato de archivo no soportado. Use archivos .xlsx, .xlsm o .xls.';
        }

        $hasMacros = $extension === 'xlsm';

        if (!$hasMacros && $extension === 'xlsx') {
            $warnings[] = 'El archivo no contiene macros (.xlsx). Verifique que corresponda a la plantilla oficial REM.';
        } elseif ($extension === 'xls') {
            $warnings[] = 'El archivo está en formato .xls (heredado). Se recomienda usar .xlsm oficial.';
        }

        // --- 2. Open Excel ---
        $isValidExcel = false;
        $sheetNames = [];
        $cells = [];

        try {
            File::setUseUploadTempDirectory(true);
            $spreadsheet = IOFactory::load($file->getPathname());
            $isValidExcel = true;

            foreach ($spreadsheet->getSheetNames() as $name) {
                $sheetNames[] = $name;
            }

            $cells = $this->extractRelevantCells($spreadsheet);

            $nombreSheetData = $this->extractFromNombreSheet($spreadsheet);

            $spreadsheet->disconnectWorksheets();
        } catch (\Exception $e) {
            $errors[] = 'No se pudo leer el archivo Excel. Verifique que no esté dañado o protegido.';
        }

        // --- 3. Detect serie from sheet names ---
        $serieDetected = null;
        if (!empty($sheetNames)) {
            $serieDetected = $this->detectSerie($sheetNames);
            if (!$serieDetected) {
                $serieDetected = $this->detectSerieFromTitle($cells);
            }
        }

        // --- 4. Detect period from Excel content ---
        $contentDetection = $this->detectPeriodFromContent($cells);

        // --- 5. Parse filename ---
        $filenameDetection = $this->parseFilename($filename);

        // --- 6. Extract establishment data from NOMBRE sheet ---
        $establecimientoSheet = $nombreSheetData['establishment_name'] ?? null;
        $deisCodeFromSheet = $nombreSheetData['deis_code'] ?? null;
        $monthFromSheet = $nombreSheetData['month'] ?? null;
        $yearFromSheet = $nombreSheetData['year'] ?? null;

        // --- 7. Determine authoritative DEIS: sheet wins, filename is cross-check ---
        $deisCode = $deisCodeFromSheet ?? ($filenameDetection['deis_code'] ?? null);
        $healthCenterDetected = null;

        if ($deisCodeFromSheet && $filenameDetection['deis_code'] && $deisCodeFromSheet !== $filenameDetection['deis_code']) {
            // --- Sheet DEIS differs from filename DEIS: consolidated message ---
            $hcFromFilename = $this->lookupHealthCenter($filenameDetection['deis_code']);
            $hcFromSheet = $this->lookupHealthCenter($deisCodeFromSheet);
            $estName = $establecimientoSheet ? trim($establecimientoSheet) : null;

            $parts = [];
            $parts[] = "Inconsistencia en la identificación del establecimiento.";
            $parts[] = "El código DEIS registrado en la hoja NOMBRE del archivo es {$deisCodeFromSheet}, pero el nombre del archivo indica {$filenameDetection['deis_code']}.";

            if ($hcFromFilename) {
                $parts[] = "Para {$hcFromFilename['name']}, el código registrado en el sistema es {$hcFromFilename['code']}.";
            }

            $errors[] = implode(' ', $parts);

            $instruction = "Abra el archivo Excel, vaya a la hoja NOMBRE y corrija el código DEIS en las celdas C3:H3. ";
            if ($hcFromFilename) {
                $digits = str_split($hcFromFilename['code']);
                $instruction .= "Para {$hcFromFilename['name']} debe quedar " . implode('-', $digits) . ".";
            } else {
                $instruction .= "Verifique el código correcto del establecimiento.";
            }
            $instruction .= " Guarde el archivo y vuelva a cargarlo.";
            $errors[] = $instruction;
        } elseif ($deisCode) {
            $healthCenterDetected = $this->lookupHealthCenter($deisCode);

            if (!$healthCenterDetected) {
                $errors[] = "El establecimiento con código DEIS {$deisCode} no está registrado en el sistema. Debe ser registrado antes de cargar el archivo.";
            } elseif ($establecimientoSheet) {
                $sheetName = trim($establecimientoSheet);
                $dbName = trim($healthCenterDetected['name']);
                if (strcasecmp($sheetName, $dbName) !== 0) {
                    $warnings[] = "El nombre del establecimiento en el archivo ({$sheetName}) no coincide con el registrado en el sistema ({$dbName}). Verifique antes de continuar.";
                }
            }
        } else {
            $errors[] = 'No se detectó código DEIS ni en la hoja NOMBRE ni en el nombre del archivo.';
        }

        // --- 9. Validate filename vs content consistency ---
        $consistencyWarnings = $this->checkConsistency($filenameDetection, $contentDetection, $sheetNames);
        $warnings = array_merge($warnings, $consistencyWarnings);

        // --- 10. Determine final values (sheet period has priority) ---
        $monthDetected = $monthFromSheet ?? $contentDetection['month'] ?? ($filenameDetection['month'] ?? null);
        $monthNameDetected = $monthDetected ? (self::MONTH_NAMES[$monthDetected] ?? null) : null;
        $yearDetected = $yearFromSheet ?? $contentDetection['year'] ?? (int) now()->format('Y');

        if (!$monthDetected) {
            $warnings[] = 'No se pudo detectar el mes dentro del Excel. Revise manualmente antes de confirmar.';
        }

        $periodDetected = $yearDetected && $monthDetected
            ? $yearDetected . '-' . str_pad($monthDetected, 2, '0', STR_PAD_LEFT)
            : ($yearDetected ? (string) $yearDetected : now()->format('Y-m'));

        $periodLabel = $monthNameDetected && $yearDetected
            ? $monthNameDetected . ' ' . $yearDetected
            : ($yearDetected ? (string) $yearDetected : '');

        if (!$yearFromSheet && !$monthFromSheet) {
            if ($filenameDetection['month'] && $monthDetected) {
                $warnings[] = 'El período fue detectado desde el nombre del archivo. No se encontraron datos de mes y año en la hoja NOMBRE del Excel.';
            } else {
                $warnings[] = 'No se pudo detectar el año ni el mes en la hoja NOMBRE del Excel. Verifique las celdas B6 (mes) y B7 (año).';
            }
        }

        // --- 11. Detect template version ---
        $finalSerie = $serieDetected ?? $filenameDetection['serie'] ?? null;
        $versionDetected = null;
        $versionActive = null;
        $versionStatus = null;

        if ($finalSerie && $yearDetected) {
            $versionDetected = "REM {$yearDetected}";

            $latestTemplate = RemTemplate::active()
                ->where('rem_type', $finalSerie)
                ->orderBy('year', 'desc')
                ->first();

            if ($latestTemplate) {
                $versionActive = "REM {$latestTemplate->year}";
                $versionStatus = ($latestTemplate->year === $yearDetected) ? 'current' : 'outdated';
            } else {
                $versionStatus = 'no_template';
            }
        } else {
            $versionDetected = 'No identificada';
            $versionStatus = 'unknown';
        }

        if ($versionStatus === 'outdated') {
            $errors[] = "La versión del archivo ({$versionDetected}) no corresponde a la plantilla vigente ({$versionActive}). Debe descargar la versión vigente desde el sistema y volver a cargar el archivo.";
        } elseif ($versionStatus === 'no_template') {
            $errors[] = "No hay una plantilla activa para la serie {$finalSerie} en el sistema. No es posible validar este archivo.";
        } elseif ($versionStatus === 'unknown') {
            $errors[] = "No se pudo identificar la versión del archivo. Verifique que la hoja NOMBRE contenga el año en la celda B7 y vuelva a cargarlo.";
        }

        // --- 12. Build response ---
        $remTypeLabel = $finalSerie
            ? (self::REM_TYPE_LABELS[$finalSerie] ?? "Serie {$finalSerie}")
            : null;

        return [
            'filename' => $filename,
            'extension' => $extension,
            'size_mb' => $sizeMb,
            'is_valid_excel' => $isValidExcel,
            'has_macros' => $hasMacros,
            'serie_detected' => $finalSerie,
            'rem_type_detected' => $remTypeLabel,
            'month_detected' => $monthDetected,
            'month_name_detected' => $monthNameDetected,
            'year_detected' => $yearDetected,
            'period_detected' => $periodDetected,
            'period_label' => $periodLabel,
            'upload_date' => now()->format('Y-m-d'),
            'health_center_detected' => $healthCenterDetected,
            'sheets_detected' => count($sheetNames),
            'establishment_sheet' => $establecimientoSheet,
            'deis_code_sheet' => $deisCodeFromSheet,
            'filename_detection' => $filenameDetection['deis_code'] ? [
                'deis_code' => $filenameDetection['deis_code'],
                'serie' => $filenameDetection['serie'],
                'month' => $filenameDetection['month'],
            ] : null,
            'content_detection' => [
                'serie' => $serieDetected,
                'month' => $contentDetection['month'],
                'year' => $contentDetection['year'],
            ],
            'version_detected' => $versionDetected,
            'version_active' => $versionActive,
            'version_status' => $versionStatus,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    private function extractFromNombreSheet($spreadsheet): array
    {
        $result = [
            'establishment_name' => null,
            'deis_code' => null,
            'month' => null,
            'year' => null,
        ];

        foreach ($spreadsheet->getSheetNames() as $name) {
            if (strtoupper(trim($name)) !== 'NOMBRE') {
                continue;
            }

            try {
                $ws = $spreadsheet->getSheetByName($name);
                if (!$ws) break;

                $b3 = $ws->getCell('B3')->getCalculatedValue();
                if ($b3 !== null && trim((string) $b3) !== '') {
                    $result['establishment_name'] = trim((string) $b3);
                }

                $deisParts = [];
                foreach (self::NOMBRE_DEIS_COLS as $col) {
                    $val = $ws->getCell($col . '3')->getCalculatedValue();
                    $deisParts[] = is_numeric($val) ? (string) (int) $val : (string) $val;
                }
                $code = implode('', $deisParts);
                if (strlen($code) === 6 && is_numeric($code)) {
                    $result['deis_code'] = $code;
                }

                $b6 = $ws->getCell('B6')->getCalculatedValue();
                if ($b6 !== null) {
                    $monthStr = strtoupper(trim((string) $b6));
                    $monthNum = self::MONTH_ALIASES[$monthStr] ?? null;
                    if (!$monthNum && is_numeric($monthStr)) {
                        $monthNum = (int) $monthStr;
                        if ($monthNum < 1 || $monthNum > 12) $monthNum = null;
                    }
                    $result['month'] = $monthNum;
                }

                $b7 = $ws->getCell('B7')->getCalculatedValue();
                if ($b7 !== null && is_numeric($b7)) {
                    $year = (int) $b7;
                    if ($year >= 2015 && $year <= 2030) {
                        $result['year'] = $year;
                    }
                }
            } catch (\Exception $e) {
            }

            break;
        }

        return $result;
    }

    private function extractRelevantCells($spreadsheet): array
    {
        $results = [];

        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            if (count($results) >= 20) break;

            try {
                $sheet = $spreadsheet->getSheetByName($sheetName);
                if (!$sheet) continue;

                $highestRow = min($sheet->getHighestRow(), 30);
                $highestColumn = $sheet->getHighestColumn();

                for ($row = 1; $row <= $highestRow; $row++) {
                    $rowData = $sheet->rangeToArray(
                        "A{$row}:{$highestColumn}",
                        null,
                        true,
                        false
                    );

                    if (!empty($rowData[0])) {
                        $rowValues = array_filter($rowData[0], function ($v) {
                            return $v !== null && trim((string) $v) !== '';
                        });

                        if (!empty($rowValues)) {
                            $results[] = [
                                'sheet' => $sheetName,
                                'row' => $row,
                                'values' => $rowData[0],
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $results;
    }

    private function detectPeriodFromContent(array $cells): array
    {
        $result = [
            'month' => null,
            'year' => null,
        ];

        $allText = '';

        foreach ($cells as $cellGroup) {
            $line = implode(' ', array_map(function ($v) {
                return strtoupper(trim((string) $v));
            }, $cellGroup['values']));

            $allText .= ' ' . $line;

            // Detect month patterns
            $monthPatterns = [
                '/MES\s*[:.]?\s*([A-ZÁÉÍÓÚÑ]+)/',
                '/MES\s+([A-ZÁÉÍÓÚÑ]+)/',
                '/MES[:\s]+([A-ZÁÉÍÓÚÑ]+)/',
                '/MES\s*[:.]?\s*(\d{1,2})/',
            ];

            foreach ($monthPatterns as $pattern) {
                if (preg_match($pattern, $line, $m)) {
                    $value = trim($m[1]);
                    if (is_numeric($value)) {
                        $monthNum = (int) $value;
                        if ($monthNum >= 1 && $monthNum <= 12) {
                            $result['month'] = $monthNum;
                        }
                    } else {
                        $monthNum = self::MONTH_ALIASES[$value] ?? null;
                        if ($monthNum) {
                            $result['month'] = $monthNum;
                        }
                    }
                }
            }

            // Detect year patterns
            $yearPatterns = [
                '/AÑO\s*[:.]?\s*(\d{4})/',
                '/AÑO\s+(\d{4})/',
                '/ANO\s*[:.]?\s*(\d{4})/',
                '/ANO\s+(\d{4})/',
                '/AÑO[:\s]+(\d{4})/',
                '/ANO[:\s]+(\d{4})/',
                '/PERIODO\s*[:.]?\s*(\d{4})/',
                '/PERÍODO\s*[:.]?\s*(\d{4})/',
                '/PERIODO\s+(\d{4})/',
                '/PERÍODO\s+(\d{4})/',
            ];

            foreach ($yearPatterns as $pattern) {
                if (preg_match($pattern, $line, $m)) {
                    $yearVal = (int) $m[1];
                    if ($yearVal >= 2015 && $yearVal <= 2030) {
                        $result['year'] = $yearVal;
                    }
                }
            }

            // Detect period as YYYY-MM
            if (preg_match('/PERIODO\s*[:.]?\s*(\d{4})[\s\/\-]+(\d{1,2})/', $line, $m)) {
                $y = (int) $m[1];
                $mo = (int) $m[2];
                if ($y >= 2015 && $y <= 2030 && $mo >= 1 && $mo <= 12) {
                    $result['year'] = $y;
                    $result['month'] = $mo;
                }
            }

            if (preg_match('/PERÍODO\s*[:.]?\s*(\d{4})[\s\/\-]+(\d{1,2})/', $line, $m)) {
                $y = (int) $m[1];
                $mo = (int) $m[2];
                if ($y >= 2015 && $y <= 2030 && $mo >= 1 && $mo <= 12) {
                    $result['year'] = $y;
                    $result['month'] = $mo;
                }
            }
        }

        // Also try to find month/year from "MM/YYYY" patterns
        if (!$result['month'] || !$result['year']) {
            foreach ($cells as $cellGroup) {
                $line = implode(' ', array_map(function ($v) {
                    return trim((string) $v);
                }, $cellGroup['values']));

                if (preg_match('/(\d{1,2})\s*\/\s*(\d{4})/', $line, $m)) {
                    $mo = (int) $m[1];
                    $y = (int) $m[2];
                    if ($y >= 2015 && $y <= 2030 && $mo >= 1 && $mo <= 12) {
                        if (!$result['month']) $result['month'] = $mo;
                        if (!$result['year']) $result['year'] = $y;
                    }
                }
            }
        }

        return $result;
    }

    private function detectSerie(array $sheetNames): ?string
    {
        $matches = [];

        foreach ($sheetNames as $name) {
            $trimmed = trim($name);
            foreach (self::SHEET_PATTERNS as $serie => $patterns) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $trimmed)) {
                        $matches[$serie] = ($matches[$serie] ?? 0) + 1;
                        break 2;
                    }
                }
            }
        }

        if (empty($matches)) return null;

        arsort($matches);

        if (count($matches) > 1) {
            // Multiple series detected - warn but return the most frequent
        }

        return array_key_first($matches);
    }

    private function detectSerieFromTitle(array $cells): ?string
    {
        $patterns = [
            ['serie' => 'A', 'pattern' => '/REM\s*[-]\s*A\d{2,3}/'],
            ['serie' => 'A', 'pattern' => '/REM\s*[-]\s*A[^A-Z]/'],
            ['serie' => 'BM', 'pattern' => '/REM\s*[-]\s*BM/'],
            ['serie' => 'BS', 'pattern' => '/REM\s*[-]\s*BS/'],
            ['serie' => 'D', 'pattern' => '/REM\s*[-]\s*D/'],
            ['serie' => 'P', 'pattern' => '/REM\s*[-]\s*P/'],
        ];

        foreach ($cells as $cellGroup) {
            foreach ($cellGroup['values'] as $value) {
                $text = strtoupper(trim((string) $value));
                foreach ($patterns as $entry) {
                    if (preg_match($entry['pattern'], $text)) {
                        return $entry['serie'];
                    }
                }
            }
        }

        return null;
    }

    private function parseFilename(string $filename): array
    {
        $result = [
            'deis_code' => null,
            'serie' => null,
            'month' => null,
        ];

        // Pattern: {DEIS 6 digits}{Serie 1 char}{Month 2 digits}.ext
        // e.g., 102302A05.xlsm
        if (preg_match('/^(\d{6})([A-Za-z]{1,2})(\d{2})/', $filename, $m)) {
            $result['deis_code'] = $m[1];
            $result['serie'] = strtoupper($m[2]);
            $monthNum = (int) $m[3];
            if ($monthNum >= 1 && $monthNum <= 12) {
                $result['month'] = $monthNum;
            }
        }

        // Fallback: just extract DEIS
        if (!$result['deis_code']) {
            if (preg_match('/^(\d{6})/', $filename, $m)) {
                $result['deis_code'] = $m[1];
            }
        }

        return $result;
    }

    private function checkConsistency(array $filename, array $content, array $sheetNames): array
    {
        $warnings = [];

        // Serie: filename vs sheets
        if ($filename['serie'] && !empty($sheetNames)) {
            $sheetSerie = $this->detectSerie($sheetNames);
            if ($sheetSerie && $filename['serie'] !== $sheetSerie) {
                $warnings[] = "El nombre del archivo indica serie {$filename['serie']}, pero el contenido indica serie {$sheetSerie}.";
            }
        }

        // Month: filename vs content
        if ($filename['month'] && $content['month']) {
            $fnMonthName = self::MONTH_NAMES[$filename['month']] ?? $filename['month'];
            $ctMonthName = self::MONTH_NAMES[$content['month']] ?? $content['month'];
            if ($filename['month'] !== $content['month']) {
                $warnings[] = "El nombre del archivo indica {$fnMonthName}, pero el contenido indica {$ctMonthName}.";
            }
        }

        // Year from filename pattern
        $filenameYear = null;
        // Not strictly needed since year comes from content

        return $warnings;
    }

    private function lookupHealthCenter(string $deisCode): ?array
    {
        try {
            $center = HealthCenter::where('code_deis', $deisCode)->first();
            if ($center) {
                return [
                    'id' => $center->id,
                    'code' => $center->code_deis,
                    'name' => $center->name,
                ];
            }
        } catch (\Exception $e) {
        }
        return null;
    }
}
