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

                [$filaHeader, $filaHeaderSuperior] = $this->findHeaderRow($worksheet, $row + 1, $highestRow, $highestCol);
                $filaInicioDatos = $filaHeader + 1;
                $filaFinDatos = $this->findDataEndRow($worksheet, $filaInicioDatos, $highestRow);

                $fields = $this->columnDetector->detect(
                    $worksheet, $filaHeader, $filaInicioDatos, $filaFinDatos, $highestCol, $sheetName, $codigo, $filaHeaderSuperior
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
            [$filaHeader, $filaHeaderSuperior] = $this->findHeaderRow($worksheet, 1, $highestRow, $highestCol);
            $filaInicioDatos = $filaHeader + 1;
            $filaFinDatos = $this->findImplicitDataEndRow($worksheet, $filaInicioDatos, $highestRow);

            $fields = $this->columnDetector->detect(
                $worksheet, $filaHeader, $filaInicioDatos, $filaFinDatos, $highestCol, $sheetName, null, $filaHeaderSuperior
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

    /**
     * Encuentra la fila de encabezado de una seccion, y opcionalmente una
     * segunda fila "superior" cuando el encabezado real ocupa dos filas
     * fusionadas (categoria general arriba -- ej. "TOTAL"/"RANGO ETARIO" --
     * y etiqueta especifica abajo -- ej. "10 - 14 años", "Migrantes").
     *
     * El criterio historico (primera fila con texto "normal" en columna A)
     * falla cuando el encabezado real tiene la columna A vacia -- esto
     * ocurre en formularios donde las etiquetas de columna viven solo en
     * B en adelante (ej. A11a: fila con B="TOTAL", C="RANGO ETARIO", con A
     * vacia). En ese caso el criterio historico salta el encabezado real
     * completo y aterriza en la primera fila que si tiene texto en A, que
     * puede ser un subtitulo de grupo sin ninguna otra columna poblada (ej.
     * "TRATAMIENTO DE SIFILIS EN GESTANTES EN ATENCION PRIMARIA") o
     * directamente la primera fila de datos reales -- en ambos casos
     * ColumnDetectorService termina leyendo las etiquetas de columna desde
     * una fila que no es el encabezado, perdiendo las columnas reales o
     * tomando formulas auxiliares de validacion (ej. CA/CB/CG/CH) como si
     * fueran campos.
     *
     * Nuevo criterio, general y sin hardcodear ninguna hoja/seccion
     * especifica: mientras columna A este vacia, cualquier fila con
     * contenido en alguna OTRA columna es candidata a formar parte del
     * encabezado real (se recuerdan las dos ultimas candidatas vistas, para
     * soportar el caso de dos filas fusionadas). En cuanto aparece la
     * primera fila con texto "normal" en columna A (ni marcador de seccion,
     * ni formula, ni "REM-"), se detiene: si ya habia candidatas previas
     * (columna A estuvo vacia justo antes), esa fila con texto en A es un
     * subtitulo o el inicio de los datos, no el encabezado -- se devuelve
     * la ultima candidata en su lugar. Si nunca hubo candidatas (columna A
     * ya tenia texto desde la primera fila, como en A01/A09/A11), el
     * comportamiento es identico al criterio historico: se devuelve esa
     * misma fila de inmediato.
     *
     * @return array{0: int, 1: ?int} [$filaHeader, $filaHeaderSuperior]
     */
    private function findHeaderRow(Worksheet $ws, int $startRow, int $maxRow, string $highestCol): array
    {
        $ultimaCandidata = null;
        $candidataAnterior = null;

        for ($row = $startRow; $row <= $maxRow; $row++) {
            $val = $ws->getCell('A' . $row)->getValue();
            $aEstaVacia = $val === null || trim((string) $val) === '';

            if ($aEstaVacia) {
                if ($this->rowHasContentOutsideColumnA($ws, $row, $highestCol)) {
                    $candidataAnterior = $ultimaCandidata;
                    $ultimaCandidata = $row;
                }

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

            if ($ultimaCandidata !== null) {
                // Texto "normal" en A, pero la(s) fila(s) anteriores con A
                // vacia ya tenian columnas reales pobladas -- esta fila es
                // un subtitulo o el inicio de los datos, no el encabezado.
                return [$ultimaCandidata, $candidataAnterior];
            }

            return [$row, null];
        }

        return [$ultimaCandidata ?? $startRow, $candidataAnterior];
    }

    private function rowHasContentOutsideColumnA(Worksheet $ws, int $row, string $highestCol): bool
    {
        $maxColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
        for ($col = 2; $col <= $maxColIndex; $col++) {
            $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $val = $ws->getCell($letra . $row)->getValue();
            if ($val !== null && trim((string) $val) !== '') {
                return true;
            }
        }

        return false;
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
