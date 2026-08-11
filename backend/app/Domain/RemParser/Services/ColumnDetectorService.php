<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\DTOs\ParsedFieldDTO;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ColumnDetectorService
{
    private FormulaAnalyzerService $formulaAnalyzer;

    public function __construct(?FormulaAnalyzerService $formulaAnalyzer = null)
    {
        $this->formulaAnalyzer = $formulaAnalyzer ?? new FormulaAnalyzerService();
    }

    public function detect(
        Worksheet $worksheet,
        int $filaHeader,
        int $filaInicioDatos,
        ?int $filaFinDatos,
        string $highestCol,
        string $sheetName,
        ?string $codigoSeccion,
        ?int $filaHeaderSuperior = null,
        array $filasHeaderAdicionales = [],
        array $bloquesSecundarios = [],
    ): array {
        $fields = [];
        $maxColIndex = Coordinate::columnIndexFromString($highestCol);
        $mergeMap = $this->buildMergeMap($worksheet);

        for ($col = 1; $col <= $maxColIndex; $col++) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $headerValue = $this->getMergedCellValue($worksheet, $mergeMap, $colLetter, $filaHeader);
            $label = $this->cleanLabel($headerValue);

            // Encabezado de dos filas fusionadas: si la columna esta vacia
            // en la fila inferior (tipicamente donde viven las etiquetas
            // especificas, ej. "10 - 14 años"), se usa como respaldo la
            // fila superior (categoria general, ej. "TOTAL", que en un
            // encabezado fusionado normalmente no se repite en la fila
            // inferior).
            if ($label === '' && $filaHeaderSuperior !== null) {
                $superiorValue = $this->getMergedCellValue($worksheet, $mergeMap, $colLetter, $filaHeaderSuperior);
                $label = $this->cleanLabel($superiorValue);
            }

            // Encabezado de 2 o 3 niveles donde la fila superior YA tiene
            // texto en columna A (ver SectionDetectorService::
            // findTrailingHeaderRows()): se combinan las etiquetas de cada
            // nivel adicional encontrado, en orden, con " / " -- ej.
            // "RANGO ETARIO Y SEXO" + "0-4 años" + "Hombres" en vez de
            // perder los niveles inferiores y repetir solo la categoria
            // general en 30+ columnas.
            //
            // Deduplicacion contra el ULTIMO nivel agregado (no contra la
            // etiqueta combinada completa): una celda fusionada
            // VERTICALMENTE (ej. "Ambos sexos" en B116:B117) resuelve al
            // mismo valor en cada fila que cubre, y sin esto se repetiria
            // como "TOTAL / Ambos sexos / Ambos sexos".
            $ultimoNivelAgregado = $label;
            foreach ($filasHeaderAdicionales as $filaAdicional) {
                $valorAdicional = $this->cleanLabel(
                    $this->getMergedCellValue($worksheet, $mergeMap, $colLetter, $filaAdicional)
                );
                if ($valorAdicional === '' || $valorAdicional === $ultimoNivelAgregado) {
                    continue;
                }
                $label = $label === '' ? $valorAdicional : $label . ' / ' . $valorAdicional;
                $ultimoNivelAgregado = $valorAdicional;
            }

            // Bloque de encabezado SECUNDARIO (hallazgo A30/C fila 95, ver
            // SectionDetectorService::findSecondaryHeaderBlocks()): solo se
            // consulta cuando el encabezado primario (arriba) no dejo
            // ninguna etiqueta para esta columna -- una columna que YA tiene
            // etiqueta del bloque primario la conserva sin cambios (el
            // bloque secundario puede redefinir semanticamente columnas
            // reutilizadas, ej. B:Y en A30/C, pero resolver esa doble
            // semantica queda fuera de alcance de esta correccion minima;
            // solo se etiquetan aqui las columnas genuinamente NUEVAS que el
            // bloque primario nunca cubrio).
            if ($label === '' && !empty($bloquesSecundarios)) {
                $bloque = null;
                foreach ($bloquesSecundarios as $candidato) {
                    if ($candidato['columnaInicioNueva'] <= $col) {
                        $bloque = $candidato;
                    }
                }

                if ($bloque !== null) {
                    $valorSecundario = $this->cleanLabel(
                        $this->getMergedCellValue($worksheet, $mergeMap, $colLetter, $bloque['filaHeader'])
                    );
                    $ultimoNivelSecundario = $valorSecundario;
                    foreach ($bloque['filasAdicionales'] as $filaAdicional) {
                        $valorAdicional = $this->cleanLabel(
                            $this->getMergedCellValue($worksheet, $mergeMap, $colLetter, $filaAdicional)
                        );
                        if ($valorAdicional === '' || $valorAdicional === $ultimoNivelSecundario) {
                            continue;
                        }
                        $valorSecundario = $valorSecundario === '' ? $valorAdicional : $valorSecundario . ' / ' . $valorAdicional;
                        $ultimoNivelSecundario = $valorAdicional;
                    }
                    $label = $valorSecundario;
                }
            }

            if ($label === '') {
                continue;
            }

            $esTotal = $this->isTotalColumn($label);
            $esControlOculto = $this->isControlOculto(
                $worksheet, $colLetter, $filaInicioDatos, $filaFinDatos
            );

            $regla = null;
            if ($esControlOculto) {
                $formulaStr = $this->getFirstFormula($worksheet, $colLetter, $filaInicioDatos, $filaFinDatos);
                if ($formulaStr !== null) {
                    $rule = $this->formulaAnalyzer->analyze($formulaStr);
                    $regla = $rule?->toArray();
                }
            }

            $fields[] = new ParsedFieldDTO(
                letra: $colLetter,
                label: $label,
                esTotal: $esTotal,
                esControlOculto: $esControlOculto,
                reglaDetectada: $regla,
            );
        }

        return $fields;
    }

    /**
     * Nunca trata el texto crudo de una formula como etiqueta -- hallazgo
     * real de A28/A.2 (2026-08-07): la fila de encabezado 28 tenia una
     * formula de control aislada en la columna CM sin ninguna etiqueta de
     * texto real en ninguna fila de encabezado para esa columna; sin este
     * filtro, la propia cadena de la formula ("=IF(B29<>SUM(B30:B52),1,0)")
     * se colaba como si fuera la etiqueta de un campo nuevo (CM), inventando
     * una columna capturable que en realidad no existe.
     */
    private function cleanLabel(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $texto = trim((string) $value);
        if (str_starts_with($texto, '=')) {
            return '';
        }
        return $texto;
    }

    private function isTotalColumn(string $label): bool
    {
        $upper = mb_strtoupper($label, 'UTF-8');
        if (str_contains($upper, 'TOTAL')) {
            return true;
        }
        if (str_contains($upper, 'AMBOS SEXOS')) {
            return true;
        }
        return false;
    }

    private function isControlOculto(Worksheet $ws, string $colLetter, int $startRow, ?int $endRow): bool
    {
        $maxRow = $endRow ?? $ws->getHighestRow();
        $filaSinFormula = false;
        $filaConDatos = false;

        for ($row = $startRow; $row <= $maxRow; $row++) {
            $rawValue = $ws->getCell($colLetter . $row)->getValue();

            if ($rawValue === null || (is_string($rawValue) && trim($rawValue) === '')) {
                continue;
            }

            $filaConDatos = true;

            if (!is_string($rawValue) || !str_starts_with($rawValue, '=')) {
                $filaSinFormula = true;
                break;
            }
        }

        if (!$filaConDatos) {
            return false;
        }

        return !$filaSinFormula;
    }

    private function buildMergeMap(Worksheet $ws): array
    {
        $map = [];
        foreach ($ws->getMergeCells() as $range) {
            if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $range, $m)) {
                $colStart = Coordinate::columnIndexFromString($m[1]);
                $rowStart = (int) $m[2];
                $colEnd = Coordinate::columnIndexFromString($m[3]);
                $rowEnd = (int) $m[4];
                $topLeftValue = $ws->getCell($m[1] . $m[2])->getValue();
                for ($r = $rowStart; $r <= $rowEnd; $r++) {
                    for ($c = $colStart; $c <= $colEnd; $c++) {
                        if ($r === $rowStart && $c === $colStart) continue;
                        $map[$r . ':' . $c] = $topLeftValue;
                    }
                }
            }
        }
        return $map;
    }

    private function getMergedCellValue(Worksheet $ws, array $mergeMap, string $colLetter, int $row): mixed
    {
        $colIndex = Coordinate::columnIndexFromString($colLetter);
        $value = $ws->getCell($colLetter . $row)->getValue();
        if ($value !== null) {
            return $value;
        }
        return $mergeMap[$row . ':' . $colIndex] ?? null;
    }

    private function getFirstFormula(Worksheet $ws, string $colLetter, int $startRow, ?int $endRow): ?string
    {
        $maxRow = $endRow ?? $ws->getHighestRow();

        for ($row = $startRow; $row <= $maxRow; $row++) {
            $rawValue = $ws->getCell($colLetter . $row)->getValue();
            if (is_string($rawValue) && str_starts_with($rawValue, '=')) {
                return $rawValue;
            }
        }

        return null;
    }
}
