<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\DTOs\ParsedSectionDTO;
use App\Domain\RemParser\DTOs\ParsedFieldDTO;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SectionDetectorService
{
    private const PATRON_SECCION = '/^SECCI[OÓ]N\s+([\w.]+)\s*[:\-]?\s*(.*)$/iu';
    private const PATRON_TIPO_CONTROL = '/^TIPO\s+DE\s+CONTROL/i';

    private ColumnDetectorService $columnDetector;

    public function __construct(?ColumnDetectorService $columnDetector = null)
    {
        $this->columnDetector = $columnDetector ?? new ColumnDetectorService();
    }

    public function detect(Worksheet $worksheet, string $sheetName): array
    {
        $highestRow = $worksheet->getHighestRow();
        $highestCol = $worksheet->getHighestColumn();

        $secciones = [];
        $ultimaFilaSeccion = 0;

        for ($row = 1; $row <= $highestRow; $row++) {
            $cellValue = $worksheet->getCell('A' . $row)->getValue();

            if (is_string($cellValue) && preg_match(self::PATRON_SECCION, $cellValue, $m)) {
                $codigo = $m[1];
                $titulo = trim($m[2]);

                $filaHeader = $this->findHeaderRow($worksheet, $row + 1, $highestRow);
                $filaInicioDatos = $filaHeader + 1;
                $filaFinDatos = $this->findDataEndRow($worksheet, $filaInicioDatos, $highestRow);

                $fields = $this->columnDetector->detect(
                    $worksheet, $filaHeader, $filaInicioDatos, $filaFinDatos, $highestCol, $sheetName, $codigo
                );

                $secciones[] = new ParsedSectionDTO(
                    codigo: $codigo,
                    titulo: $titulo,
                    filaHeader: $filaHeader,
                    filaInicioDatos: $filaInicioDatos,
                    filaFinDatos: $filaFinDatos,
                    fields: $fields,
                );

                $ultimaFilaSeccion = $filaFinDatos ?? $highestRow;
            }
        }

        $secciones = $this->filterAggregators($secciones);

        if (empty($secciones)) {
            $filaHeader = $this->findHeaderRow($worksheet, 1, $highestRow);
            $filaInicioDatos = $filaHeader + 1;
            $filaFinDatos = $this->findImplicitDataEndRow($worksheet, $filaInicioDatos, $highestRow);

            $fields = $this->columnDetector->detect(
                $worksheet, $filaHeader, $filaInicioDatos, $filaFinDatos, $highestCol, $sheetName, null
            );

            $secciones[] = new ParsedSectionDTO(
                codigo: null,
                titulo: 'Seccion unica implicita',
                filaHeader: $filaHeader,
                filaInicioDatos: $filaInicioDatos,
                filaFinDatos: $filaFinDatos,
                fields: $fields,
            );
        }

        return $secciones;
    }

    private function findHeaderRow(Worksheet $ws, int $startRow, int $maxRow): int
    {
        for ($row = $startRow; $row <= $maxRow; $row++) {
            $val = $ws->getCell('A' . $row)->getValue();
            if ($val === null || trim((string) $val) === '') {
                continue;
            }
            $strVal = trim((string) $val);
            if (preg_match(self::PATRON_SECCION, $strVal)) {
                continue;
            }
            if (str_starts_with($strVal, '=')) {
                continue;
            }
            if (str_starts_with($strVal, 'REM-')) {
                continue;
            }
            return $row;
        }
        return $startRow;
    }

    private function findDataEndRow(Worksheet $ws, int $startRow, int $maxRow): ?int
    {
        for ($row = $startRow; $row <= $maxRow; $row++) {
            $val = $ws->getCell('A' . $row)->getValue();
            if (is_string($val) && preg_match(self::PATRON_SECCION, $val)) {
                return $row - 1;
            }
        }
        // Last section extends to the end of the sheet
        return $maxRow;
    }

    private function findImplicitDataEndRow(Worksheet $ws, int $startRow, int $maxRow): ?int
    {
        for ($row = $startRow; $row <= $maxRow; $row++) {
            $val = $ws->getCell('A' . $row)->getValue();
            if (is_string($val) && preg_match(self::PATRON_SECCION, $val)) {
                return $row - 1;
            }
        }
        return null;
    }

    /**
     * Descarta una seccion "padre" (ej. F) solo cuando es un encabezado puro
     * sin contenido propio antes de su primera subseccion (ej. F.1) -- nunca
     * por el solo hecho de que existan subsecciones con su mismo prefijo.
     *
     * Senal usada: filaHeader. findHeaderRow() avanza fila por fila saltando
     * marcadores "SECCION ..." y filas vacias hasta encontrar la primera fila
     * con contenido real. Si el padre no tiene ninguna fila propia entre su
     * marcador y el de su subseccion, ese avance termina aterrizando en el
     * MISMO encabezado que la subseccion ya calculo para si misma -- ambas
     * quedan con filaHeader identico. Si el padre si tiene datos propios,
     * findHeaderRow encuentra ese contenido antes de llegar al marcador de la
     * subseccion, y los filaHeader resultan distintos.
     */
    private function filterAggregators(array $secciones): array
    {
        $codigos = array_map(fn(ParsedSectionDTO $s) => $s->codigo, $secciones);
        $headers = array_map(fn(ParsedSectionDTO $s) => $s->filaHeader, $secciones);
        $esAgregador = [];

        foreach ($codigos as $i => $codigo) {
            if ($codigo === null) continue;
            foreach ($codigos as $j => $otro) {
                if ($i === $j || $otro === null) continue;
                if (!str_starts_with($otro, $codigo . '.')) continue;

                if ($headers[$i] === $headers[$j]) {
                    $esAgregador[$i] = true;
                    break;
                }
            }
        }

        $resultado = [];
        foreach ($secciones as $i => $sec) {
            if (!isset($esAgregador[$i])) {
                $resultado[] = $sec;
            }
        }

        return $resultado;
    }
}
