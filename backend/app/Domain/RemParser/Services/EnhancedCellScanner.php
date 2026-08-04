<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\DTOs\EnhancedCellDTO;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;

class EnhancedCellScanner
{
    private const COLOR_NOMBRES = [
        'FFFFFFFF' => 'blanco',
        'FFFFFF' => 'blanco',
        'FFFF00' => 'amarillo',
        'FFFFFF00' => 'amarillo',
        'FFD9D9D9' => 'gris_claro',
        'D9D9D9' => 'gris_claro',
        'FFC0C0C0' => 'plateado',
        'C0C0C0' => 'plateado',
        'FFBFBFBF' => 'gris_claro',
        'BFBFBF' => 'gris_claro',
        'FF92D050' => 'verde',
        '92D050' => 'verde',
        'FF00B050' => 'verde_oscuro',
        '00B050' => 'verde_oscuro',
        'FFFFC000' => 'naranja',
        'FFC000' => 'naranja',
        'FF4472C4' => 'azul',
        '4472C4' => 'azul',
        'FFFFCC' => 'crema',
        'FFFFFFCC' => 'crema',
        'FFCCFFCC' => 'verde_claro',
        'CCFFCC' => 'verde_claro',
        'FFE2EFDA' => 'verde_muy_claro',
        'E2EFDA' => 'verde_muy_claro',
    ];

    private const COLOR_ZONA = [
        'blanco' => 'zona_captura',
        'amarillo' => 'zona_total',
        'gris_claro' => 'zona_calculo',
        'plateado' => 'zona_calculo',
        'verde' => 'zona_calculo',
        'verde_oscuro' => 'zona_calculo',
        'naranja' => 'zona_total',
        'azul' => 'zona_encabezado',
        'crema' => 'zona_total',
        'verde_claro' => 'zona_calculo',
        'verde_muy_claro' => 'zona_calculo',
    ];

    public function scan(
        Worksheet $worksheet,
        array $sectionData,
        ?bool $sheetProtectionState = null,
    ): array {
        $filaHeader = $sectionData['filaHeader'] ?? 9;
        $filaInicio = $sectionData['filaInicioDatos'] ?? 10;
        $filaFin = $sectionData['filaFinDatos'] ?? $worksheet->getHighestRow();

        $sheetProtection = $this->detectSheetProtection($worksheet);
        $mergeMap = $this->buildMergeMapDetail($worksheet);
        $fields = $sectionData['fields'] ?? [];
        $maxCol = $this->resolveMaxColumn($worksheet, $fields);

        $cells = [];

        for ($row = $filaHeader; $row <= $filaFin; $row++) {
            for ($col = 1; $col <= $maxCol; $col++) {
                $colLetter = Coordinate::stringFromColumnIndex($col);
                $coordenada = $colLetter . $row;

                $cell = $worksheet->getCell($coordenada);

                $rawValue = $cell->getValue();
                if ($rawValue instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                    // Celdas con texto de formato mixto (ej. un run en negrita dentro del
                    // mismo titulo) devuelven un objeto RichText en vez de un string. Sin
                    // esto, el objeto se guarda tal cual, se serializa como {} en el JSON de
                    // cell_data y se lee de vuelta como un array vacio -- perdiendo el texto
                    // real y rompiendo cualquier codigo que espere un string en valor_bruto
                    // (ej. SectionCalibrationMatrixService::isSpecialSummaryRow).
                    $rawValue = $rawValue->getPlainText();
                }
                $isFormula = is_string($rawValue) && str_starts_with($rawValue, '=');
                $formulaStr = $isFormula ? $rawValue : null;

                $style = $worksheet->getStyle($coordenada);

                $fillColor = $this->extractFillColor($style->getFill());
                $fontColor = $this->extractFontColor($style->getFont());
                $locked = $style->getProtection()->getLocked();

                $mergeInfo = $this->findMergeInfo($coordenada, $mergeMap);

                $cellType = $this->classifyCellType(
                    row: $row,
                    colLetter: $colLetter,
                    filaHeader: $filaHeader,
                    isFormula: $isFormula,
                    rawValue: $rawValue,
                    fields: $fields,
                    fillColorName: $fillColor['nombre_inferido'] ?? null,
                );

                $zone = $this->classifyZone(
                    cellType: $cellType,
                    fillColorName: $fillColor['nombre_inferido'] ?? null,
                    isFormula: $isFormula,
                    fields: $fields,
                    colLetter: $colLetter,
                    row: $row,
                    filaHeader: $filaHeader,
                );

                $dependencies = $isFormula ? $this->extractDependencies($formulaStr) : [];

                $isExplicitlyUnlocked = $locked === Protection::PROTECTION_UNPROTECTED;
                $efectivelyBlocked = $sheetProtection && !$isExplicitlyUnlocked;

                $cells[$coordenada] = new EnhancedCellDTO(
                    coordenada: $coordenada,
                    columna: $colLetter,
                    fila: $row,
                    valorBruto: $isFormula ? null : $rawValue,
                    esFormula: $isFormula,
                    formula: $formulaStr,
                    dependencias: $dependencies,
                    esEditable: !$efectivelyBlocked,
                    estaBloqueada: $efectivelyBlocked,
                    proteccionHojaActiva: $sheetProtection,
                    colorFondo: $fillColor,
                    colorFuente: $fontColor,
                    bordes: $this->extractBorders($style->getBorders()),
                    esCombinada: $mergeInfo !== null,
                    rangoCombinado: $mergeInfo['rango'] ?? null,
                    validacionDatos: $this->extractDataValidation($worksheet, $coordenada),
                    comentarios: $this->extractComments($cell),
                    formatoNumero: $style->getNumberFormat()->getFormatCode(),
                    tipoCelda: $cellType,
                    zona: $zone,
                );
            }
        }

        return $cells;
    }

    public function scanForSection(
        Worksheet $worksheet,
        array $sectionData,
    ): array {
        return $this->scan($worksheet, $sectionData);
    }

    private function detectSheetProtection(Worksheet $ws): bool
    {
        try {
            $protection = $ws->getProtection();
            if ($protection !== null) {
                $enabled = $protection->isProtectionEnabled();
                if ($enabled) return true;
            }
        } catch (\Throwable) {
        }

        try {
            $spreadsheet = $ws->getParent();
            if ($spreadsheet !== null) {
                $security = $spreadsheet->getSecurity();
                if ($security !== null) {
                    $locked = false;
                    if (method_exists($security, 'getLockStructure')) {
                        $locked = (bool) $security->getLockStructure();
                    }
                    if ($locked) return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private function extractFillColor(Fill $fill): ?array
    {
        try {
            $colorObj = $fill->getStartColor();
            if ($colorObj === null) return null;

            $rgb = $colorObj->getRGB();
            $argb = $fill->getStartColor()->getARGB();

            $effective = $argb ?? $rgb;
            if (!$effective || $effective === '' || $effective === '00000000') {
                $fillType = $fill->getFillType();
                if ($fillType === Fill::FILL_NONE || $fillType === null) {
                    return ['rgb' => null, 'nombre_inferido' => 'sin_relleno'];
                }
                return null;
            }

            $upper = strtoupper($effective);
            $name = self::COLOR_NOMBRES[$upper] ?? 'desconocido';

            return [
                'rgb' => $effective,
                'nombre_inferido' => $name,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractFontColor($font): ?array
    {
        try {
            $colorObj = $font->getColor();
            if ($colorObj === null) return null;

            $rgb = $colorObj->getRGB();
            $argb = $colorObj->getARGB();
            $effective = $argb ?? $rgb;

            if (!$effective || $effective === '' || $effective === '00000000') {
                return ['rgb' => null, 'nombre_inferido' => 'sin_color'];
            }

            $upper = strtoupper($effective);
            $name = self::COLOR_NOMBRES[$upper] ?? 'desconocido';

            return [
                'rgb' => $effective,
                'nombre_inferido' => $name,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractBorders($borders): ?array
    {
        try {
            $result = [];
            foreach (['top', 'bottom', 'left', 'right'] as $side) {
                $method = 'get' . ucfirst($side);
                $border = $borders->$method();
                $style = $border->getBorderStyle();
                $color = null;
                try {
                    $c = $border->getColor();
                    $color = $c ? $c->getRGB() : null;
                } catch (\Throwable) {
                    $color = null;
                }
                $result[$side] = [
                    'estilo' => $style ? $style->value : 'none',
                    'color' => $color,
                ];
            }
            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractDataValidation(Worksheet $ws, string $coordinate): ?array
    {
        try {
            $validation = $ws->getDataValidation($coordinate);
            if ($validation === null) return null;

            $type = $validation->getType();
            if ($type === 'none') return null;

            return [
                'tipo' => $validation->getType(),
                'formula1' => $validation->getFormula1(),
                'formula2' => $validation->getFormula2(),
                'permitir_vacio' => $validation->getAllowBlank(),
                'mostrar_mensaje' => $validation->getShowInputMessage(),
                'mensaje' => $validation->getPromptTitle()
                    ? $validation->getPromptTitle() . ': ' . $validation->getPromptBody()
                    : null,
                'estilo' => $validation->getStyle(),
                'operador' => $validation->getOperator(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractComments($cell): ?array
    {
        try {
            $comment = $cell->getComment();
            if ($comment === null) return null;

            $text = '';
            try {
                $text = $comment->getText()->getPlainText();
            } catch (\Throwable) {
                $text = (string) $comment;
            }

            return [
                'texto' => $text,
                'autor' => $comment->getAuthor(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractDependencies(?string $formula): array
    {
        if (!$formula) return [];

        $refs = [];
        $upper = strtoupper($formula);

        $upper = preg_replace_callback(
            '/\$?([A-Z]+)(\d+)\s*:\s*\$?([A-Z]+)(\d+)/',
            function (array $m) use (&$refs): string {
                $colStartIdx = Coordinate::columnIndexFromString($m[1]);
                $colEndIdx = Coordinate::columnIndexFromString($m[3]);
                $rowStart = (int) $m[2];
                $rowEnd = (int) $m[4];

                for ($ci = $colStartIdx; $ci <= $colEndIdx; $ci++) {
                    $colLetter = Coordinate::stringFromColumnIndex($ci);
                    for ($ri = $rowStart; $ri <= $rowEnd; $ri++) {
                        $ref = $colLetter . $ri;
                        if (!in_array($ref, $refs, true)) {
                            $refs[] = $ref;
                        }
                    }
                }
                return '';
            },
            $upper,
        );

        if (preg_match_all('/\$?([A-Z]+)\$?(\d+)/', $upper, $m)) {
            foreach ($m[0] as $ref) {
                $clean = str_replace('$', '', $ref);
                if (!in_array($clean, $refs, true)) {
                    $refs[] = $clean;
                }
            }
        }

        return $refs;
    }

    private function buildMergeMapDetail(Worksheet $ws): array
    {
        $map = [];
        $ranges = $ws->getMergeCells();
        foreach ($ranges as $range) {
            if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $range, $m)) {
                $colStart = Coordinate::columnIndexFromString($m[1]);
                $rowStart = (int) $m[2];
                $colEnd = Coordinate::columnIndexFromString($m[3]);
                $rowEnd = (int) $m[4];

                for ($r = $rowStart; $r <= $rowEnd; $r++) {
                    for ($c = $colStart; $c <= $colEnd; $c++) {
                        $key = Coordinate::stringFromColumnIndex($c) . $r;
                        $map[$key] = [
                            'rango' => $range,
                            'es_principal' => ($r === $rowStart && $c === $colStart),
                            'col_principal' => $m[1] . $m[2],
                        ];
                    }
                }
            }
        }
        return $map;
    }

    private function findMergeInfo(string $coordinate, array $mergeMap): ?array
    {
        return $mergeMap[$coordinate] ?? null;
    }

    private function classifyCellType(
        int $row,
        string $colLetter,
        int $filaHeader,
        bool $isFormula,
        mixed $rawValue,
        array $fields,
        ?string $fillColorName,
    ): string {
        if ($row === $filaHeader) return 'encabezado';

        $fieldInfo = $this->findField($colLetter, $fields);
        $isEmpty = $rawValue === null || (is_string($rawValue) && trim((string) $rawValue) === '');

        if ($isFormula) {
            $isTotal = $fieldInfo && ($fieldInfo['esTotal'] ?? false);
            $isHiddenControl = $fieldInfo && ($fieldInfo['esControlOculto'] ?? false);

            if ($isTotal) return 'formula_total';
            if ($isHiddenControl) return 'control_oculto';
            return 'formula_calculo';
        }

        if ($colLetter === 'A' || $colLetter === 'B') {
            return $isEmpty ? 'vacio' : 'etiqueta';
        }

        if ($fieldInfo && ($fieldInfo['esControlOculto'] ?? false)) {
            return $isEmpty ? 'vacio' : 'control_oculto';
        }

        if ($isEmpty) return 'vacio';

        return 'dato_entrada';
    }

    private function classifyZone(
        string $cellType,
        ?string $fillColorName,
        bool $isFormula,
        array $fields,
        string $colLetter,
        int $row,
        int $filaHeader,
    ): string {
        if ($row === $filaHeader) return 'zona_encabezado';

        $fieldInfo = $this->findField($colLetter, $fields);

        if ($fieldInfo) {
            if ($fieldInfo['esTotal'] ?? false) return 'zona_total';
            if ($fieldInfo['esControlOculto'] ?? false) return 'zona_control_oculto';
        }

        if ($colLetter === 'A' || $colLetter === 'B') return 'zona_etiquetas';

        if (isset(self::COLOR_ZONA[$fillColorName ?? ''])) {
            return self::COLOR_ZONA[$fillColorName];
        }

        if ($isFormula) return 'zona_calculo';

        return 'zona_captura';
    }

    private function findField(string $colLetter, array $fields): ?array
    {
        foreach ($fields as $f) {
            if (($f['letra'] ?? '') === $colLetter) {
                return $f;
            }
        }
        return null;
    }

    private function resolveMaxColumn(Worksheet $ws, array $fields): int
    {
        if (!empty($fields)) {
            $maxLetter = '';
            foreach ($fields as $f) {
                $l = $f['letra'] ?? '';
                if (strlen($l) > strlen($maxLetter) || (strlen($l) === strlen($maxLetter) && $l > $maxLetter)) {
                    $maxLetter = $l;
                }
            }
            if ($maxLetter !== '') {
                return Coordinate::columnIndexFromString($maxLetter);
            }
        }
        return Coordinate::columnIndexFromString($ws->getHighestColumn());
    }
}
