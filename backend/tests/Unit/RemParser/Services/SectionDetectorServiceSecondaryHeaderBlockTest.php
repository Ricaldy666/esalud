<?php

namespace Tests\Unit\RemParser\Services;

use App\Domain\RemParser\Services\SectionDetectorService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

/**
 * Cubre findSecondaryHeaderBlocks() -- hallazgo real de A30/C (2026-08-10):
 * un segundo bloque de encabezado (filas 95-97) aparece 14 filas despues del
 * encabezado principal de la seccion, SIN ningun marcador SECCION que lo
 * anuncie, introduciendo columnas genuinamente NUEVAS (Z-AJ) mas alla del
 * ancho ya conocido por el encabezado principal (que solo llega hasta Y).
 * Antes de este fix, ColumnDetectorService solo leia etiquetas desde UNA
 * posicion fija de encabezado por seccion -- cualquier columna nueva
 * introducida mas adelante dentro del rango de datos ya aceptado quedaba
 * completamente invisible.
 *
 * Generico: detecta, dentro del rango [filaInicioDatos, filaFinDatos] ya
 * establecido de una seccion, una fila con texto propio en columna A que
 * ademas introduce texto plano mas alla del ancho ya conocido -- la trata
 * como un encabezado secundario y reutiliza findTrailingHeaderRows() (sin
 * modificar) para resolver sus propias filas adicionales y su propia fila
 * de inicio de datos.
 */
class SectionDetectorServiceSecondaryHeaderBlockTest extends TestCase
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
     * Caso base: bloque secundario de una sola fila, introduce 2 columnas
     * nuevas (D, E) mas alla del ancho del encabezado principal (A:C). Las
     * columnas reutilizadas (A:C) conservan su etiqueta del encabezado
     * principal; solo D y E, antes invisibles, quedan etiquetadas.
     */
    public function test_secondary_header_block_introduces_new_columns(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION M: EJEMPLO'],
            2 => ['A' => 'Concepto', 'B' => 'TOTAL', 'C' => 'Hombres'],
            3 => ['A' => 'Concepto 1', 'B' => '=SUM(F3:G3)', 'C' => 2],
            4 => ['A' => 'Bloque nuevo', 'D' => 'Modalidad', 'E' => 'Sistema'],
            5 => ['A' => 'Concepto 2', 'B' => '=SUM(F5:G5)', 'C' => 1, 'D' => 10, 'E' => 20],
            6 => ['A' => 'SECCION N: SIGUIENTE'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'M'));
        $fields = $this->fieldsByLetter($sections, 'M');

        $this->assertSame(3, $section->filaInicioDatos, 'el inicio de datos de la seccion no debe verse afectado por el bloque secundario');
        $this->assertSame(5, $section->filaFinDatos);

        $this->assertSame('Concepto', $fields['A']->label, 'columna reutilizada -- conserva la etiqueta del encabezado principal');
        $this->assertSame('TOTAL', $fields['B']->label, 'columna reutilizada -- conserva la etiqueta del encabezado principal');
        $this->assertSame('Hombres', $fields['C']->label, 'columna reutilizada -- conserva la etiqueta del encabezado principal');
        $this->assertSame('Modalidad', $fields['D']->label, 'columna nueva -- etiquetada desde el bloque secundario (fila 4)');
        $this->assertSame('Sistema', $fields['E']->label, 'columna nueva -- etiquetada desde el bloque secundario (fila 4)');
    }

    /**
     * Bloque secundario con su propio encabezado de 2 niveles (analogo a
     * A30/C filas 95-97: nivel superior en la fila del bloque, nivel
     * inferior en la fila siguiente con columna A vacia) -- reutiliza
     * findTrailingHeaderRows() sin modificar, combinando las etiquetas con
     * " / " igual que el encabezado principal.
     */
    public function test_secondary_header_block_with_two_level_trailing_row_combines_labels(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION N: EJEMPLO'],
            2 => ['A' => 'Concepto', 'B' => 'TOTAL'],
            3 => ['A' => 'Concepto 1', 'B' => '=SUM(D3:E3)'],
            4 => ['A' => 'Especialidad odonto', 'C' => 'Modalidad'],
            5 => ['C' => 'Institucional'],
            6 => ['A' => 'Concepto 2', 'B' => '=SUM(D6:E6)', 'C' => 7],
            7 => ['A' => 'SECCION O: SIGUIENTE'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'N'));
        $fields = $this->fieldsByLetter($sections, 'N');

        $this->assertSame(3, $section->filaInicioDatos, 'el inicio de datos de la seccion no debe verse afectado por el bloque secundario');
        $this->assertSame(6, $section->filaFinDatos);

        $this->assertSame('Concepto', $fields['A']->label);
        $this->assertSame('TOTAL', $fields['B']->label);
        $this->assertSame('Modalidad / Institucional', $fields['C']->label, 'columna nueva con encabezado propio de 2 niveles combinado');
    }

    /**
     * Fila TOTAL lider EMBEBIDA dentro de un bloque secundario (analogo
     * exacto a A30/C fila 98: formulas que agregan solo filas posteriores,
     * intercaladas entre el encabezado del bloque secundario y su primer
     * dato real) -- al reutilizar findTrailingHeaderRows() sin modificar,
     * la exclusion de TOTAL lider (isLeadingTotalRow(), ya implementada
     * para Hallazgo 1) se aplica automaticamente tambien aqui, sin codigo
     * adicional: la fila TOTAL no debe contarse como el primer dato del
     * bloque secundario.
     */
    public function test_secondary_header_block_excludes_embedded_leading_total_row(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION P: EJEMPLO'],
            2 => ['A' => 'Concepto', 'B' => 'TOTAL'],
            3 => ['A' => 'Concepto 1', 'B' => '=SUM(D3:E3)'],
            4 => ['A' => 'Bloque nuevo', 'C' => 'Dato nuevo'],
            5 => ['A' => 'TOTAL', 'C' => '=SUM(C6:C8)'],
            6 => ['A' => 'Concepto 2', 'B' => '=SUM(D6:E6)', 'C' => 4],
            7 => ['A' => 'SECCION Q: SIGUIENTE'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'P'));
        $fields = $this->fieldsByLetter($sections, 'P');

        $this->assertSame(3, $section->filaInicioDatos);
        $this->assertSame(6, $section->filaFinDatos);
        $this->assertSame('Dato nuevo', $fields['C']->label, 'la etiqueta de la columna nueva viene del encabezado del bloque (fila 4), no de la fila TOTAL (fila 5)');
    }

    /**
     * Regresion (falso positivo): una fila dentro del rango de datos con
     * columna A poblada pero que NO introduce ninguna columna mas alla del
     * ancho ya conocido no debe generar ningun bloque secundario -- las
     * etiquetas de todas las columnas deben quedar exactamente como las
     * dejo el encabezado principal, sin cambios.
     */
    public function test_no_secondary_block_when_no_new_columns_are_introduced(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION Q2: EJEMPLO'],
            2 => ['A' => 'Concepto', 'B' => 'TOTAL', 'C' => 'Hombres'],
            3 => ['A' => 'Concepto 1', 'B' => '=SUM(F3:G3)', 'C' => 2],
            4 => ['A' => 'Concepto 2', 'B' => '=SUM(F4:G4)', 'C' => 5],
            5 => ['A' => 'Concepto 3', 'B' => '=SUM(F5:G5)', 'C' => 1],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'Q2'));
        $fields = $this->fieldsByLetter($sections, 'Q2');

        $this->assertSame(3, $section->filaInicioDatos);
        $this->assertSame(5, $section->filaFinDatos);
        $this->assertCount(3, $fields, 'sin columnas nuevas, la cantidad de campos debe permanecer identica (A, B, C)');
        $this->assertSame('Concepto', $fields['A']->label);
        $this->assertSame('TOTAL', $fields['B']->label);
        $this->assertSame('Hombres', $fields['C']->label);
    }

    /**
     * Regresion (falso positivo, patron real A28/A.11): una seccion con
     * MUCHAS filas de datos reales, cada una con columna A poblada, cero
     * columnas nuevas introducidas nunca -- no debe generar ningun bloque
     * secundario aunque haya muchas filas candidatas con A poblada.
     */
    public function test_no_secondary_block_across_many_real_data_rows_with_populated_column_a(): void
    {
        $rows = [
            1 => ['A' => 'SECCION R2: EJEMPLO'],
            2 => ['A' => 'Diagnostico', 'B' => 'Comunas'],
        ];
        for ($i = 0; $i < 10; $i++) {
            $rows[3 + $i] = ['A' => "Concepto real $i", 'B' => "Comuna $i"];
        }

        $ws = $this->sheet($rows);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'R2'));
        $fields = $this->fieldsByLetter($sections, 'R2');

        $this->assertCount(2, $fields, 'sin columnas nuevas en ninguna fila, la cantidad de campos debe permanecer identica (A, B)');
    }
}
