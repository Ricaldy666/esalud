<?php

namespace Tests\Unit\RemParser\Services;

use App\Domain\RemParser\Services\SectionDetectorService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

/**
 * Cubre filterAggregators(): una seccion "padre" (ej. F) solo debe
 * descartarse cuando es un encabezado puro sin filas propias antes de su
 * primera subseccion (ej. F.1) -- nunca solo por existir F.1/F.2. Bug real
 * detectado en A09: Seccion F y G tenian filas propias y el criterio
 * anterior (basado solo en el prefijo del codigo) las eliminaba igual.
 */
class SectionDetectorServiceTest extends TestCase
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

    /**
     * Caso A: F existe, F.1 y F.2 existen, F tiene filas de datos propias.
     * Esperado: F, F.1 y F.2 conservadas.
     */
    public function test_parent_with_own_data_rows_is_kept_alongside_its_subsections(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION F: ACTIVIDADES DE ATENCION'],
            2 => ['A' => 'Concepto', 'B' => 'Total'],
            3 => ['A' => 'Actividad 1', 'B' => 5],
            4 => ['A' => 'Actividad 2', 'B' => 3],
            5 => ['A' => 'SECCION F.1: SUBSECCION QUIRURGICA'],
            6 => ['A' => 'Concepto', 'B' => 'Total'],
            7 => ['A' => 'Actividad F1', 'B' => 2],
            8 => ['A' => 'SECCION F.2: SUBSECCION IMAGENOLOGIA'],
            9 => ['A' => 'Concepto', 'B' => 'Total'],
            10 => ['A' => 'Actividad F2', 'B' => 9],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $codigos = array_map(fn($s) => $s->codigo, $sections);

        $this->assertEqualsCanonicalizing(['F', 'F.1', 'F.2'], $codigos);

        $f = current(array_filter($sections, fn($s) => $s->codigo === 'F'));
        $this->assertNotEmpty($f->fields, 'F debe tener sus propios campos detectados');
    }

    /**
     * Caso B: seccion padre usada unicamente como encabezado (sin fila propia
     * entre su marcador y el de la primera subseccion), con subsecciones que
     * si tienen datos.
     * Esperado: se elimina solo el encabezado padre; las subsecciones quedan.
     */
    public function test_pure_header_only_parent_without_own_rows_is_discarded(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION P: ENCABEZADO PADRE'],
            2 => ['A' => 'SECCION P.1: SUBSECCION UNO'],
            3 => ['A' => 'Concepto', 'B' => 'Total'],
            4 => ['A' => 'Actividad P1', 'B' => 4],
            5 => ['A' => 'SECCION P.2: SUBSECCION DOS'],
            6 => ['A' => 'Concepto', 'B' => 'Total'],
            7 => ['A' => 'Actividad P2', 'B' => 6],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $codigos = array_map(fn($s) => $s->codigo, $sections);

        $this->assertEqualsCanonicalizing(['P.1', 'P.2'], $codigos, 'P debe eliminarse por ser un encabezado puro; P.1 y P.2 deben conservarse');
    }

    /**
     * Caso C: G existe, G.1 existe, G tiene datos propios.
     * Esperado: G y G.1 conservadas.
     */
    public function test_parent_g_with_own_data_is_kept_alongside_g1(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION G: PROGRAMA'],
            2 => ['A' => 'Concepto', 'B' => 'Total'],
            3 => ['A' => 'Actividad G', 'B' => 8],
            4 => ['A' => 'SECCION G.1: SUBPROGRAMA'],
            5 => ['A' => 'Concepto', 'B' => 'Total'],
            6 => ['A' => 'Actividad G1', 'B' => 2],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $codigos = array_map(fn($s) => $s->codigo, $sections);

        $this->assertEqualsCanonicalizing(['G', 'G.1'], $codigos);
    }

    /**
     * Caso D: secciones normales sin subsecciones.
     * Esperado: comportamiento sin cambios, ambas se conservan.
     */
    public function test_normal_sections_without_subsections_are_unaffected(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION D: SECCION NORMAL'],
            2 => ['A' => 'Concepto', 'B' => 'Total'],
            3 => ['A' => 'Actividad D', 'B' => 1],
            4 => ['A' => 'SECCION E: OTRA SECCION'],
            5 => ['A' => 'Concepto', 'B' => 'Total'],
            6 => ['A' => 'Actividad E', 'B' => 2],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $codigos = array_map(fn($s) => $s->codigo, $sections);

        $this->assertEqualsCanonicalizing(['D', 'E'], $codigos);
    }
}
