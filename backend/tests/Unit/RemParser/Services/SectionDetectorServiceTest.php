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

    /**
     * Caso E (hallazgo real de A32, 2026-08-10): subseccion SIN punto
     * separador -- "SECCION D1", no "SECCION D.1" -- con D usada
     * unicamente como encabezado (sin fila propia entre su marcador y el
     * de D1). Antes del fix, str_starts_with($otro, $codigo . '.') nunca
     * reconocia "D1" como subseccion de "D" (no hay punto), dejando a D
     * como una seccion fantasma duplicada de D1 (mismo rango y campos).
     * Esperado: D se elimina, D1 se conserva -- igual que el patron ya
     * validado con punto (Caso B).
     */
    public function test_pure_header_only_parent_without_own_rows_is_discarded_when_child_has_no_dot_separator(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION D: ENCABEZADO PADRE'],
            2 => ['A' => 'SECCION D1: SUBSECCION SIN PUNTO'],
            3 => ['A' => 'Concepto', 'B' => 'Total'],
            4 => ['A' => 'Actividad D1', 'B' => 4],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $codigos = array_map(fn($s) => $s->codigo, $sections);

        $this->assertEqualsCanonicalizing(['D1'], $codigos, 'D debe eliminarse por ser un encabezado puro aunque su hijo "D1" no use punto separador');
    }

    /**
     * Caso F: mismo patron sin punto (D/D1), pero D SI tiene su propia
     * fila de datos antes del marcador de D1. Esperado: D se conserva
     * (nunca se descarta solo por compartir prefijo con una subseccion) --
     * simetrico al Caso A, que ya cubre esto para el patron con punto.
     */
    public function test_parent_with_own_data_is_kept_alongside_subsection_without_dot_separator(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION D: ENCABEZADO CON DATOS PROPIOS'],
            2 => ['A' => 'Concepto', 'B' => 'Total'],
            3 => ['A' => 'Actividad D propia', 'B' => 7],
            4 => ['A' => 'SECCION D1: SUBSECCION SIN PUNTO'],
            5 => ['A' => 'Concepto', 'B' => 'Total'],
            6 => ['A' => 'Actividad D1', 'B' => 4],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $codigos = array_map(fn($s) => $s->codigo, $sections);

        $this->assertEqualsCanonicalizing(['D', 'D1'], $codigos, 'D tiene datos propios -- no debe eliminarse aunque D1 comparta su prefijo');
    }

    /**
     * Caso G (regresion negativa explicita): un codigo de seccion
     * COMPLETAMENTE DISTINTO que solo comparte el prefijo por coincidencia
     * (ej. "AB" respecto de "A") NUNCA debe tratarse como subseccion,
     * aunque ambas secciones tengan el mismo filaHeader por casualidad --
     * requisito explicito: "no asumir que todo codigo que empieza igual es
     * subseccion". El caracter inmediatamente despues del prefijo debe ser
     * un punto o un digito; una letra (como "B" en "AB") nunca califica.
     */
    public function test_unrelated_section_sharing_letter_prefix_is_never_treated_as_subsection(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION A: PRIMERA SECCION'],
            2 => ['A' => 'SECCION AB: SECCION DISTINTA, NO SUBSECCION'],
            3 => ['A' => 'Concepto', 'B' => 'Total'],
            4 => ['A' => 'Actividad AB', 'B' => 3],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $codigos = array_map(fn($s) => $s->codigo, $sections);

        $this->assertEqualsCanonicalizing(['A', 'AB'], $codigos, '"AB" nunca debe tratarse como subseccion de "A" solo por compartir el prefijo -- ambas deben conservarse como secciones independientes');
    }
}
