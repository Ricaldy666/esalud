<?php

namespace Tests\Unit\RemParser\Services;

use App\Domain\RemParser\Services\SectionDetectorService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

/**
 * Cubre findTrailingHeaderRows()/ColumnDetectorService::detect() -- hallazgo
 * A30/A, A30/C, A30/D, A30/E (2026-08-21): cuando una fila TOTAL lider
 * (isLeadingTotalRow(), ver SectionDetectorServiceMultiLevelHeaderTest::
 * test_leading_total_row_referencing_only_rows_after_is_excluded_from_data)
 * queda pegada al bloque de encabezado, findTrailingHeaderRows() la agrega
 * al array de "filas de encabezado adicionales" -- correcto para calcular
 * filaInicioDatos, pero ese MISMO array se reutilizaba sin filtrar para
 * construir las etiquetas multinivel de columna. El texto propio de la fila
 * TOTAL lider en la columna de concepto (ej. "TOTAL", sin formula, a
 * diferencia de las demas columnas de esa fila que si son formula y se
 * descartan via cleanLabel()) se concatenaba al label de esa columna
 * ("Especialidades odontologicas / TOTAL") e isTotalColumn() la marcaba
 * esTotal=true -- una columna puramente descriptiva terminaba mal
 * clasificada como columna de total.
 *
 * Fix: findTrailingHeaderRows() ahora tambien reporta, via un parametro de
 * salida por referencia, cuales de las filas devueltas son especificamente
 * TOTAL lider. detect() sigue usando el array COMPLETO para calcular
 * filaInicioDatos/anchoConocido (sin cambios), pero excluye las filas TOTAL
 * lider del array que se pasa a ColumnDetectorService::detect() para
 * construir labels. Generico -- no depende de ninguna hoja/seccion/columna
 * especifica.
 */
class SectionDetectorServiceLeadingTotalRowLabelTest extends TestCase
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
     * Fixture base: encabezado de 2 niveles (filas 2-3), fila TOTAL lider
     * pegada inmediatamente despues (fila 4, columna A = "TOTAL", B y C
     * agregan EXCLUSIVAMENTE las filas de datos posteriores), datos reales
     * desde la fila 5. Patron identico al de A30/A, C, D, E.
     */
    private function leadingTotalFixture(): Worksheet
    {
        return $this->sheet([
            1 => ['A' => 'SECCION K2: EJEMPLO'],
            2 => ['A' => 'Especialidades', 'B' => 'TOTAL', 'C' => 'RANGO'],
            3 => ['B' => 'Hombres', 'C' => 'Mujeres'],
            4 => ['A' => 'TOTAL', 'B' => '=SUM(B5:B6)', 'C' => '=SUM(C5:C6)'],
            5 => ['A' => 'Pediatría', 'B' => '=+B5', 'C' => '=+C5'],
            6 => ['A' => 'Cirugía', 'B' => '=+B6', 'C' => '=+C6'],
        ]);
    }

    /**
     * 1. TOTAL lider pegado al encabezado no contamina el label de la
     *    columna descriptiva.
     */
    public function test_leading_total_row_does_not_contaminate_descriptive_column_label(): void
    {
        $sections = $this->service()->detect($this->leadingTotalFixture(), 'HOJATEST');
        $fields = $this->fieldsByLetter($sections, 'K2');

        $this->assertSame('Especialidades', $fields['A']->label, 'el texto "TOTAL" de la fila lider (fila 4) no debe concatenarse al label de la columna descriptiva');
    }

    /**
     * 2. esTotal queda false para la columna descriptiva.
     */
    public function test_leading_total_row_does_not_mark_descriptive_column_as_total(): void
    {
        $sections = $this->service()->detect($this->leadingTotalFixture(), 'HOJATEST');
        $fields = $this->fieldsByLetter($sections, 'K2');

        $this->assertFalse($fields['A']->esTotal, 'la columna de concepto no debe marcarse esTotal=true solo por el texto "TOTAL" de la fila lider');
    }

    /**
     * 3. Las columnas que SI son genuinamente TOTAL (por su encabezado
     *    real, filas 2-3, sin relacion con la fila lider) se siguen
     *    detectando correctamente -- el fix no debe volverse demasiado
     *    agresivo y perder columnas total reales.
     */
    public function test_genuinely_total_columns_are_still_detected_correctly(): void
    {
        $sections = $this->service()->detect($this->leadingTotalFixture(), 'HOJATEST');
        $fields = $this->fieldsByLetter($sections, 'K2');

        $this->assertSame('TOTAL / Hombres', $fields['B']->label);
        $this->assertTrue($fields['B']->esTotal, 'columna B es genuinamente TOTAL segun su propio encabezado (fila 2), debe seguir esTotal=true');
    }

    /**
     * 4. filaInicioDatos sigue excluyendo la fila TOTAL lider (sin cambios
     *    respecto al comportamiento ya probado en
     *    SectionDetectorServiceMultiLevelHeaderTest).
     */
    public function test_fila_inicio_datos_still_excludes_leading_total_row(): void
    {
        $sections = $this->service()->detect($this->leadingTotalFixture(), 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'K2'));

        $this->assertSame(5, $section->filaInicioDatos, 'la fila 4 (TOTAL lider) debe seguir excluida de los datos -- el primer dato real es la fila 5');
    }

    /**
     * 5. Encabezados multinivel NORMALES (sin ninguna fila TOTAL lider) no
     *    cambian -- regresion explicita minima, equivalente a los casos ya
     *    cubiertos en SectionDetectorServiceMultiLevelHeaderTest (que se
     *    corre completo como parte de la suite relacionada).
     */
    public function test_normal_multi_level_header_without_leading_total_is_unaffected(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION L2: EJEMPLO'],
            2 => ['A' => 'ÁREAS TEMÁTICAS', 'B' => 'PROFESIONAL', 'C' => 'TOTAL', 'D' => 'RANGO ETARIO'],
            3 => ['D' => '0-4 años'],
            4 => ['A' => 'Actividad física', 'B' => 'Médico/a', 'C' => '=SUM(D4:D4)'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'L2'));
        $fields = $this->fieldsByLetter($sections, 'L2');

        $this->assertSame(4, $section->filaInicioDatos);
        $this->assertSame('ÁREAS TEMÁTICAS', $fields['A']->label);
        $this->assertSame('RANGO ETARIO / 0-4 años', $fields['D']->label);
    }

    /**
     * 6. Fixture equivalente a A30/D: concepto (A) + 2 pares origen (B,J y
     *    C,K) + 2 columnas TOTAL (R,S, formula horizontal por fila) +
     *    encabezado de 2 niveles + fila TOTAL lider inmediatamente despues
     *    del encabezado. Confirma en conjunto: filaInicioDatos correcto,
     *    columna A sin contaminar, columnas TOTAL reales SI detectadas.
     */
    public function test_a30d_equivalent_fixture(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION D2: TELEINTERCONSULTA EJEMPLO'],
            2 => [
                'A' => 'Especialidades odontológicas',
                'B' => 'Ambulatoria nueva', 'J' => 'Ambulatoria control',
                'R' => 'TOTAL Hombres', 'S' => 'TOTAL Mujeres',
            ],
            3 => ['B' => 'Hombres', 'J' => 'Hombres', 'C' => 'Mujeres', 'K' => 'Mujeres'],
            4 => ['A' => 'TOTAL', 'R' => '=SUM(R5:R6)', 'S' => '=SUM(S5:S6)'],
            5 => ['A' => 'Cirugía y traumatología maxilofacial', 'R' => '=+B5+J5', 'S' => '=+C5+K5'],
            6 => ['A' => 'Endodoncia', 'R' => '=+B6+J6', 'S' => '=+C6+K6'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'D2'));
        $fields = $this->fieldsByLetter($sections, 'D2');

        $this->assertSame(5, $section->filaInicioDatos, 'la fila 4 (TOTAL lider) debe excluirse -- primer dato real es la fila 5');
        $this->assertSame('Especialidades odontológicas', $fields['A']->label, 'sin contaminacion "/ TOTAL"');
        $this->assertFalse($fields['A']->esTotal);
        $this->assertTrue($fields['R']->esTotal, 'R es genuinamente TOTAL segun su propio encabezado');
        $this->assertTrue($fields['S']->esTotal);
    }
}
