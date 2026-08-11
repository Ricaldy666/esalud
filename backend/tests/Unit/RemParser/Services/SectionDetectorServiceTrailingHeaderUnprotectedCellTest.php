<?php

namespace Tests\Unit\RemParser\Services;

use App\Domain\RemParser\Services\SectionDetectorService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ReflectionMethod;
use PHPUnit\Framework\TestCase;

/**
 * Cubre rowHasUnprotectedCellWithinColumns() / su wireado en
 * findTrailingHeaderRows() -- hallazgo A33/C (2026-08-11): filas 54-55
 * ("Modalidad remota"/"Modalidad presencial", concepto en columna B, sin
 * formula, densidad baja) se tragaban como encabezado adicional por el
 * mismo motivo que ya protege el mecanismo #3 (sin formula descalificante)
 * -- pero sus celdas de captura (C54/D54/C55/D55) estan genuinamente
 * DESBLOQUEADAS en el Excel real (datos reales, vacios solo porque este
 * archivo de referencia no tiene captura cargada), a diferencia de una fila
 * de encabezado real, cuyas celdas siempre estan bloqueadas.
 *
 * Ancho acotado deliberadamente a $anchoEncabezado (el mismo ya usado para
 * densidad/formula), NUNCA al ancho completo de campos de la seccion --
 * verificado empiricamente contra las 138 filas que el metodo clasifica hoy
 * como encabezado adicional en las 10 hojas cerradas (A19a-A32): la unica
 * con alguna celda unprotected es A32/K fila 186 (columna L, residuo de
 * formato sin relacion con captura real, confirmado contra 38 ocurrencias
 * historicas en rem_data con valor siempre null), y esa celda cae FUERA del
 * ancho de encabezado establecido en ese punto del escaneo.
 */
class SectionDetectorServiceTrailingHeaderUnprotectedCellTest extends TestCase
{
    private function service(): SectionDetectorService
    {
        return new SectionDetectorService();
    }

    private function sheet(array $rows, array $unprotectedCoords = []): Worksheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('HOJATEST');

        foreach ($rows as $rowNumber => $cols) {
            foreach ($cols as $colLetter => $value) {
                $sheet->setCellValue($colLetter . $rowNumber, $value);
            }
        }

        foreach ($unprotectedCoords as $coord) {
            $sheet->getStyle($coord)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
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

    private function trailingHeaderRows(Worksheet $ws, int $startRow, int $maxRow, string $highestCol): array
    {
        $method = new ReflectionMethod(SectionDetectorService::class, 'findTrailingHeaderRows');
        $method->setAccessible(true);

        return $method->invoke($this->service(), $ws, $startRow, $maxRow, $highestCol);
    }

    // ─── Positivos: reconstruccion generica de A33/C ────────────────────

    /**
     * Fixture estructuralmente identica a A33/C: encabezado de un solo
     * nivel (fila 2), dos filas de dato real con concepto en columna B
     * (fila 3 y 4, celdas C/D desbloqueadas y vacias), fila TOTAL final
     * (fila 5, concepto tambien en B, formulas hacia atras).
     */
    private function sheetLikeA33C(): Worksheet
    {
        return $this->sheet(
            [
                1 => ['A' => 'SECCION Z: EJEMPLO'],
                2 => ['A' => 'Talleres', 'B' => 'Modalidad', 'C' => 'Nº de sesiones', 'D' => 'Nº de participantes'],
                3 => ['B' => 'Modalidad remota'],
                4 => ['B' => 'Modalidad presencial'],
                5 => ['B' => 'Total', 'C' => '=SUM(C3:C4)', 'D' => '=SUM(D3:D4)'],
                6 => ['A' => 'SECCION Y: SIGUIENTE'],
            ],
            unprotectedCoords: ['C3', 'D3', 'C4', 'D4']
        );
    }

    public function test_row_immediately_after_header_with_unprotected_cell_is_not_a_trailing_header(): void
    {
        $ws = $this->sheetLikeA33C();

        $trailing = $this->trailingHeaderRows($ws, 3, 6, 'D');

        $this->assertNotContains(3, $trailing, 'fila 3 tiene celdas C/D desbloqueadas dentro del ancho de encabezado -- es dato real, no encabezado');
    }

    public function test_second_data_row_with_unprotected_cell_is_not_a_trailing_header(): void
    {
        $ws = $this->sheetLikeA33C();

        $trailing = $this->trailingHeaderRows($ws, 3, 6, 'D');

        $this->assertNotContains(4, $trailing, 'fila 4 tiene celdas C/D desbloqueadas dentro del ancho de encabezado -- es dato real, no encabezado');
    }

    public function test_fila_inicio_datos_lands_on_first_real_row(): void
    {
        $ws = $this->sheetLikeA33C();

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'Z'));

        $this->assertSame(3, $section->filaInicioDatos, 'la fila 3 ya no debe absorberse como encabezado adicional');
    }

    public function test_fila_fin_datos_excludes_the_trailing_total_row(): void
    {
        $ws = $this->sheetLikeA33C();

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'Z'));

        $this->assertSame(4, $section->filaFinDatos, 'la fila 5 (TOTAL, concepto en columna B) debe excluirse via el mecanismo ya existente de TOTAL final');
    }

    // ─── Negativos: A33/A y A33/B (encabezados multinivel reales) ───────

    /**
     * Reconstruccion generica del encabezado de 3 niveles de A33/A (fila
     * TOTAL/RANGO ETARIO fusionado, fila de tramos etarios, fila
     * Hombres/Mujeres) -- ninguna celda desprotegida en ninguna de las 3
     * filas de encabezado, deben seguir aceptandose como encabezado
     * adicional.
     */
    public function test_a33a_style_three_level_header_rows_still_classified_as_header(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION Z3: EJEMPLO'],
            2 => ['A' => 'Ingreso', 'D' => 'TOTAL', 'G' => 'RANGO ETARIO'],
            3 => ['G' => '0 a 4 años', 'I' => '5 a 9 años'],
            4 => ['D' => 'Ambos Sexos', 'E' => 'Hombres', 'F' => 'Mujeres', 'G' => 'Hombres', 'H' => 'Mujeres', 'I' => 'Hombres', 'J' => 'Mujeres'],
            5 => ['A' => 'Concepto 1', 'D' => '=SUM(E5:F5)', 'E' => '=+G5+I5', 'F' => '=+H5+J5'],
            6 => ['A' => 'SECCION Y3: SIGUIENTE'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'Z3'));

        $this->assertSame(5, $section->filaInicioDatos, 'las 3 filas de encabezado (2,3,4) deben seguir aceptandose -- ninguna tiene celdas desprotegidas');
    }

    /**
     * Reconstruccion generica del encabezado de 3 niveles de A33/B
     * (TOTAL/RANGO ETARIO Y SEXO/ATENCIONES POR PROFESIONAL fusionado en la
     * fila superior, tramos etarios, Hombres/Mujeres/profesionales).
     */
    public function test_a33b_style_three_level_header_rows_still_classified_as_header(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION Z4: EJEMPLO'],
            2 => ['A' => 'TIPO', 'B' => 'ACTIVIDADES', 'C' => 'TOTAL', 'F' => 'RANGO ETARIO Y SEXO'],
            3 => ['F' => '0 a 4 años', 'H' => '5 a 9 años'],
            4 => ['C' => 'Ambos Sexos', 'D' => 'Hombres', 'E' => 'Mujeres', 'F' => 'Hombres', 'G' => 'Mujeres', 'H' => 'Hombres', 'I' => 'Mujeres'],
            5 => ['A' => 'Grupo 1', 'B' => 'Actividad 1', 'C' => '=SUM(D5:E5)', 'D' => '=+F5+H5', 'E' => '=+G5+I5'],
            6 => ['A' => 'SECCION Y4: SIGUIENTE'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'Z4'));

        $this->assertSame(5, $section->filaInicioDatos, 'las 3 filas de encabezado (2,3,4) deben seguir aceptandose -- ninguna tiene celdas desprotegidas');
    }

    // ─── Negativos: A32/K (unprotected FUERA del ancho establecido) ─────

    /**
     * Reconstruccion generica de A32/K fila 186: encabezado denso (fila 3,
     * 2 de 3 columnas del ancho establecido pobladas) con una celda
     * desprotegida en una columna MUY MAS ALLA del ancho de encabezado
     * (columna H, indice 8, cuando anchoEncabezado=3) -- residuo de formato
     * sin relacion con la captura real de la seccion. Debe seguir
     * aceptandose como encabezado.
     */
    public function test_unprotected_cell_outside_established_width_does_not_disqualify_header_row(): void
    {
        $ws = $this->sheet(
            [
                1 => ['A' => 'SECCION Z5: EJEMPLO'],
                2 => ['A' => 'ACTIVIDAD', 'B' => 'TOTAL', 'C' => 'TIPO'],
                3 => ['C' => 'Telefónico', 'D' => 'Videollamadas', 'H' => '48-59 meses'],
                4 => ['A' => 'Concepto 1', 'B' => '=SUM(C4:D4)'],
                5 => ['A' => 'SECCION Y5: SIGUIENTE'],
            ],
            unprotectedCoords: ['H3']
        );

        $trailing = $this->trailingHeaderRows($ws, 3, 5, 'H');

        $this->assertContains(3, $trailing, 'la celda H3 esta fuera de anchoEncabezado (3, establecido desde la fila 2) -- no debe descalificar la fila 3 como encabezado');
    }

    /**
     * Caso adicional de borde: la celda desprotegida cae exactamente en
     * anchoEncabezado + 1 (el primer indice fuera de rango) -- confirma que
     * el limite es estrictamente 1..anchoEncabezado, no un margen mas
     * amplio.
     */
    public function test_unprotected_cell_exactly_one_column_beyond_established_width_does_not_disqualify(): void
    {
        $ws = $this->sheet(
            [
                1 => ['A' => 'SECCION Z6: EJEMPLO'],
                2 => ['A' => 'CATEGORIA', 'B' => 'TOTAL', 'C' => 'RANGO'],
                3 => ['B' => 'Ambos Sexos', 'C' => 'Hombres', 'D' => 'nota interna'],
                4 => ['A' => 'Concepto 1', 'B' => '=SUM(C4:D4)'],
                5 => ['A' => 'SECCION Y6: SIGUIENTE'],
            ],
            unprotectedCoords: ['D3']
        );

        $trailing = $this->trailingHeaderRows($ws, 3, 5, 'D');

        $this->assertContains(3, $trailing, 'anchoEncabezado=3 (desde la fila 2, hasta columna C) -- D3 (columna 4) queda fuera del rango revisado, la fila 3 sigue siendo encabezado');
    }
}
