<?php

namespace Tests\Unit\RemParser\Services;

use App\Domain\RemParser\Services\SectionDetectorService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

/**
 * Cubre findTrailingHeaderRows() -- hallazgo de la auditoria de A19a
 * (2026-08-07): secciones con encabezados de 2 o 3 niveles donde la fila
 * superior YA tiene texto en columna A (ej. "ÁREAS TEMÁTICAS",
 * "ACTIVIDADES", "CONDICIÓN"), por lo que findHeaderRow() la toma como
 * encabezado de inmediato -- sin poder ver que debajo hay 1 o 2 filas MAS
 * de etiquetas por columna antes de que empiecen los datos reales. Esto es
 * un caso DISTINTO del ya cubierto por SectionDetectorServiceTwoRowHeaderTest
 * (filaHeaderSuperior): alli columna A esta vacia DESDE el inicio del
 * encabezado; aqui columna A tiene texto desde la primera fila y las filas
 * adicionales vienen DESPUES.
 *
 * Confirmado contra el REM real que este mismo patron aparece, ademas de en
 * A19a (A.1, A.2, A.4, B.1, C), en la gran mayoria de las secciones de A01,
 * A09, A11 y en A11a/L, M, N -- el criterio implementado es generico
 * (columna A vacia + contenido en otra columna + sin formulas = nivel
 * adicional de encabezado) y no depende de ninguna hoja/seccion/fila
 * especifica.
 */
class SectionDetectorServiceMultiLevelHeaderTest extends TestCase
{
    private function service(): SectionDetectorService
    {
        return new SectionDetectorService();
    }

    private function sheet(array $rows): Worksheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('HOJATEST');

        foreach ($rows as $rowNumber => $cols) {
            foreach ($cols as $colLetter => $value) {
                $sheet->setCellValue($colLetter . $rowNumber, $value);
            }
        }

        return $sheet;
    }

    private function fieldsByLetter(array $sections, string $codigo): array
    {
        $section = current(array_filter($sections, fn ($s) => $s->codigo === $codigo));
        $byLetter = [];
        foreach ($section->fields as $f) {
            $byLetter[$f->letra] = $f;
        }

        return $byLetter;
    }

    /**
     * Encabezado de 1 fila (caso de control): columna A tiene texto, la
     * fila siguiente es un dato real (con formula). No debe encontrarse
     * ninguna fila adicional de encabezado -- comportamiento identico al
     * criterio historico.
     */
    public function test_single_row_header_is_unaffected(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION X: CONTROL'],
            2 => ['A' => 'Concepto', 'B' => 'Total', 'C' => 'Hombres'],
            3 => ['A' => 'Actividad 1', 'B' => '=SUM(C3:D3)', 'C' => 5],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'X'));

        $this->assertSame(2, $section->filaHeader);
        $this->assertSame(3, $section->filaInicioDatos);

        $fields = $this->fieldsByLetter($sections, 'X');
        $this->assertSame('Concepto', $fields['A']->label);
        $this->assertSame('Total', $fields['B']->label);
        $this->assertSame('Hombres', $fields['C']->label);
    }

    /**
     * Encabezado de 2 niveles con columna A poblada en el nivel superior
     * (patron real de A19a/B.1: "CONDICIONANTES ABORDADAS" en la fila
     * superior, condicionante especifico -- "Actividad física",
     * "Alimentación" -- en la fila inferior). La fila de datos reales
     * (con formula) determina donde termina el encabezado.
     */
    public function test_two_level_trailing_header_with_text_in_column_a_combines_labels(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION Y: GESTION'],
            2 => ['A' => 'ACTIVIDADES', 'B' => 'TOTAL', 'C' => 'CONDICIONANTES ABORDADAS'],
            3 => ['C' => 'Actividad física', 'D' => 'Alimentación'],
            4 => ['A' => 'Eventos masivos', 'B' => '=SUM(C4:D4)'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'Y'));

        $this->assertSame(2, $section->filaHeader);
        $this->assertSame(4, $section->filaInicioDatos, 'la fila 3 (A vacia, sin formulas) es un nivel adicional de encabezado, no un dato');

        $fields = $this->fieldsByLetter($sections, 'Y');
        $this->assertSame('ACTIVIDADES', $fields['A']->label);
        $this->assertSame('TOTAL', $fields['B']->label);
        $this->assertSame('CONDICIONANTES ABORDADAS / Actividad física', $fields['C']->label);
        $this->assertSame('Alimentación', $fields['D']->label, 'columna D solo tiene etiqueta en el nivel inferior (fila 3); el nivel superior esta vacio para D');
    }

    /**
     * Encabezado de 3 niveles con columna A poblada en el nivel superior
     * (patron real de A19a/A.1, A.2, A.4, C -- y tambien A01, A09, A11,
     * A11a/L,M,N): categoria general (ej. "RANGO ETARIO Y SEXO"), rango
     * etario, sexo. Las 3 etiquetas deben combinarse en orden.
     */
    public function test_three_level_trailing_header_combines_all_levels(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION Z: CONSEJERIAS'],
            2 => ['A' => 'ÁREAS TEMÁTICAS', 'B' => 'PROFESIONAL', 'C' => 'TOTAL', 'D' => 'RANGO ETARIO Y SEXO'],
            3 => ['D' => '0-4 años', 'F' => '5-9 años'],
            4 => ['C' => 'Ambos Sexos', 'D' => 'Hombres', 'E' => 'Mujeres', 'F' => 'Hombres', 'G' => 'Mujeres'],
            5 => ['A' => 'Actividad física', 'B' => 'Médico/a', 'C' => '=SUM(D5+E5)'],
        ]);
        // "RANGO ETARIO Y SEXO" es una categoria general fusionada sobre
        // todo el bloque de columnas D:G, igual que en el REM real.
        $ws->mergeCells('D2:G2');

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'Z'));

        $this->assertSame(2, $section->filaHeader);
        $this->assertSame(5, $section->filaInicioDatos, 'las filas 3 y 4 (A vacia, sin formulas) son niveles adicionales de encabezado');

        $fields = $this->fieldsByLetter($sections, 'Z');
        $this->assertSame('ÁREAS TEMÁTICAS', $fields['A']->label);
        $this->assertSame('PROFESIONAL', $fields['B']->label);
        $this->assertSame('TOTAL / Ambos Sexos', $fields['C']->label);
        $this->assertSame('RANGO ETARIO Y SEXO / 0-4 años / Hombres', $fields['D']->label);
        $this->assertSame('RANGO ETARIO Y SEXO / Mujeres', $fields['E']->label, 'columna E hereda la categoria fusionada (D2:G2) pero no tiene sub-etiqueta propia en la fila 3');
        $this->assertSame('RANGO ETARIO Y SEXO / 5-9 años / Hombres', $fields['F']->label);
        $this->assertSame('RANGO ETARIO Y SEXO / Mujeres', $fields['G']->label);
    }

    /**
     * Celda fusionada VERTICALMENTE entre dos filas de encabezado (ej.
     * "Ambos sexos" en B4:B5, patron real de A11a/N): el valor resuelve al
     * mismo texto en cada fila que cubre el merge, y no debe repetirse en
     * la etiqueta combinada final -- hallazgo puntual detectado al probar
     * este fix contra A11a/N (columna B daba "TOTAL / Ambos sexos / Ambos
     * sexos" antes de la deduplicacion).
     */
    public function test_vertically_merged_cell_does_not_duplicate_in_combined_label(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION W: EJEMPLO'],
            2 => ['A' => 'CONDICIÓN', 'B' => 'TOTAL'],
            3 => ['B' => 'Ambos sexos'],
            4 => ['A' => 'Concepto 1', 'B' => '=SUM(C4:D4)'],
        ]);
        $ws->mergeCells('B3:B4');
        // Reescribe la fila 4 despues del merge para que B4 (parte del
        // merge, valor logico null) no aporte contenido propio -- el merge
        // ya resuelve B4 al valor de B3 via getMergedCellValue().
        $ws->setCellValue('A4', 'Concepto 1');
        $ws->setCellValue('C4', '=SUM(D4:E4)');

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'W'));
        $fields = $this->fieldsByLetter($sections, 'W');

        $this->assertSame('TOTAL / Ambos sexos', $fields['B']->label, 'no debe repetirse "Ambos sexos" dos veces por el merge vertical B3:B4');
    }

    /**
     * Una fila con columna A vacia pero que SI contiene una formula no debe
     * tratarse como nivel adicional de encabezado -- las filas de datos
     * reales del REM siempre traen formulas de total; tratarla como
     * encabezado perderia una fila de datos real.
     */
    public function test_row_with_formula_and_empty_column_a_is_never_treated_as_header(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION V: EJEMPLO'],
            2 => ['A' => 'Concepto', 'B' => 'Total'],
            3 => ['B' => '=SUM(C3:D3)'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'V'));

        $this->assertSame(3, $section->filaInicioDatos, 'la fila 3 tiene una formula -- debe tratarse como dato, no como encabezado adicional, aunque A este vacia');
    }

    /**
     * Fila de encabezado DENSA (llena la gran mayoria del ancho ya
     * establecido con texto plano, ej. "Ambos Sexos"/"Hombres" repetido
     * por columna) con una formula de control AISLADA muy lejos de ese
     * ancho no debe descartarse -- hallazgo real de A28/A.2 (2026-08-07):
     * la fila de sexo (37 de 42 columnas pobladas, 88% de densidad) tenia
     * una formula de control en una columna ~50 posiciones mas alla del
     * ancho real del encabezado; tratarla como dato perdia las etiquetas
     * de sexo de toda la seccion.
     */
    public function test_dense_header_row_with_a_distant_stray_formula_is_still_treated_as_header(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION Y2: EJEMPLO'],
            2 => ['A' => 'CATEGORIA', 'B' => 'TOTAL', 'C' => 'RANGO'],
            3 => ['B' => 'Ambos Sexos', 'C' => 'Hombres', 'T' => '=IF(B4<>SUM(B5:B10),1,0)'],
            4 => ['A' => 'Concepto 1', 'B' => '=SUM(C4:D4)'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'Y2'));

        $this->assertSame(4, $section->filaInicioDatos, 'la fila 3 es densa (2 de 3 columnas del encabezado pobladas) -- su formula aislada en T no debe descalificarla');

        $fields = $this->fieldsByLetter($sections, 'Y2');
        $this->assertSame('TOTAL / Ambos Sexos', $fields['B']->label);
        $this->assertSame('RANGO / Hombres', $fields['C']->label);
    }

    /**
     * Fila candidata ESCASA (solo 1 de 4 columnas del encabezado poblada,
     * 25% de densidad) con una formula INMEDIATAMENTE cercana SI debe
     * descartarse como dato real -- hallazgo real de A19a/A.3
     * (2026-08-07): la fila 108 solo tiene texto en columna B ("Con riesgo
     * psicosocial", el concepto real de esa fila de datos) con su formula
     * de total en la columna E, justo despues; tratarla como encabezado
     * perdia 10 filas de datos reales completas.
     */
    public function test_sparse_candidate_row_with_a_nearby_formula_is_treated_as_data(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION Z2: EJEMPLO'],
            2 => ['A' => 'PROFESIONAL', 'B' => 'TOTAL', 'C' => 'TIPO', 'D' => 'OTRO'],
            3 => ['B' => 'Con riesgo psicosocial', 'E' => '=SUM(F3:G3)'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'Z2'));

        $this->assertSame(3, $section->filaInicioDatos, 'la fila 3 es escasa (1 de 4 columnas del encabezado pobladas) -- su formula cercana en E SI debe descalificarla como dato real');
    }

    /**
     * Encabezado real de 3 NIVELES donde el ULTIMO nivel tiene columna A
     * poblada -- hallazgo real de A30/F (2026-08-10): fila 120 (categoria
     * general), fila 121 (nivel intermedio, solo aplica a un subconjunto de
     * columnas), fila 122 (nivel inferior, DENSO, con columna A poblada
     * "Telecomité de especialidad" -- NO es un dato real, es el tercer
     * nivel del mismo bloque de encabezado), fila 123 (subtotal/formulas
     * de control, intercalada sin gap antes del primer dato real), fila
     * 124 (primer concepto real "Oncológico").
     *
     * Antes de esta correccion, CUALQUIER fila con columna A poblada
     * detenia la busqueda de inmediato (asumiendo que A poblada siempre es
     * el primer dato real) -- perdiendo el tercer nivel de etiquetas Y
     * tratando la fila de subtotal como si fuera el primer dato.
     */
    public function test_three_level_header_with_populated_column_a_on_the_last_level(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION F2: EJEMPLO'],
            2 => ['A' => 'Actividad', 'B' => 'TOTAL', 'C' => 'Telecomité realizados', 'G' => 'Total Nº de casos'],
            3 => ['C' => '0 - 10 años', 'D' => '15 - 17 años'],
            4 => [
                'A' => 'Telecomité de especialidad',
                'C' => '0 - 10 años', 'D' => '15 - 17 años',
                'G' => 'Ambos Sexos', 'H' => 'Hombres', 'I' => 'Mujeres',
            ],
            5 => ['B' => '=SUM(B6:B11)', 'C' => '=SUM(C6:C11)', 'D' => '=SUM(D6:D11)', 'G' => '=SUM(G6:G11)', 'H' => '=SUM(H6:H11)', 'I' => '=SUM(I6:I11)'],
            6 => ['A' => 'Oncológico', 'B' => '=SUM(C6:D6)', 'G' => '=SUM(H6:I6)'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'F2'));

        $this->assertSame(2, $section->filaHeader);
        $this->assertSame(6, $section->filaInicioDatos, 'las filas 3 (nivel intermedio), 4 (nivel inferior con A poblada) y 5 (subtotal de control) deben tratarse todas como encabezado/control -- el primer dato real es la fila 6');

        $fields = $this->fieldsByLetter($sections, 'F2');
        $this->assertSame('TOTAL', $fields['B']->label);
        $this->assertSame('Telecomité realizados / 0 - 10 años', $fields['C']->label);
        $this->assertSame('Total Nº de casos / Ambos Sexos', $fields['G']->label);
        $this->assertSame('Hombres', $fields['H']->label, 'columna H solo tiene etiqueta en el ultimo nivel (fila 4)');
    }

    /**
     * Regresion explicita: un encabezado NORMAL de 1 sola fila (columna A
     * poblada desde el primer candidato tras el encabezado, patron
     * A01/A09/A11) NO debe verse afectado por la nueva capacidad de
     * aceptar filas con A poblada como encabezado adicional -- esa
     * capacidad solo debe activarse cuando YA se acepto al menos un nivel
     * adicional antes (columna A vacia). Sin este limite, CUALQUIER
     * primera fila de datos con alta densidad y sin formulas (ej. una fila
     * de ejemplo con valores literales, no formulas) se clasificaria
     * incorrectamente como encabezado.
     */
    public function test_first_row_with_populated_column_a_and_no_prior_trailing_row_is_still_data(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION G2: EJEMPLO'],
            2 => ['A' => 'Concepto', 'B' => 'Total', 'C' => 'Hombres', 'D' => 'Mujeres'],
            3 => ['A' => 'Actividad 1', 'B' => 5, 'C' => 2, 'D' => 3],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'G2'));

        $this->assertSame(3, $section->filaInicioDatos, 'fila 3 es la PRIMERA candidata tras el encabezado (sin ningun nivel adicional previo aceptado) -- debe seguir siendo dato, aunque tenga alta densidad y ninguna formula');
    }

    /**
     * Fila de formulas puras (subtotal/control) que NO esta seguida de
     * ningun dato real (fin genuino de la seccion) NO debe tratarse como
     * fila de control saltable -- debe seguir siendo la primera (y unica)
     * fila de datos. Distingue el subtotal real de A30/F (fila 123,
     * seguida de un concepto real en 124) de una fila con formula que es
     * autenticamente el unico dato de la seccion.
     */
    public function test_formula_only_row_not_followed_by_real_data_is_not_treated_as_control_row(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION H2: EJEMPLO'],
            2 => ['A' => 'Concepto', 'B' => 'Total'],
            3 => ['B' => '=SUM(C3:D3)'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'H2'));

        $this->assertSame(3, $section->filaInicioDatos, 'fila 3 (formula, sin texto) no tiene ninguna fila real despues -- no debe saltarse como control, debe tratarse como el (unico) dato');
    }

    /**
     * Fila TOTAL INICIAL: columna A con texto ("TOTAL"), pero todas las
     * demas columnas son formulas que agregan EXCLUSIVAMENTE filas
     * posteriores a si misma -- hallazgo real de A30/A, C, E (2026-08-10,
     * Hallazgo 1 de la investigacion, opcion B aprobada): una fila TOTAL
     * situada al INICIO de la seccion (antes de los conceptos individuales,
     * no al final), sin gap que la separe -- debe excluirse de rem_data
     * igual que un total general excluido al final de una seccion.
     */
    public function test_leading_total_row_referencing_only_rows_after_is_excluded_from_data(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION I2: EJEMPLO'],
            2 => ['A' => 'Especialidades', 'B' => 'TOTAL', 'C' => 'RANGO'],
            3 => ['B' => 'Hombres', 'C' => 'Mujeres'],
            4 => ['A' => 'TOTAL', 'B' => '=SUM(B5:B10)', 'C' => '=SUM(C5:C10)'],
            5 => ['A' => 'Pediatría', 'B' => '=SUM(C5+D5)'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'I2'));

        $this->assertSame(5, $section->filaInicioDatos, 'fila 4 (TOTAL, formulas que agregan solo filas posteriores 5-10) debe excluirse -- el primer dato real es la fila 5');
    }

    /**
     * Regresion explicita: una fila con columna A poblada y formulas que
     * referencian la PROPIA fila (patron normal de un dato real con
     * columna de total por-fila, ej. "=SUM(B13+J13)") NUNCA debe
     * confundirse con una fila TOTAL inicial -- distingue el patron real
     * de A30/A fila 12 (TOTAL, agrega filas de ABAJO) del patron normal de
     * cualquier fila de dato real ya auditada en A01/A09/A11 (con formula
     * de total combinando columnas de la MISMA fila).
     */
    public function test_row_with_formula_referencing_its_own_row_is_never_treated_as_leading_total(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION J2: EJEMPLO'],
            2 => ['A' => 'Especialidades', 'B' => 'TOTAL', 'C' => 'Hombres', 'D' => 'Mujeres'],
            3 => ['A' => 'Pediatría', 'B' => '=SUM(C3+D3)'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'J2'));

        $this->assertSame(3, $section->filaInicioDatos, 'la formula de la fila 3 referencia su PROPIA fila (C3, D3) -- nunca debe tratarse como total inicial, es un dato real normal');
    }
}
