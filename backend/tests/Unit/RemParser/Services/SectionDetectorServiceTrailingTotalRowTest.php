<?php

namespace Tests\Unit\RemParser\Services;

use App\Domain\RemParser\Services\SectionDetectorService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

/**
 * Cubre excludeTrailingTotalRows()/isTrailingTotalRow() -- hallazgo real de
 * A31 (2026-08-10): filas 28, 46, 66, 85 son TOTAL final inmediato (sin
 * gap) al cierre de cada seccion, con formulas que agregan filas ANTERIORES
 * dentro de la misma seccion. Patron OPUESTO al TOTAL lider ya cubierto por
 * isLeadingTotalRow() (formulas que agregan SOLO filas posteriores) --
 * ninguno de los mecanismos existentes (findDataEndRowWithGapDetection,
 * isLeadingTotalRow) cubre este caso.
 *
 * Confirmado empiricamente contra rem_data que sin este fix estas filas se
 * persisten como registros de negocio fantasma (concept="TOTAL") en cada
 * carga real, porque getCalculatedValue() de una formula SUM siempre da un
 * numero valido.
 */
class SectionDetectorServiceTrailingTotalRowTest extends TestCase
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

    private function section(array $sections, string $codigo)
    {
        return current(array_filter($sections, fn ($s) => $s->codigo === $codigo));
    }

    /**
     * Caso 1: TOTAL final con SUM simple de filas anteriores (patron real
     * de A31/B fila 46: "=SUM(B32:B45)") -- debe excluirse.
     */
    public function test_trailing_total_row_with_simple_backward_sum_is_excluded(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION X3: EJEMPLO'],
            2 => ['A' => 'Concepto', 'B' => 'TOTAL'],
            3 => ['A' => 'Item 1', 'B' => '=SUM(C3:D3)'],
            4 => ['A' => 'Item 2', 'B' => '=SUM(C4:D4)'],
            5 => ['A' => 'TOTAL', 'B' => '=SUM(B3:B4)'],
            6 => ['A' => 'SECCION Y3: SIGUIENTE'],
        ]);

        $section = $this->section($this->service()->detect($ws, 'HOJATEST'), 'X3');

        $this->assertSame(4, $section->filaFinDatos, 'fila 5 (TOTAL, SUM hacia atras) debe excluirse de filaFinDatos');
    }

    /**
     * Caso 2: TOTAL final con formula COMPUESTA -- mezcla de referencia a
     * su propia fila (subtotal horizontal, ej. "=+C5+D5") Y referencia
     * hacia filas anteriores (ej. "=SUM(C3:C4)") -- patron real de A31/A
     * fila 28 y A31/D fila 85. La referencia a la propia fila es NEUTRAL
     * (tambien existe en filas de dato real), pero la referencia hacia
     * atras es evidencia suficiente para excluir.
     */
    public function test_trailing_total_row_with_mixed_same_row_and_backward_formula_is_excluded(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION X4: EJEMPLO'],
            2 => ['A' => 'Concepto', 'B' => 'TOTAL', 'C' => 'Hombres', 'D' => 'Mujeres'],
            3 => ['A' => 'Item 1', 'B' => '=+C3+D3', 'C' => 5, 'D' => 3],
            4 => ['A' => 'Item 2', 'B' => '=+C4+D4', 'C' => 2, 'D' => 1],
            5 => ['A' => 'TOTAL', 'B' => '=+C5+D5', 'C' => '=SUM(C3:C4)', 'D' => '=SUM(D3:D4)'],
            6 => ['A' => 'SECCION Y4: SIGUIENTE'],
        ]);

        $section = $this->section($this->service()->detect($ws, 'HOJATEST'), 'X4');

        $this->assertSame(4, $section->filaFinDatos, 'fila 5 (formula compuesta: subtotal propio + agregacion hacia atras) debe excluirse');
    }

    /**
     * Caso 3: la ULTIMA fila de la seccion tiene formula (su propio
     * subtotal horizontal, "=+C4+D4") pero SI representa un dato real --
     * ninguna de sus formulas referencia una fila anterior. No debe
     * excluirse. Distingue el patron real de A31 (filas de dato normal,
     * ej. fila 12, cuyo unico formula es "=+D12+E12", sin evidencia hacia
     * atras) del TOTAL final genuino.
     */
    public function test_last_row_with_only_same_row_formula_is_treated_as_real_data(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION X5: EJEMPLO'],
            2 => ['A' => 'Concepto', 'B' => 'TOTAL', 'C' => 'Hombres', 'D' => 'Mujeres'],
            3 => ['A' => 'Item 1', 'B' => '=+C3+D3', 'C' => 5, 'D' => 3],
            4 => ['A' => 'Item 2', 'B' => '=+C4+D4', 'C' => 2, 'D' => 1],
        ]);

        $section = $this->section($this->service()->detect($ws, 'HOJATEST'), 'X5');

        $this->assertSame(4, $section->filaFinDatos, 'fila 4 es dato real (solo subtotal de su propia fila, sin referencia hacia atras) -- no debe excluirse');
    }

    /**
     * Caso 4 (regresion): el TOTAL LIDER ya resuelto (isLeadingTotalRow,
     * Hallazgo 1 de A30) no debe verse afectado por este mecanismo nuevo,
     * que es independiente y aditivo.
     */
    public function test_leading_total_row_mechanism_is_not_broken_by_trailing_total_addition(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION X6: EJEMPLO'],
            2 => ['A' => 'Especialidades', 'B' => 'TOTAL', 'C' => 'RANGO'],
            3 => ['B' => 'Hombres', 'C' => 'Mujeres'],
            4 => ['A' => 'TOTAL', 'B' => '=SUM(B5:B10)', 'C' => '=SUM(C5:C10)'],
            5 => ['A' => 'Pediatría', 'B' => '=SUM(C5+D5)'],
        ]);

        $section = $this->section($this->service()->detect($ws, 'HOJATEST'), 'X6');

        $this->assertSame(5, $section->filaInicioDatos, 'fila 4 (TOTAL lider, formulas hacia adelante) debe seguir excluyendose -- comportamiento sin cambios');
    }

    /**
     * Caso 5: seccion SIN ningun patron de TOTAL final -- ultima fila es un
     * dato real sin ninguna formula. filaFinDatos no debe cambiar.
     */
    public function test_section_without_any_trailing_total_pattern_is_unaffected(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION X7: EJEMPLO'],
            2 => ['A' => 'Concepto', 'B' => 'Total'],
            3 => ['A' => 'Item 1', 'B' => 5],
            4 => ['A' => 'Item 2', 'B' => 3],
        ]);

        $section = $this->section($this->service()->detect($ws, 'HOJATEST'), 'X7');

        $this->assertSame(4, $section->filaFinDatos, 'sin ningun patron de TOTAL final, filaFinDatos no debe cambiar');
    }

    /**
     * Regresion del bug real de A32/D1 (2026-08-10): una columna de
     * "control oculto" (formula de mensaje de validacion, MUY mas alla del
     * ancho real de la seccion, ej. columna Z cuando el encabezado solo
     * llega hasta B) que referencia una fila DENTRO del rango
     * [filaInicioDatos, fila) por motivos ajenos a cualquier agregacion
     * (armar texto de un mensaje de error, ej. "=IF(B4>0,\"...\"&B3,\"\")")
     * NO debe confundirse con evidencia de fila TOTAL. Antes del fix, esto
     * cortaba 53 filas de datos reales en A32/D1 (fin correcto 89, bug
     * daba 37) porque el analisis escaneaba hasta el ancho de TODA la
     * hoja en vez del ancho real de la seccion.
     */
    public function test_hidden_control_column_referencing_in_range_row_does_not_cause_false_exclusion(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION X9: EJEMPLO'],
            2 => ['A' => 'Concepto', 'B' => 'TOTAL'],
            3 => ['A' => 'Item 1', 'B' => '=SUM(C3:D3)', 'Z' => '=IF(B3>0,"mensaje sobre "&B2,"")'],
            4 => ['A' => 'Item 2', 'B' => '=SUM(C4:D4)', 'Z' => '=IF(B4>0,"mensaje sobre "&B3,"")'],
            5 => ['A' => 'TOTAL', 'B' => '=SUM(B3:B4)', 'Z' => '=IF(B5>0,"mensaje sobre "&B3,"")'],
            6 => ['A' => 'SECCION Y9: SIGUIENTE'],
        ]);

        $section = $this->section($this->service()->detect($ws, 'HOJATEST'), 'X9');

        $this->assertSame(4, $section->filaFinDatos, 'la columna de control Z (fuera del ancho real de la seccion, hasta B) no debe causar exclusion de la fila 4 (dato real) aunque su formula referencie la fila 3');
    }

    /**
     * Seccion ANCHA tipo A32/D1 real: encabezado hasta columna S (via
     * rango etario), 3 filas de dato real cada una con su propia columna
     * de control oculto MUY mas alla del ancho real (columna AT,
     * replicando el patron real "=CA{fila}&CB{fila}&..." con referencias a
     * la primera fila de datos para armar texto de mensajes), y la fila
     * TOTAL real al cierre. Solo el TOTAL debe excluirse.
     */
    public function test_wide_section_with_control_columns_on_every_row_excludes_only_the_real_total(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION X10: EJEMPLO'],
            2 => ['A' => 'Especialidad', 'B' => 'TOTAL', 'C' => 'RANGO ETARIO'],
            3 => ['C' => '0-4 años', 'D' => '5-9 años'],
            4 => ['A' => 'Pediatría', 'B' => '=SUM(C4:D4)', 'AT' => '=IF(B4>0,"* referencia "&C3,"")'],
            5 => ['A' => 'Medicina interna', 'B' => '=SUM(C5:D5)', 'AT' => '=IF(B5>0,"* referencia "&C4,"")'],
            6 => ['A' => 'Cardiología', 'B' => '=SUM(C6:D6)', 'AT' => '=IF(B6>0,"* referencia "&C4,"")'],
            7 => ['A' => 'TOTAL', 'B' => '=SUM(B4:B6)', 'C' => '=SUM(C4:C6)', 'D' => '=SUM(D4:D6)', 'AT' => '=IF(B7>0,"* referencia "&C4,"")'],
            8 => ['A' => 'SECCION Y10: SIGUIENTE'],
        ]);

        $section = $this->section($this->service()->detect($ws, 'HOJATEST'), 'X10');

        $this->assertSame(6, $section->filaFinDatos, 'solo la fila 7 (TOTAL real) debe excluirse -- las columnas de control en AT (mas alla del ancho real hasta D) no deben afectar a las filas 4-6');
    }

    /**
     * Caso adicional: multiples filas TOTAL apiladas al final (subtotal +
     * gran total) deben excluirse ambas -- el mecanismo es generico, no
     * limitado a exactamente 1 fila.
     */
    public function test_multiple_stacked_trailing_total_rows_are_all_excluded(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION X8: EJEMPLO'],
            2 => ['A' => 'Concepto', 'B' => 'TOTAL'],
            3 => ['A' => 'Item 1', 'B' => '=SUM(C3:D3)'],
            4 => ['A' => 'Item 2', 'B' => '=SUM(C4:D4)'],
            5 => ['A' => 'Subtotal', 'B' => '=SUM(B3:B4)'],
            6 => ['A' => 'TOTAL', 'B' => '=SUM(B3:B5)'],
            7 => ['A' => 'SECCION Y8: SIGUIENTE'],
        ]);

        $section = $this->section($this->service()->detect($ws, 'HOJATEST'), 'X8');

        $this->assertSame(4, $section->filaFinDatos, 'ambas filas TOTAL apiladas (5 y 6) deben excluirse');
    }

    /**
     * Hallazgo real de A32/F2 fila 151 (2026-08-10): la seccion agrupa
     * varias filas bajo un concepto de grupo puesto SOLO en la primera
     * fila (columna A) -- las filas siguientes, incluida la fila TOTAL
     * final, dejan A vacia y ponen su propio sub-concepto en columna B
     * ("TOTAL" vive en B, no en A). Antes del fix, isTrailingTotalRow()
     * exigia el concepto en columna A de forma fija y nunca detectaba
     * este TOTAL.
     */
    public function test_trailing_total_row_is_detected_when_concept_lives_in_a_different_column_than_a(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION X11: EJEMPLO'],
            2 => ['A' => 'Especialidad', 'B' => 'TOTAL', 'C' => 'RANGO ETARIO'],
            3 => ['C' => '0-4 años', 'D' => '5-9 años'],
            4 => ['A' => 'Grupo Medico', 'B' => 'Pediatría', 'C' => '=SUM(D4:E4)'],
            5 => ['B' => 'Medicina interna', 'C' => '=SUM(D5:E5)'],
            6 => ['B' => 'Cardiología', 'C' => '=SUM(D6:E6)'],
            7 => ['B' => 'TOTAL', 'C' => '=SUM(C4:C6)', 'D' => '=SUM(D4:D6)', 'E' => '=SUM(E4:E6)'],
            8 => ['A' => 'SECCION Y11: SIGUIENTE'],
        ]);

        $section = $this->section($this->service()->detect($ws, 'HOJATEST'), 'X11');

        $this->assertSame(6, $section->filaFinDatos, 'fila 7 (TOTAL con concepto en columna B, no A) debe excluirse');
    }

    /**
     * Regresion negativa: una fila de dato real con DOS columnas de texto
     * propio (concepto de grupo en A + sub-concepto en B, patron real de
     * A32/F2 fila 141 -- "Controles de salud mental por videollamadas" en
     * A, "Médico/a" en B) NUNCA debe confundirse con un TOTAL, aunque
     * alguna otra columna tenga una formula que referencie una fila
     * anterior. Dos columnas de texto propio (mas alla de la de concepto
     * identificada) indican un dato real, no una fila TOTAL de una sola
     * etiqueta.
     */
    public function test_real_data_row_with_two_own_text_columns_is_never_treated_as_total(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION X12: EJEMPLO'],
            2 => ['A' => 'Especialidad', 'B' => 'TOTAL'],
            3 => ['A' => 'Grupo Medico', 'B' => 'Pediatría', 'C' => '=SUM(D3:E3)'],
            4 => ['A' => 'Grupo Videollamada', 'B' => 'Médico/a', 'C' => '=SUM(D4:E4)'],
        ]);

        $section = $this->section($this->service()->detect($ws, 'HOJATEST'), 'X12');

        $this->assertSame(4, $section->filaFinDatos, 'fila 4 tiene dos columnas de texto propio (A y B) -- es un dato real, nunca un TOTAL, aunque tenga formulas');
    }
}
