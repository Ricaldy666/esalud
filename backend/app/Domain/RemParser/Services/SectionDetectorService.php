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

    /**
     * Normaliza el valor crudo de una celda (`Cell::getValue()`) a texto
     * plano antes de evaluar el marcador SECCION -- hallazgo A30 (2026-08-18):
     * cuando Excel guarda la celda con formato mixto (parte del texto en
     * negrita, otro color, etc.), PhpSpreadsheet devuelve un objeto
     * RichText en vez de un string, y el `is_string()` usado hasta ahora en
     * los 3 puntos de deteccion de marcador fallaba silenciosamente,
     * dejando el marcador invisible sin ningun error (caso real: A30 fila
     * 94, "SECCION D: TELEINTERCONSULTA ODONTOLOGICA" nunca se detecto).
     * RichText implementa __toString() correctamente (confirmado: castea
     * al texto plano esperado), asi que basta con castear cualquier valor
     * escalar u objeto con __toString -- cualquier otro tipo (array, objeto
     * sin __toString, etc.) se descarta como null, nunca como cadena vacia,
     * para no introducir falsos positivos nuevos.
     */
    private function extractCellText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return null;
    }

    public function detect(Worksheet $worksheet, string $sheetName): array
    {
        $highestRow = $worksheet->getHighestRow();
        $highestCol = $worksheet->getHighestColumn();

        $secciones = [];
        $ultimaFilaSeccion = 0;

        for ($row = 1; $row <= $highestRow; $row++) {
            $cellValue = $this->extractCellText($worksheet->getCell('A' . $row)->getValue());

            if ($cellValue !== null && preg_match(self::PATRON_SECCION, $cellValue, $m)) {
                $codigo = $m[1];
                $titulo = trim($m[2]);

                [$filaHeader, $filaHeaderSuperior] = $this->findHeaderRow($worksheet, $row + 1, $highestRow, $highestCol);
                $filasTotalLider = [];
                $filasHeaderAdicionales = $this->findTrailingHeaderRows($worksheet, $filaHeader + 1, $highestRow, $highestCol, $filasTotalLider);
                $filaInicioDatos = (empty($filasHeaderAdicionales) ? $filaHeader : end($filasHeaderAdicionales)) + 1;

                $anchoConocido = $this->lastPlainTextColumnIndex($worksheet, $filaHeader, $highestCol);
                foreach ($filasHeaderAdicionales as $filaAdicional) {
                    $anchoConocido = max($anchoConocido, $this->lastPlainTextColumnIndex($worksheet, $filaAdicional, $highestCol));
                }

                $filaFinDatos = $this->findDataEndRow($worksheet, $filaInicioDatos, $highestRow, $highestCol, $anchoConocido);

                $bloquesSecundarios = $this->findSecondaryHeaderBlocks(
                    $worksheet, $filaInicioDatos, $filaFinDatos, $anchoConocido, $highestCol
                );

                // Las filas TOTAL lider siguen contando para filaInicioDatos/
                // anchoConocido (arriba, sin cambios) pero se excluyen del
                // conjunto usado para construir labels multinivel de columna
                // -- ver findTrailingHeaderRows() y hallazgo A30/A-C-D-E.
                $filasHeaderParaLabels = array_values(array_diff($filasHeaderAdicionales, $filasTotalLider));

                $fields = $this->columnDetector->detect(
                    $worksheet, $filaHeader, $filaInicioDatos, $filaFinDatos, $highestCol, $sheetName, $codigo, $filaHeaderSuperior, $filasHeaderParaLabels, $bloquesSecundarios
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
            $filasTotalLider = [];
            $filasHeaderAdicionales = $this->findTrailingHeaderRows($worksheet, $filaHeader + 1, $highestRow, $highestCol, $filasTotalLider);
            $filaInicioDatos = (empty($filasHeaderAdicionales) ? $filaHeader : end($filasHeaderAdicionales)) + 1;

            $anchoConocido = $this->lastPlainTextColumnIndex($worksheet, $filaHeader, $highestCol);
            foreach ($filasHeaderAdicionales as $filaAdicional) {
                $anchoConocido = max($anchoConocido, $this->lastPlainTextColumnIndex($worksheet, $filaAdicional, $highestCol));
            }

            $filaFinDatos = $this->findImplicitDataEndRow($worksheet, $filaInicioDatos, $highestRow, $highestCol, $anchoConocido);

            $bloquesSecundarios = $this->findSecondaryHeaderBlocks(
                $worksheet, $filaInicioDatos, $filaFinDatos, $anchoConocido, $highestCol
            );

            $filasHeaderParaLabels = array_values(array_diff($filasHeaderAdicionales, $filasTotalLider));

            $fields = $this->columnDetector->detect(
                $worksheet, $filaHeader, $filaInicioDatos, $filaFinDatos, $highestCol, $sheetName, null, $filaHeaderSuperior, $filasHeaderParaLabels, $bloquesSecundarios
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

    /**
     * Busca filas de encabezado ADICIONALES inmediatamente despues de
     * $filaHeader -- hallazgo de la auditoria de A19a (2026-08-07):
     * secciones con encabezados de 2 o 3 niveles donde la fila superior YA
     * tiene texto en columna A (ej. "ÁREAS TEMÁTICAS", "ACTIVIDADES"), por
     * lo que findHeaderRow() la toma como encabezado de inmediato -- sin
     * poder ver que debajo hay 1 o 2 filas MAS de etiquetas por columna
     * (ej. rango etario en una fila, sexo en la siguiente; o categoria de
     * condicionante en una fila, condicionante especifico en la siguiente)
     * antes de que empiecen los datos reales.
     *
     * Criterio, general y sin hardcodear ninguna hoja/fila especifica: una
     * fila es un nivel adicional de encabezado si columna A esta vacia Y
     * tiene contenido en alguna otra columna (mismo criterio ya usado por
     * findHeaderRow() para detectar candidatas con A vacia) Y no contiene
     * ninguna formula DENTRO DEL ANCHO REAL YA ESTABLECIDO por el
     * encabezado -- las filas de datos reales del REM siempre traen al
     * menos una formula de total en una de las columnas que el propio
     * encabezado etiqueto (ej. la columna "TOTAL"), las filas de
     * encabezado nunca.
     *
     * Distingue una fila de encabezado real de una fila de datos real
     * usando DOS senales combinadas, no solo "tiene alguna formula":
     *
     *   1. Densidad: que proporcion del ancho ya establecido por el
     *      encabezado (columnas 1..$anchoEncabezado, expandido a medida
     *      que se aceptan mas niveles) esta poblada con texto plano en
     *      esta fila. Una fila de encabezado real (ej. "Hombres"/"Mujeres"
     *      repetido por cada tramo etario) llena la GRAN MAYORIA de ese
     *      ancho; una fila de datos real, cuando el concepto vive en una
     *      columna distinta de A (ej. B) y el resto de columnas del
     *      encabezado no le aplican, deja la mayor parte de ese ancho
     *      vacio.
     *   2. Formulas: si la densidad es alta (>50%, "parece encabezado"),
     *      solo se descarta la fila por una formula DENTRO del ancho ya
     *      establecido -- una formula de control aislada mucho mas alla de
     *      ese ancho no descarta la fila (ver hallazgo A28/A.2 abajo). Si
     *      la densidad es baja ("no parece encabezado"), se vuelve al
     *      criterio estricto original: cualquier formula en cualquier
     *      columna descarta la fila (hallazgo real de A19a/A.3: la fila de
     *      datos 108 solo tiene texto en columna B -- 1 de 4 columnas del
     *      encabezado, 25% de densidad -- con su formula de total en la
     *      columna E, inmediatamente despues; ahi SI debe descartarse).
     *
     * Por que el ancho se acota (no se escanea toda la hoja) para la
     * densidad alta: hallazgo real de A28/A.2 (2026-08-07): la ultima fila
     * de encabezado (sexo: "Hombres"/"Mujeres", 37 de 42 columnas
     * pobladas -- 88% de densidad) tenia una formula de control aislada en
     * una columna muy lejana (CM, columna ~91, muy mas alla del ancho real
     * del encabezado que llega hasta AP/columna 42) -- escanear formulas
     * en TODA la hoja descartaba esa fila como si fuera un dato real,
     * perdiendo sus etiquetas de sexo.
     *
     * Se detiene en la primera fila que no cumpla -- esa es la primera
     * fila de datos reales.
     *
     * No interfiere con el mecanismo existente de $filaHeaderSuperior
     * (encabezados de 2 filas donde A esta vacia DESDE el inicio, ej.
     * A11a A-K): ese caso ya devuelve como $filaHeader la ULTIMA fila antes
     * de los datos, por lo que la fila siguiente es directamente un dato
     * real (con formula) y este metodo no encuentra ninguna fila adicional.
     *
     * DOS extensiones agregadas durante la auditoria de A30 (2026-08-10),
     * ambas generales y sin hardcodear ninguna hoja/fila especifica:
     *
     *   a) Fila de CONTROL/SUBTOTAL pura: una fila sin NINGUN texto plano
     *      en ninguna columna (solo formulas, o vacia fuera de las
     *      formulas) se acepta como fila adicional que no aporta etiquetas
     *      (cleanLabel() ya descarta formulas, asi que contribuye vacio a
     *      cada columna) -- PERO solo si la fila SIGUIENTE tiene al menos
     *      un texto plano real (evidencia de que hay un concepto de datos
     *      genuino justo despues, no el fin de la seccion). Hallazgo real:
     *      A30/F fila 123, una fila de subtotales (=SUM(fila124:129))
     *      intercalada ENTRE el ultimo nivel de encabezado (fila 122) y el
     *      primer concepto real (fila 124), sin gap ni marcador que la
     *      separe -- sin esta regla, esa fila quedaria como filaInicioDatos.
     *      La condicion de "fila siguiente con texto real" evita que una
     *      fila con formula y SIN mas contenido despues (fin genuino de
     *      datos, ej. una unica fila con formula al final de una seccion
     *      corta) se trate incorrectamente como control/subtotal en vez de
     *      dato real -- caso cubierto por test_row_with_formula_and_empty_
     *      column_a_is_never_treated_as_header, que debe seguir sin cambios.
     *
     *   b) Fila con columna A POBLADA que sigue siendo nivel de encabezado:
     *      antes, CUALQUIER fila con A poblada detenia la busqueda de
     *      inmediato (asumiendo que A poblada siempre es el primer dato
     *      real, cierto para A01/A09/A11 con encabezado de 1 fila). Ahora
     *      se le aplica el MISMO criterio de densidad+formula ya usado
     *      para filas con A vacia, PERO solo si ya se acepto al menos un
     *      nivel adicional antes en esta misma seccion ($filas no vacio) --
     *      asi se distingue un tercer nivel de encabezado real (A30/F fila
     *      122, "Telecomité de especialidad": llega despues de que la fila
     *      121 con A vacia ya fue aceptada) de la primera fila de datos
     *      reales inmediatamente despues del encabezado (A01/A09: A
     *      poblada desde el primer candidato, sin ningun nivel adicional
     *      previo aceptado -- ese caso sigue deteniendose de inmediato,
     *      exactamente como antes).
     *
     * @return int[] filas adicionales de encabezado, en orden (vacio si no hay ninguna)
     */
    private function findTrailingHeaderRows(Worksheet $ws, int $startRow, int $maxRow, string $highestCol, array &$filasTotalLider = []): array
    {
        $filas = [];
        $filasTotalLider = [];
        $filaHeader = $startRow - 1;
        $anchoEncabezado = $this->lastPlainTextColumnIndex($ws, $filaHeader, $highestCol);
        $maxColIndex = $this->columnIndexFromLetter($highestCol);

        for ($row = $startRow; $row <= $maxRow; $row++) {
            $valColA = $this->extractCellText($ws->getCell('A' . $row)->getValue());
            if ($valColA !== null && preg_match(self::PATRON_SECCION, trim($valColA))) {
                // Corte duro: un marcador "SECCION ..." nunca es parte del
                // encabezado de la seccion actual, sin importar densidad o
                // formulas -- mismo guard ya usado en findHeaderRow() y
                // findDataEndRowWithGapDetection(). Bug real encontrado
                // durante la auditoria de A30 (2026-08-10): al dejar de
                // detener el loop de inmediato ante CUALQUIER fila con A
                // poblada (ver punto b abajo), el loop podia caminar a
                // traves del marcador de la SIGUIENTE seccion (baja
                // densidad, sin formula = "aceptada" por el criterio ya
                // existente) y aterrizar en los datos de esa otra seccion
                // -- confirmado en A28/A.11, que sin este guard terminaba
                // con los limites de B.1.
                break;
            }

            if (!$this->rowHasAnyContent($ws, $row, $maxColIndex)) {
                break;
            }

            if (!$this->rowHasAnyPlainText($ws, $row, $maxColIndex)) {
                // Fila de control/subtotal pura -- ver punto (a) arriba.
                // Solo se acepta como "salto" si la fila siguiente
                // demuestra que hay un concepto de datos real despues.
                $siguienteTieneTextoReal = $row + 1 <= $maxRow
                    && $this->rowHasAnyPlainText($ws, $row + 1, $maxColIndex);

                if ($siguienteTieneTextoReal) {
                    $filas[] = $row;
                    continue;
                }

                break;
            }

            $val = $ws->getCell('A' . $row)->getValue();
            $aEstaVacia = $val === null || trim((string) $val) === '';

            if (!$aEstaVacia && empty($filas)) {
                // A poblada como PRIMER candidato tras el encabezado, sin
                // ningun nivel adicional previo aceptado -- primer dato
                // real (criterio historico, sin cambios). Ver punto (b).
                break;
            }

            // NUEVO (auditoria A30, 2026-08-10, Hallazgo 1): fila TOTAL
            // INICIAL -- columna A con texto (ej. "TOTAL"), pero TODAS las
            // demas columnas pobladas son formulas que agregan
            // EXCLUSIVAMENTE filas POSTERIORES a esta misma fila (ej.
            // A30/A fila 12: "=SUM(B13:B67)", agrega las filas 13-67, que
            // vienen despues). Se distingue de un total POR FILA normal
            // (ej. fila de dato real con "=SUM(B13+J13)", que combina
            // columnas de la MISMA fila) exactamente por eso: sus
            // referencias nunca caen en la propia fila ni en filas
            // anteriores. Confirmado el mismo patron en A30/A (fila 12),
            // A30/C (fila 80) y A30/E (fila 114) -- las 3 tenian esta fila
            // TOTAL tratada como si fuera la primera fila de datos reales,
            // cuando en realidad es una fila de control sin ninguna celda
            // editable (100% formulas), igual que los totales generales ya
            // excluidos al FINAL de una seccion (findDataEndRowWithGapDetection)
            // -- aqui el mismo tipo de fila aparece al INICIO, sin gap.
            if (!$aEstaVacia && $this->isLeadingTotalRow($ws, $row, $maxColIndex)) {
                $siguienteTieneTextoReal = $row + 1 <= $maxRow
                    && $this->rowHasAnyPlainText($ws, $row + 1, $maxColIndex);

                if ($siguienteTieneTextoReal) {
                    // Se acumula para el calculo de filaInicioDatos (igual
                    // que antes), pero se marca aparte en $filasTotalLider
                    // -- ver uso en detect(), que la excluye del conjunto
                    // pasado a ColumnDetectorService::detect() para
                    // construir labels multinivel (hallazgo A30/A-C-D-E,
                    // 2026-08-21: el texto propio de esta fila, ej. "TOTAL",
                    // se filtraba como si fuera un nivel de encabezado
                    // legitimo, contaminando la etiqueta de la columna
                    // descriptiva y marcandola incorrectamente esTotal=true).
                    $filas[] = $row;
                    $filasTotalLider[] = $row;
                    continue;
                }

                break;
            }

            $densidad = $anchoEncabezado > 0
                ? $this->plainTextDensityWithinColumns($ws, $row, $anchoEncabezado) / $anchoEncabezado
                : 0.0;
            $pareceEncabezado = $densidad > 0.5;

            if (!$aEstaVacia && !$pareceEncabezado) {
                // A poblada, pero densidad baja: a diferencia de una fila
                // con A vacia (donde "densidad baja + sin formula" puede
                // seguir siendo un nivel de encabezado legitimo, criterio
                // ya probado en 20+ secciones), una fila con A poblada Y
                // baja densidad es casi siempre un dato real -- incluso sin
                // ninguna formula. Hallazgo real: A28/A.11, filas 128-143,
                // conceptos reales de solo texto (ej. A="Diagnóstico o
                // planificación...", B="Comunas, comunidades") SIN ninguna
                // formula en toda la seccion; sin este corte, la ausencia
                // de formula las dejaba pasar como si fueran encabezado,
                // tragandose los 16 conceptos reales completos.
                break;
            }

            $tieneFormulaDescalificante = $pareceEncabezado
                ? $this->rowHasFormulaWithinColumns($ws, $row, $anchoEncabezado)
                : $this->rowHasFormulaWithinColumns($ws, $row, $maxColIndex);

            if ($tieneFormulaDescalificante) {
                break;
            }

            // NUEVO (auditoria A33/C, 2026-08-11): celda genuinamente
            // capturable dentro del ancho de encabezado YA ESTABLECIDO
            // ($anchoEncabezado, el mismo usado arriba para densidad/formula,
            // no el ancho completo de la seccion). Hallazgo real: A33/C
            // filas 54-55 ("Modalidad remota"/"Modalidad presencial", concepto
            // en columna B, sin formula, densidad baja) se tragaban como
            // encabezado adicional por el mismo motivo que las protege el
            // chequeo anterior (sin formula) -- pero C54/D54/C55/D55 estan
            // desbloqueadas en el Excel (celdas de captura real, vacias solo
            // porque este archivo de referencia no tiene datos cargados
            // todavia), a diferencia de una fila de encabezado real, cuyas
            // celdas siempre estan bloqueadas.
            //
            // Ancho acotado deliberadamente a $anchoEncabezado, NUNCA al
            // ancho completo de campos de la seccion: verificado contra las
            // 138 filas que este metodo clasifica hoy como encabezado
            // adicional en las 10 hojas ya cerradas (A19a-A32) -- el unico
            // caso con alguna celda unprotected es A32/K fila 186 (columna
            // L, un residuo de formato sin relacion con captura real,
            // confirmado contra 38 ocurrencias historicas en rem_data con
            // valor siempre null), pero esa celda cae FUERA de
            // $anchoEncabezado en ese punto del escaneo -- si el chequeo
            // usara el ancho completo de la seccion, esa fila (un
            // encabezado real de rango etario) se habria excluido
            // incorrectamente.
            if ($this->rowHasUnprotectedCellWithinColumns($ws, $row, $anchoEncabezado)) {
                break;
            }

            $filas[] = $row;
            $anchoEncabezado = max($anchoEncabezado, $this->lastPlainTextColumnIndex($ws, $row, $highestCol));
        }

        return $filas;
    }

    /**
     * Una fila TOTAL inicial: columna A puede tener texto (ej. "TOTAL"),
     * pero CUALQUIER otra columna poblada debe ser una formula, y CADA
     * formula debe referenciar EXCLUSIVAMENTE filas posteriores a $row
     * (ver formulaReferencesOnlyRowsAfter()). Una sola columna con texto
     * plano fuera de A, o una sola formula que referencie la propia fila o
     * una anterior, descalifica la fila por completo -- no es un total
     * inicial, es un dato real.
     */
    private function isLeadingTotalRow(Worksheet $ws, int $row, int $maxColIndex): bool
    {
        $tieneAlgunaFormula = false;

        for ($col = 2; $col <= $maxColIndex; $col++) {
            $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $val = $ws->getCell($letra . $row)->getValue();
            if ($val === null || trim((string) $val) === '') {
                continue;
            }
            if (!is_string($val) || !str_starts_with($val, '=')) {
                return false;
            }
            if (!$this->formulaReferencesOnlyRowsAfter($val, $row)) {
                return false;
            }
            $tieneAlgunaFormula = true;
        }

        return $tieneAlgunaFormula;
    }

    /**
     * Extrae CADA referencia de celda (ej. "B13", "$C81", "AB67") de la
     * formula y confirma que TODOS los numeros de fila referenciados son
     * ESTRICTAMENTE mayores a $row -- ninguna referencia a la propia fila
     * ni a filas anteriores. Cubre tanto rangos ("=SUM(B13:B67)") como
     * cadenas de sumas individuales ("=B81+B82+...+B93"), los dos estilos
     * de formula de total encontrados en el REM real (A30/A usa el
     * primero, A30/C usa el segundo para parte de sus columnas).
     */
    private function formulaReferencesOnlyRowsAfter(string $formula, int $row): bool
    {
        if (!preg_match_all('/[A-Z]{1,3}(\d+)/', $formula, $matches)) {
            return false;
        }

        foreach ($matches[1] as $filaReferenciada) {
            if ((int) $filaReferenciada <= $row) {
                return false;
            }
        }

        return true;
    }

    private function rowHasAnyPlainText(Worksheet $ws, int $row, int $maxColIndex): bool
    {
        for ($col = 1; $col <= $maxColIndex; $col++) {
            $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $val = $ws->getCell($letra . $row)->getValue();
            if ($val === null || trim((string) $val) === '') {
                continue;
            }
            if (is_string($val) && str_starts_with($val, '=')) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function columnIndexFromLetter(string $highestCol): int
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
    }

    /**
     * Cantidad de columnas, dentro de 1..$maxColIndex, con contenido de
     * TEXTO PLANO (no formula) en la fila indicada -- usada para calcular
     * la densidad de findTrailingHeaderRows().
     */
    private function plainTextDensityWithinColumns(Worksheet $ws, int $row, int $maxColIndex): int
    {
        $count = 0;
        for ($col = 1; $col <= $maxColIndex; $col++) {
            $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $val = $ws->getCell($letra . $row)->getValue();
            if ($val === null || trim((string) $val) === '') {
                continue;
            }
            if (is_string($val) && str_starts_with($val, '=')) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * Ultima columna (indice) con contenido de TEXTO PLANO (no formula) en
     * la fila indicada -- usada para establecer/expandir el ancho real del
     * encabezado en findTrailingHeaderRows(). Ignora deliberadamente las
     * columnas con formula: una formula de control aislada no debe ampliar
     * el ancho del encabezado ni, por lo tanto, "auto-autorizarse" a si
     * misma en la siguiente fila.
     */
    private function lastPlainTextColumnIndex(Worksheet $ws, int $row, string $highestCol): int
    {
        $maxColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
        $ultimaColumna = 0;
        for ($col = 1; $col <= $maxColIndex; $col++) {
            $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $val = $ws->getCell($letra . $row)->getValue();
            if ($val === null || trim((string) $val) === '') {
                continue;
            }
            if (is_string($val) && str_starts_with($val, '=')) {
                continue;
            }
            $ultimaColumna = $col;
        }

        return $ultimaColumna;
    }

    /**
     * Busca bloques de encabezado SECUNDARIOS dentro del rango de datos ya
     * establecido de una seccion (entre filaInicioDatos y filaFinDatos) --
     * hallazgo real de A30/C (2026-08-10): un segundo bloque de encabezado
     * (filas 95-97) aparece 14 filas despues del encabezado principal, SIN
     * ningun marcador SECCION que lo anuncie, introduciendo columnas
     * genuinamente NUEVAS (Z-AJ) mas alla del ancho ya conocido por el
     * encabezado principal (que solo llega hasta Y).
     *
     * Generico: cualquier fila dentro del rango de datos con texto propio
     * en columna A (su propio eje de concepto) que ademas introduce texto
     * plano mas alla del ancho ya conocido se trata como el inicio de un
     * bloque secundario. Se reutiliza findTrailingHeaderRows() sin
     * modificar para resolver las filas adicionales de encabezado de ese
     * bloque y su propia fila de inicio de datos -- de paso, esto tambien
     * resuelve la exclusion de una fila TOTAL lider embebida DENTRO del
     * bloque secundario (ej. fila 98, ver isLeadingTotalRow()) sin codigo
     * adicional, porque findTrailingHeaderRows() ya la reconoce como tal.
     *
     * @return array<int, array{filaHeader: int, filasAdicionales: int[], columnaInicioNueva: int, filaInicioDatos: int}>
     */
    private function findSecondaryHeaderBlocks(
        Worksheet $ws,
        int $filaInicioDatos,
        ?int $filaFinDatos,
        int $anchoConocido,
        string $highestCol,
    ): array {
        $bloques = [];
        $maxRow = $filaFinDatos ?? $ws->getHighestRow();
        $maxColIndex = $this->columnIndexFromLetter($highestCol);
        $anchoActual = $anchoConocido;

        $row = $filaInicioDatos;
        while ($row <= $maxRow) {
            $valColA = $this->extractCellText($ws->getCell('A' . $row)->getValue());
            $aEstaVacia = $valColA === null || trim($valColA) === '';
            $esMarcadorSeccion = $valColA !== null && preg_match(self::PATRON_SECCION, trim($valColA));
            $tieneTextoNuevo = !$esMarcadorSeccion
                && $this->rowHasPlainTextBeyondColumn($ws, $row, $anchoActual, $maxColIndex);

            if (!$aEstaVacia && !$esMarcadorSeccion && $tieneTextoNuevo) {
                $filasTotalLiderSecundario = [];
                $filasAdicionales = $this->findTrailingHeaderRows($ws, $row + 1, $maxRow, $highestCol, $filasTotalLiderSecundario);
                $filaInicioDatosSecundario = (empty($filasAdicionales) ? $row : end($filasAdicionales)) + 1;

                $nuevoAncho = max($anchoActual, $this->lastPlainTextColumnIndex($ws, $row, $highestCol));
                foreach ($filasAdicionales as $filaAdicional) {
                    $nuevoAncho = max($nuevoAncho, $this->lastPlainTextColumnIndex($ws, $filaAdicional, $highestCol));
                }

                if ($nuevoAncho > $anchoActual) {
                    $bloques[] = [
                        'filaHeader' => $row,
                        // Igual que en detect(): se excluyen del set usado
                        // para labels solo las filas TOTAL lider, nunca del
                        // calculo de filaInicioDatos/ancho de arriba.
                        'filasAdicionales' => array_values(array_diff($filasAdicionales, $filasTotalLiderSecundario)),
                        'columnaInicioNueva' => $anchoActual + 1,
                        'filaInicioDatos' => $filaInicioDatosSecundario,
                    ];
                    $anchoActual = $nuevoAncho;
                }

                $row = max($filaInicioDatosSecundario, $row + 1);
                continue;
            }

            $row++;
        }

        return $bloques;
    }

    private function rowHasPlainTextBeyondColumn(Worksheet $ws, int $row, int $anchoActual, int $maxColIndex): bool
    {
        for ($col = $anchoActual + 1; $col <= $maxColIndex; $col++) {
            $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $val = $ws->getCell($letra . $row)->getValue();
            if ($val === null || trim((string) $val) === '') {
                continue;
            }
            if (is_string($val) && str_starts_with($val, '=')) {
                continue;
            }
            return true;
        }

        return false;
    }

    private function rowHasFormulaWithinColumns(Worksheet $ws, int $row, int $maxColIndex): bool
    {
        for ($col = 1; $col <= $maxColIndex; $col++) {
            $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $val = $ws->getCell($letra . $row)->getValue();
            if (is_string($val) && str_starts_with($val, '=')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evidencia positiva de captura real: al menos una celda desprotegida
     * (getProtection()->getLocked() === 'unprotected') dentro de las
     * columnas 1..$maxColIndex. Usado por findTrailingHeaderRows() para
     * distinguir una fila de encabezado real (siempre bloqueada) de una
     * fila de dato real cuyo concepto vive fuera de columna A y cuyas
     * celdas de captura estan vacias solo porque el archivo de referencia
     * no tiene datos cargados (hallazgo A33/C, 2026-08-11). $maxColIndex
     * debe ser el ancho de encabezado YA ESTABLECIDO ($anchoEncabezado),
     * nunca el ancho completo de campos de la seccion -- una celda
     * desprotegida fuera de ese ancho (ej. A32/K fila 186, columna L, un
     * residuo de formato sin relacion con captura real) no debe descartar
     * una fila que de otro modo es un encabezado real.
     */
    private function rowHasUnprotectedCellWithinColumns(Worksheet $ws, int $row, int $maxColIndex): bool
    {
        for ($col = 1; $col <= $maxColIndex; $col++) {
            $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            if ($ws->getCell($letra . $row)->getStyle()->getProtection()->getLocked() === 'unprotected') {
                return true;
            }
        }

        return false;
    }

    /**
     * Tolerancia de filas completamente vacias (sin contenido en ninguna
     * columna) dentro del rango de datos de una seccion antes de asumir que
     * la seccion realmente termino. Calibrado contra los gaps internos
     * legitimos observados en el REM real (maximo 2 filas vacias
     * consecutivas, ej. A09/F.1 filas 146-147) frente a los gaps que
     * preceden a un total general de hoja distante (26 a 57 filas vacias
     * consecutivas en los 4 casos reales auditados: A01/H.2, A09/K, A11/H,
     * A11a/N) -- ver findDataEndRowWithGapDetection().
     */
    private const MAX_FILAS_VACIAS_TOLERADAS = 2;

    private function findDataEndRow(Worksheet $ws, int $startRow, int $maxRow, string $highestCol, int $anchoSeccion): ?int
    {
        $end = $this->findDataEndRowWithGapDetection($ws, $startRow, $maxRow, $highestCol) ?? $maxRow;

        return $this->excludeTrailingTotalRows($ws, $end, $startRow, $anchoSeccion);
    }

    private function findImplicitDataEndRow(Worksheet $ws, int $startRow, int $maxRow, string $highestCol, int $anchoSeccion): ?int
    {
        $end = $this->findDataEndRowWithGapDetection($ws, $startRow, $maxRow, $highestCol);

        return $end === null ? null : $this->excludeTrailingTotalRows($ws, $end, $startRow, $anchoSeccion);
    }

    /**
     * Excluye del final de una seccion una o mas filas TOTAL finales
     * inmediatas (sin gap) -- hallazgo real de A31 (2026-08-10, filas 28,
     * 46, 66, 85): a diferencia del TOTAL lider (isLeadingTotalRow(), cuyas
     * formulas SOLO referencian filas posteriores), este patron es el
     * OPUESTO -- una fila con concepto propio en columna A situada al
     * cierre inmediato del rango de datos, cuyas formulas agregan filas
     * ANTERIORES de la misma seccion. Ninguno de los dos mecanismos
     * existentes (findDataEndRowWithGapDetection para el total general de
     * hoja con gap grande, isLeadingTotalRow para el total lider) cubre
     * este caso -- ambos requieren un patron distinto de gap o direccion de
     * formula.
     *
     * Sin esto, RemParserService::parseSheet() persiste esta fila como un
     * registro de negocio fantasma en rem_data, porque getCalculatedValue()
     * de una formula SUM siempre da un numero valido (confirmado
     * empiricamente: filas 28/46/66/85 de A31 ya estan en rem_data con
     * concept="TOTAL" en cada carga real historica).
     *
     * IMPORTANTE: esto NO reduce el rango de escaneo de cell_data -- ver
     * EnhancedCellScanner::extendScanForTrailingTotalRows(), que usa la
     * misma logica para seguir escaneando esta fila como referencia
     * tecnica (bloqueada/formula) aunque quede fuera de filaFinDatos.
     *
     * $anchoSeccion es el ANCHO REAL de la seccion (ultima columna con
     * texto plano en el encabezado, igual que $anchoConocido en detect()/
     * findSecondaryHeaderBlocks()) -- NUNCA el ancho de toda la hoja.
     * Hallazgo real de A32/D1 (2026-08-10): columnas de control oculto muy
     * mas alla del ancho real de la seccion (ej. CA-CR, formulas de
     * mensajes de validacion, patron ya conocido como "esControlOculto" en
     * ColumnDetectorService) estan presentes en TODAS las filas de datos,
     * no solo en el TOTAL -- y algunas de ellas referencian, por motivos
     * ajenos a cualquier agregacion (armar el texto de un mensaje de
     * error), una celda dentro de [filaInicioDatos, fila), lo que sin este
     * limite se confundia con evidencia de TOTAL y cortaba 53 filas de
     * datos reales (D1: fin correcto 89, bug daba 37). Acotar el analisis
     * al ancho real de la seccion excluye esas columnas de control por
     * construccion, igual que ya hace findTrailingHeaderRows() con
     * $anchoEncabezado.
     */
    private function excludeTrailingTotalRows(Worksheet $ws, int $endRow, int $startRow, int $anchoSeccion): ?int
    {
        while ($endRow >= $startRow && $this->isTrailingTotalRow($ws, $endRow, $startRow, $anchoSeccion)) {
            $endRow--;
        }

        return $endRow >= $startRow ? $endRow : null;
    }

    /**
     * Una fila TOTAL final: tiene UNA columna con texto propio (no formula)
     * -- su concepto/etiqueta, ej. "TOTAL" -- dentro del ancho real de la
     * seccion, y toda otra celda no vacia de esa fila (excluyendo esa
     * columna de concepto) es una formula. A diferencia de
     * isLeadingTotalRow(), una referencia a la PROPIA fila (ej.
     * "=+D28+E28", el subtotal horizontal por fila que TAMBIEN existe en
     * cualquier fila de dato real de la seccion, ej. fila 12) es NEUTRAL --
     * no descalifica ni cuenta como evidencia. Lo que distingue
     * genuinamente un TOTAL final de una fila de dato real normal es que AL
     * MENOS una formula referencia una fila ANTERIOR dentro de la misma
     * seccion (ej. "=SUM(F12:F27)") -- ninguna fila de dato real de A31
     * tiene esa evidencia hacia atras, solo formulas hacia su propia fila.
     * Cualquier referencia a una fila POSTERIOR a la actual descalifica de
     * inmediato (no es un patron de cierre).
     *
     * La columna de concepto NO esta fija en 'A' -- hallazgo real de
     * A32/F2 fila 151 (2026-08-10): la seccion agrupa varias filas bajo un
     * concepto de grupo puesto solo en la PRIMERA fila (columna A), y las
     * filas siguientes (incluida la fila TOTAL final) dejan A vacia y
     * ponen su propio sub-concepto en la columna B ("TOTAL" vive en B151,
     * no en A151). Se busca la PRIMERA columna (de izquierda a derecha,
     * dentro del ancho real de la seccion) con texto plano -- esa es la
     * columna de concepto de ESTA fila especificamente, no de toda la
     * seccion -- y se excluye solo esa columna del requisito de formula.
     * Si aparece una SEGUNDA columna con texto plano (no formula) fuera de
     * la de concepto, la fila se trata como dato real, no como TOTAL --
     * asi se evita confundir una fila de dato real con dos etiquetas
     * propias (ej. concepto + sub-concepto, fila 141 de A32/F2) con un
     * TOTAL genuino.
     */
    private function isTrailingTotalRow(Worksheet $ws, int $row, int $startRow, int $anchoSeccion): bool
    {
        $columnaConcepto = $this->findConceptColumnForTotalRow($ws, $row, $anchoSeccion);
        if ($columnaConcepto === null) {
            return false;
        }

        $tieneFormulaHaciaAtras = false;
        for ($col = 1; $col <= $anchoSeccion; $col++) {
            if ($col === $columnaConcepto) {
                continue;
            }

            $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $val = $ws->getCell($letra . $row)->getValue();
            if ($val === null || trim((string) $val) === '') {
                continue;
            }
            if (!is_string($val) || !str_starts_with($val, '=')) {
                return false;
            }
            if (!$this->formulaReferencesOnlyRowsUpTo($val, $row)) {
                return false;
            }
            if ($this->formulaReferencesAnyRowBefore($val, $row, $startRow)) {
                $tieneFormulaHaciaAtras = true;
            }
        }

        return $tieneFormulaHaciaAtras;
    }

    /**
     * Busca la PRIMERA columna con texto plano y verifica que su contenido
     * parezca una etiqueta de TOTAL ("TOTAL"/"SUBTOTAL"/"AMBOS SEXOS" --
     * mismo criterio ya usado por ColumnDetectorService::isTotalColumn()).
     * Si esa primera columna con texto NO parece una etiqueta de TOTAL, la
     * fila se descarta de inmediato como candidata -- hallazgo real de
     * A09/I fila 336 (2026-08-10): "Altas administrativas" es un CONCEPTO
     * DE NEGOCIO REAL (un dato derivado/calculado con formulas que agregan
     * otras filas de la misma seccion por definicion metodologica del REM,
     * no un TOTAL de cierre), en columna B (A vacia). Sin este chequeo, la
     * generalizacion de columna de concepto (hallazgo A32/F2) confundia
     * cualquier fila de dato real cuyo unico texto propio viviera fuera de
     * A y tuviera formulas hacia atras con una fila TOTAL genuina --
     * perdiendo 3 filas de datos reales. Requerir que el texto realmente
     * diga "TOTAL" (o similar) es la misma señal que todas las filas TOTAL
     * ya confirmadas en esta campaña (A19a-A31) siempre tuvieron, incluso
     * antes de esta generalizacion.
     */
    private function findConceptColumnForTotalRow(Worksheet $ws, int $row, int $anchoSeccion): ?int
    {
        for ($col = 1; $col <= $anchoSeccion; $col++) {
            $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $val = $ws->getCell($letra . $row)->getValue();
            if ($val === null || trim((string) $val) === '') {
                continue;
            }
            if (is_string($val) && str_starts_with($val, '=')) {
                continue;
            }

            return $this->pareceEtiquetaTotal($val) ? $col : null;
        }

        return null;
    }

    private function pareceEtiquetaTotal(mixed $valor): bool
    {
        $texto = mb_strtoupper(trim((string) $valor), 'UTF-8');

        return str_contains($texto, 'TOTAL') || str_contains($texto, 'AMBOS SEXOS');
    }

    private function formulaReferencesOnlyRowsUpTo(string $formula, int $row): bool
    {
        if (!preg_match_all('/[A-Z]{1,3}(\d+)/', $formula, $matches)) {
            return false;
        }

        foreach ($matches[1] as $filaReferenciada) {
            if ((int) $filaReferenciada > $row) {
                return false;
            }
        }

        return true;
    }

    private function formulaReferencesAnyRowBefore(string $formula, int $row, int $startRow): bool
    {
        if (!preg_match_all('/[A-Z]{1,3}(\d+)/', $formula, $matches)) {
            return false;
        }

        foreach ($matches[1] as $filaReferenciada) {
            $fr = (int) $filaReferenciada;
            if ($fr < $row && $fr >= $startRow) {
                return true;
            }
        }

        return false;
    }

    /**
     * Busca la fila de corte de una seccion, en orden de prioridad:
     *
     *   1. El siguiente marcador "SECCION ..." -- fin exacto (comportamiento
     *      historico, sin cambios).
     *   2. Una racha de mas de MAX_FILAS_VACIAS_TOLERADAS filas completamente
     *      vacias (ninguna columna poblada). Cuando aparece, la seccion
     *      termina en la ULTIMA fila con contenido antes de esa racha, no en
     *      la fila donde eventualmente reaparece contenido -- ese contenido
     *      distante (tipicamente un total general de la hoja o una formula
     *      resumen externa) no es parte de la seccion.
     *
     * Si ninguna de las dos senales aparece, devuelve null: cada metodo
     * publico decide su propio valor por defecto en ese caso (findDataEndRow
     * usa $maxRow como antes; findImplicitDataEndRow usa null como antes).
     * No hay ningun conocimiento de hoja/seccion/fila especifica aqui -- el
     * criterio es puramente estructural (presencia/ausencia de contenido).
     */
    private function findDataEndRowWithGapDetection(Worksheet $ws, int $startRow, int $maxRow, string $highestCol): ?int
    {
        $maxColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
        $ultimaFilaConContenido = null;
        $rachaVacias = 0;

        for ($row = $startRow; $row <= $maxRow; $row++) {
            $val = $this->extractCellText($ws->getCell('A' . $row)->getValue());
            if ($val !== null && preg_match(self::PATRON_SECCION, $val)) {
                return $row - 1;
            }

            if ($this->rowHasAnyContent($ws, $row, $maxColIndex)) {
                $ultimaFilaConContenido = $row;
                $rachaVacias = 0;
                continue;
            }

            $rachaVacias++;
            if ($ultimaFilaConContenido !== null && $rachaVacias > self::MAX_FILAS_VACIAS_TOLERADAS) {
                return $ultimaFilaConContenido;
            }
        }

        return null;
    }

    private function rowHasAnyContent(Worksheet $ws, int $row, int $maxColIndex): bool
    {
        for ($col = 1; $col <= $maxColIndex; $col++) {
            $letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $val = $ws->getCell($letra . $row)->getValue();
            if ($val !== null && trim((string) $val) !== '') {
                return true;
            }
        }

        return false;
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
                if (!$this->esCodigoSubseccion($codigo, $otro)) continue;

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

    /**
     * Determina si $otro es una subseccion de $codigo -- hallazgo real de
     * A32 (2026-08-10): "SECCION D" (fila 30) y "SECCION D1" (fila 31) son
     * marcadores consecutivos con el MISMO filaHeader (D no tiene ningun
     * contenido propio), pero el codigo hijo "D1" no usa punto separador
     * (a diferencia del patron ya conocido "F.1", "H.2"). El criterio
     * anterior (str_starts_with($otro, $codigo . '.')) nunca reconocia
     * este caso, dejando a D como una seccion fantasma duplicada de D1
     * (mismo rango y campos). Mismo problema para E/E1 y F/F1.
     *
     * Generico: $otro es subseccion de $codigo si empieza EXACTAMENTE con
     * $codigo y el caracter INMEDIATAMENTE siguiente es un punto (patron
     * ya validado: "F" + ".1") o un digito (patron nuevo: "D" + "1"). Se
     * exige el caracter inmediato siguiente sea punto o digito -- nunca
     * cualquier caracter -- para no confundir un codigo de seccion
     * COMPLETAMENTE DISTINTO que solo comparte el prefijo por coincidencia
     * (ej. "AB" jamas debe tratarse como subseccion de "A") con una
     * jerarquia real. La confirmacion real de que el padre es un
     * agregador puro sigue siendo, sin cambios, que su filaHeader sea
     * IDENTICO al de la subseccion (cero contenido propio antes del
     * marcador de la subseccion) -- esta funcion solo amplia que
     * candidatos se evaluan para esa confirmacion.
     */
    private function esCodigoSubseccion(string $codigo, string $otro): bool
    {
        if (!str_starts_with($otro, $codigo)) {
            return false;
        }

        $resto = substr($otro, strlen($codigo));
        if ($resto === '') {
            return false;
        }

        return $resto[0] === '.' || ctype_digit($resto[0]);
    }
}
