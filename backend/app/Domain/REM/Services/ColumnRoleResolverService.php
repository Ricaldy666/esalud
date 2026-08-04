<?php

namespace App\Domain\REM\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Resuelve, con evidencia real de cell_data (fusiones de celdas, formulas,
 * valor bruto de plantilla -- nunca supuestos fijos por hoja/seccion), dos
 * problemas relacionados encontrados durante la calibracion funcional de
 * A09:
 *
 * Capa 1 -- detectSubcategoryColumn(): una columna de una seccion que
 * consistentemente contiene texto de categoria (no numerico), pero no
 * esta fusionada con la columna de concepto. Generaliza el detector
 * anterior (que exigia ademas zona=zona_etiquetas) a aceptar cualquier
 * zona, siempre que la evidencia de fusion/formula/valor sea consistente.
 *
 * Capa 2 -- resolveConceptOverflowColumns() / classifyOverflowCell():
 * dentro de una misma seccion, el concepto puede fusionarse en un ancho
 * de columnas distinto segun la fila (ej. A09/C: A:C en filas simples,
 * A:B en 3 sub-bloques donde C pasa a ser una celda independiente con
 * texto real). Esto no se puede resolver con una sola columna fija por
 * seccion -- se resuelve celda por celda, en el momento del parseo.
 *
 * Se apoya en cell_data (no en el worksheet en vivo) para la evidencia de
 * fusion: RemParserService::loadSpreadsheet() usa
 * setReadDataOnly(true) por rendimiento/memoria, lo que hace que
 * Worksheet::getMergeCells() no devuelva ninguna fusion -- cell_data, en
 * cambio, siempre las conserva (fue escaneado con readDataOnly(false),
 * una unica vez, no en cada carga). El valor de cada celda (para decidir
 * si es texto o numero) se sigue leyendo en vivo del upload real -- nunca
 * de cell_data, que es una foto estatica de la plantilla.
 *
 * Usado por RemParserService::buildSectionMaps()/parseSheet() (persiste
 * rem_data) y por SectionCalibrationMatrixService::detectLabelColumns()
 * (matriz de calibracion), para evitar mantener dos heuristicas
 * divergentes.
 */
class ColumnRoleResolverService
{
    private const ROLE_MERGED = 'merged';
    private const ROLE_SUBCATEGORY = 'subcategory';
    private const ROLE_NUMERIC = 'numeric';

    /**
     * Detecta una columna de subcategoria real: no fusionada con el
     * concepto en ninguna fila observada, sin formulas, y con evidencia
     * consistente de texto no numerico en al menos una fila (condicion 1:
     * una sola celda no numerica no basta -- ninguna fila puede romper la
     * consistencia con un valor numerico real, condicion 3).
     *
     * @param array<int, array<string, array<string, mixed>>> $cellRows [fila => [columna => celda cell_data]]
     * @param array<int, array{letra?: string, esTotal?: bool}> $fields
     * @param string[] $excludedColumns columnas ya asignadas (concepto/nivel padre, profesional, totales)
     *
     * $parentColumn es la columna contra la que se compara la fusion -- para
     * detectar un nivel 2 (subcategory) es el concepto; para detectar un
     * nivel 3 (detail) encadenado, es el propio subcategoryColumn ya
     * detectado (no el concepto) -- ver RemParserService::buildSectionMaps().
     * El metodo no distingue en que nivel de la cadena esta, solo compara
     * contra la columna que se le pase.
     */
    public function detectSubcategoryColumn(
        array $cellRows,
        array $fields,
        array $excludedColumns,
        int $dataStartRow,
        int $dataEndRow,
        string $parentColumn,
    ): ?string {
        if (empty($cellRows)) {
            return null;
        }

        $candidateColumns = [];
        foreach ($fields as $field) {
            $letter = strtoupper((string) ($field['letra'] ?? ''));
            if ($letter === '' || in_array($letter, $excludedColumns, true) || ($field['esTotal'] ?? false) || in_array($letter, $candidateColumns, true)) {
                continue;
            }
            $candidateColumns[] = $letter;
        }

        foreach ($candidateColumns as $column) {
            $realLabelEvidenceRows = 0;
            $consistent = true;

            for ($row = $dataStartRow; $row <= $dataEndRow; $row++) {
                $cell = $cellRows[$row][$column] ?? null;
                if ($cell === null) {
                    continue; // sin evidencia en esta fila -- neutro, no prueba ni rompe
                }

                $parentCell = $cellRows[$row][$parentColumn] ?? null;
                if ($this->sharesMerge($cell, $parentCell)) {
                    continue; // fusionada con el nivel padre esta fila -- neutro (caso A09/B)
                }

                if ($cell['es_formula'] ?? false) {
                    // Una formula real nunca es una etiqueta estatica de categoria.
                    $consistent = false;
                    break;
                }

                $rawValue = trim((string) ($cell['valor_bruto'] ?? ''));
                if ($rawValue === '') {
                    continue; // celda vacia puntual -- neutra, no rompe consistencia
                }

                if (is_numeric($rawValue)) {
                    // Evidencia real de columna numerica -- descarta la candidatura
                    // por completo (condicion 3: no reclasificar una columna
                    // numerica real por un error de tipeo aislado en otra columna).
                    $consistent = false;
                    break;
                }

                $realLabelEvidenceRows++;
            }

            // Condicion 1: una sola celda no numerica aislada no basta. Evidencia
            // real (A01/A fila 10, y el mismo patron en al menos otras 25
            // secciones de hojas certificadas): un limite de seccion mal
            // calibrado (filaInicioDatos apuntando a una fila de
            // sub-encabezado de rango etario/sexo, en vez de la primera fila
            // real de datos -- mismo patron ya documentado en BACKLOG 2/7)
            // puede filtrar 1 o 2 filas de texto de encabezado ("Menos de 4
            // anos", "Hombres") dentro del rango escaneado. Si el resto de
            // las filas reales de esa columna estan vacias en el upload de
            // prueba (columna numerica real, simplemente sin datos
            // registrados en este upload puntual), 1-2 filas de encabezado
            // bastarian para una falsa candidatura. Exigir evidencia en al
            // menos 3 filas reales protege contra bloques de encabezado de
            // hasta 2 filas (el maximo observado en A01-A34) sin bloquear
            // ningun caso real auditado (A19a/A.4: 3 filas; A19a/A.2: 12;
            // A31/A: 16; A06/B.3: 22).
            if ($consistent && $realLabelEvidenceRows >= 3) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Columnas cuyo rol varia por fila dentro de la misma seccion: caen
     * dentro del ancho de fusion declarado en el propio encabezado de
     * concepto (fila filaHeader), mas alla de concept_column misma.
     * Evidencia puramente estructural (una sola lectura del encabezado),
     * no depende del contenido de las filas de datos.
     *
     * @param array<int, array<string, array<string, mixed>>> $cellRows
     * @param string[] $excludedColumns columnas ya asignadas (profesional, totales, subcategoria de seccion)
     * @return string[]
     */
    public function resolveConceptOverflowColumns(
        array $cellRows,
        int $filaHeader,
        string $conceptColumn,
        array $excludedColumns,
    ): array {
        if ($filaHeader <= 0) {
            return [];
        }

        $headerCell = $cellRows[$filaHeader][$conceptColumn] ?? null;
        if ($headerCell === null || !($headerCell['es_combinada'] ?? false)) {
            return [];
        }

        // Salvaguarda: si la propia celda ancla del encabezado de concepto no
        // tiene texto, la deteccion de concept_column para esta seccion no es
        // confiable (evidencia real: A04/I.1/I.2, concept_column resolvio "E"
        // pero E111 -- el ancla de su fusion E111:F112 -- esta vacia; el
        // concepto real de la seccion vive en A111:A113, "TIPO DE RECETA").
        // Sin esta salvaguarda, el ancho de fusion de una columna que ni
        // siquiera es el concepto real generaria columnas "overflow" ajenas
        // al problema que este mecanismo resuelve. No corrige la deteccion de
        // concept_column en si (fuera de alcance, ver backlog independiente) --
        // solo evita construir sobre una base ya conocida como no confiable.
        $anchorValue = trim((string) ($headerCell['valor_bruto'] ?? ''));
        if ($anchorValue === '') {
            return [];
        }

        $range = $headerCell['rango_combinado'] ?? null;
        if ($range === null) {
            return [];
        }

        [$startCell, $endCell] = Coordinate::rangeBoundaries($range);
        $overflow = [];
        for ($c = $startCell[0] + 1; $c <= $endCell[0]; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            if (in_array($letter, $excludedColumns, true)) {
                continue;
            }
            $overflow[] = $letter;
        }

        return $overflow;
    }

    /**
     * Resuelve el rol de una celda concreta (columna candidata de
     * concept_overflow_columns, en una fila especifica): 'merged' si
     * comparte la fusion de la celda "padre" de esa misma fila,
     * 'subcategory' si es independiente y contiene texto no numerico,
     * 'numeric' en cualquier otro caso (vacia o numerica -- se comporta
     * exactamente igual que una columna numerica normal).
     *
     * $parentCellData es la celda contra la que se compara la fusion --
     * para el primer nivel de una cadena de overflow es la celda de
     * concepto; para un nivel siguiente (ej. columna de detalle) es la
     * celda del nivel anterior ya resuelto en esta misma fila, no
     * siempre el concepto. Generico: este metodo no sabe ni le importa
     * en que nivel de la cadena esta -- la jerarquia (concept ->
     * subcategory -> detail -> ...) se construye en el llamador
     * (RemParserService), encadenando llamadas a este mismo metodo.
     *
     * $rawValueFromUpload debe venir de una lectura en vivo del upload
     * real (nunca de cell_data, que es una foto estatica de la
     * plantilla) -- solo la evidencia de fusion se toma de cell_data.
     */
    public function classifyOverflowCell(?array $cellData, ?array $parentCellData, ?string $rawValueFromUpload): string
    {
        if ($this->sharesMerge($cellData, $parentCellData)) {
            return self::ROLE_MERGED;
        }

        $rawValue = trim((string) ($rawValueFromUpload ?? ''));
        if ($rawValue !== '' && !is_numeric($rawValue)) {
            return self::ROLE_SUBCATEGORY;
        }

        return self::ROLE_NUMERIC;
    }

    public function isMergedRole(string $role): bool
    {
        return $role === self::ROLE_MERGED;
    }

    public function isSubcategoryRole(string $role): bool
    {
        return $role === self::ROLE_SUBCATEGORY;
    }

    private function sharesMerge(?array $cell, ?array $otherCell): bool
    {
        if ($cell === null || $otherCell === null) {
            return false;
        }

        if (!($cell['es_combinada'] ?? false) || !($otherCell['es_combinada'] ?? false)) {
            return false;
        }

        $cellRange = $cell['rango_combinado'] ?? null;
        $conceptRange = $otherCell['rango_combinado'] ?? null;

        return $cellRange !== null && $conceptRange !== null && $cellRange === $conceptRange;
    }
}
