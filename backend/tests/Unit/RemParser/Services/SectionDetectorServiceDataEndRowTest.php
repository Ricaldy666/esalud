<?php

namespace Tests\Unit\RemParser\Services;

use App\Domain\RemParser\Services\SectionDetectorService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

/**
 * Cubre la correccion de findDataEndRow()/findImplicitDataEndRow() --
 * hallazgo funcional de A11a/N (2026-08-07): la ULTIMA seccion de una hoja
 * se extendia hasta la fila mas alta de TODA la hoja (getHighestRow()),
 * arrastrando consigo un total general de hoja distante (ej. una formula
 * SUM que agrega columnas de todas las secciones) como si fuera parte de la
 * ultima seccion, en vez de detenerse en su verdadera ultima fila de datos.
 *
 * Confirmado contra el REM real que el mismo patron (gap de filas
 * completamente vacias entre el fin real de la seccion y un total general
 * distante) aparece en A01/H.2 (200 vs 258), A09/K (358 vs 385), A11/H (272
 * vs 311) y A11a/N (123 vs 150) -- los dos primeros ya estaban compensados
 * manualmente en la estructura activa, los dos ultimos no.
 *
 * Criterio implementado, sin hardcodear ninguna hoja/seccion/fila: una racha
 * de mas de 2 filas completamente vacias (ninguna columna poblada) senala
 * que lo que sigue ya no es parte de la seccion. Calibrado contra los gaps
 * internos legitimos reales (maximo 2 filas vacias consecutivas dentro de
 * una seccion, ej. A09/F.1) frente a los gaps que preceden a un total
 * general (26 a 57 filas vacias en los 4 casos reales auditados).
 */
class SectionDetectorServiceDataEndRowTest extends TestCase
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
     * Ultima seccion de la hoja, con un total general distante separado por
     * un gap grande (5 filas completamente vacias). La seccion debe
     * terminar en su ultima fila real de datos, no en la fila del total
     * general ni en getHighestRow().
     */
    public function test_last_section_with_distant_grand_total_stops_before_the_gap(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION Z: ULTIMA SECCION'],
            2 => ['A' => 'Concepto', 'B' => 'Total'],
            3 => ['A' => 'Actividad 1', 'B' => 5],
            4 => ['A' => 'Actividad 2', 'B' => 3],
            // filas 5-9 completamente vacias (gap de 5)
            10 => ['B' => '=SUM(B3:B4)'], // total general de hoja, sin relacion con la seccion
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $z = $this->section($sections, 'Z');

        $this->assertSame(4, $z->filaFinDatos, 'debe detenerse en la ultima fila real (4), no arrastrar el total general de la fila 10');
    }

    /**
     * Ultima seccion de la hoja, sin ningun total general posterior: los
     * datos llegan literalmente hasta la ultima fila con contenido de la
     * hoja. Debe seguir comportandose igual que antes de la correccion.
     */
    public function test_last_section_without_grand_total_extends_to_true_last_row(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION Z: ULTIMA SECCION'],
            2 => ['A' => 'Concepto', 'B' => 'Total'],
            3 => ['A' => 'Actividad 1', 'B' => 5],
            4 => ['A' => 'Actividad 2', 'B' => 3],
            5 => ['A' => 'Actividad 3', 'B' => 1],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $z = $this->section($sections, 'Z');

        $this->assertSame(5, $z->filaFinDatos);
    }

    /**
     * Seccion normal seguida de otra seccion: debe seguir detectando el
     * marcador "SECCION ..." como corte exacto, sin que la nueva logica de
     * gaps interfiera -- incluso si hubiera filas vacias antes del proximo
     * marcador.
     */
    public function test_normal_section_followed_by_another_section_is_unaffected(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION D: SECCION NORMAL'],
            2 => ['A' => 'Concepto', 'B' => 'Total'],
            3 => ['A' => 'Actividad D', 'B' => 1],
            // filas 4-5 completamente vacias (gap interno, dentro de lo tolerado)
            6 => ['A' => 'SECCION E: OTRA SECCION'],
            7 => ['A' => 'Concepto', 'B' => 'Total'],
            8 => ['A' => 'Actividad E', 'B' => 2],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $d = $this->section($sections, 'D');
        $e = $this->section($sections, 'E');

        $this->assertSame(5, $d->filaFinDatos, 'D debe terminar justo antes del marcador de E (fila 6), como antes');
        $this->assertSame(8, $e->filaFinDatos);
    }

    /**
     * Gaps internos pequenos (hasta 2 filas completamente vacias) dentro de
     * la ultima seccion de la hoja NO deben cortar la seccion antes de
     * tiempo -- patron real observado en A09/F.1 (gap de 2 filas) y A09/H
     * (gap de 1 fila), ambas secciones intermedias cuyo filaFinDatos ya
     * coincidia con la estructura activa antes de esta correccion.
     */
    public function test_small_internal_gap_within_last_section_does_not_cut_early(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION Z: ULTIMA SECCION'],
            2 => ['A' => 'Concepto', 'B' => 'Total'],
            3 => ['A' => 'Actividad 1', 'B' => 5],
            // fila 4-5 completamente vacias (gap de 2, tolerado)
            6 => ['A' => 'Actividad 2', 'B' => 3],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $z = $this->section($sections, 'Z');

        $this->assertSame(6, $z->filaFinDatos, 'un gap de solo 2 filas vacias no debe cortar la seccion; debe seguir hasta la fila 6');
    }

    /**
     * Un gap de exactamente 3 filas completamente vacias (uno mas que la
     * tolerancia) SI debe cortar la seccion -- confirma el limite exacto del
     * umbral (MAX_FILAS_VACIAS_TOLERADAS = 2).
     */
    public function test_gap_of_three_blank_rows_cuts_the_section(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION Z: ULTIMA SECCION'],
            2 => ['A' => 'Concepto', 'B' => 'Total'],
            3 => ['A' => 'Actividad 1', 'B' => 5],
            // filas 4-6 completamente vacias (gap de 3, supera la tolerancia)
            7 => ['B' => '=SUM(B3)'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $z = $this->section($sections, 'Z');

        $this->assertSame(3, $z->filaFinDatos);
    }
}
