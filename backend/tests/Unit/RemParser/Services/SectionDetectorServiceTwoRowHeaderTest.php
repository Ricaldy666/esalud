<?php

namespace Tests\Unit\RemParser\Services;

use App\Domain\RemParser\Services\SectionDetectorService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

/**
 * Cubre la correccion de encabezados con columna A vacia (findHeaderRow() +
 * ColumnDetectorService::detect()) -- hallazgo de la auditoria de A11a
 * (2026-08-06): las secciones A, B, C, D, E, F, G, H, I, J, K tienen su
 * encabezado real (ej. "TOTAL" / "RANGO ETARIO") en filas donde la columna
 * A esta vacia, lo que hacia que el criterio historico (primera fila con
 * texto "normal" en A) saltara el encabezado completo y aterrizara en un
 * subtitulo de grupo (A, H, I -- resultando en 1 solo campo detectado) o en
 * la primera fila de datos reales (B-G, J, K -- resultando en columnas de
 * formulas auxiliares de validacion, como CA/CB/CG/CH, tomadas como si
 * fueran campos de captura).
 */
class SectionDetectorServiceTwoRowHeaderTest extends TestCase
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
     * Caso de control: encabezado normal, con texto en columna A (patron de
     * A01/A09/A11, ej. "TIPO DE CONTROL" o "CONCEPTO"). Debe comportarse
     * exactamente igual que antes de esta correccion.
     */
    public function test_normal_header_with_text_in_column_a_is_unaffected(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION A: CONTROLES'],
            2 => ['A' => 'Concepto', 'B' => 'Total', 'C' => 'Hombres', 'D' => 'Mujeres'],
            3 => ['A' => 'Actividad 1', 'B' => 5, 'C' => 2, 'D' => 3],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'A'));

        $this->assertSame(2, $section->filaHeader);
        $this->assertSame(3, $section->filaInicioDatos);

        $fields = $this->fieldsByLetter($sections, 'A');
        $this->assertSame('Concepto', $fields['A']->label);
        $this->assertSame('Total', $fields['B']->label);
        $this->assertSame('Hombres', $fields['C']->label);
        $this->assertSame('Mujeres', $fields['D']->label);
    }

    /**
     * Encabezado de una sola fila, pero con columna A vacia (patron real de
     * A11a/B: B="TOTAL", C="Pueblos Originarios", D="Migrantes", nada en
     * A). La fila de datos empieza inmediatamente despues.
     */
    public function test_single_row_header_with_empty_column_a_is_detected(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION B: NIÑOS EXPUESTOS'],
            2 => ['B' => 'TOTAL', 'C' => 'Pueblos Originarios', 'D' => 'Migrantes'],
            3 => ['A' => 'Recién nacidos expuestos a sífilis', 'E' => '=CA3&CB3'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'B'));

        $this->assertSame(2, $section->filaHeader, 'debe usar la fila 2 (TOTAL/Pueblos Originarios/Migrantes), no la 3');
        $this->assertSame(3, $section->filaInicioDatos);

        $fields = $this->fieldsByLetter($sections, 'B');
        $this->assertSame('TOTAL', $fields['B']->label);
        $this->assertSame('Pueblos Originarios', $fields['C']->label);
        $this->assertSame('Migrantes', $fields['D']->label);
    }

    /**
     * Encabezado de DOS filas fusionadas (patron real de A11a/A: fila
     * superior con categoria general "TOTAL"/"RANGO ETARIO", fila inferior
     * con la etiqueta especifica por columna, ej. "10 - 14 años"). La
     * etiqueta final por columna debe combinar ambas: la especifica cuando
     * exista, la de categoria general como respaldo (ej. TOTAL, que solo
     * vive en la fila superior).
     */
    public function test_two_row_header_combines_category_and_specific_labels(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION A: TRATAMIENTO'],
            2 => ['B' => 'TOTAL', 'C' => 'RANGO ETARIO'],
            3 => ['C' => '10 - 14 años', 'D' => '15 - 19 años', 'E' => '20 - 24 años'],
            4 => ['A' => 'TRATAMIENTO EN ATENCION PRIMARIA'],
            5 => ['A' => 'Gestantes con penicilina en Atención Primaria', 'B' => '=SUM(C5:E5)'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'A'));

        $this->assertSame(3, $section->filaHeader, 'debe usar la fila inferior (etiquetas especificas) como encabezado principal');
        $this->assertSame(4, $section->filaInicioDatos);

        $fields = $this->fieldsByLetter($sections, 'A');
        $this->assertSame('TOTAL', $fields['B']->label, 'columna B solo tiene etiqueta en la fila superior -- debe usarse como respaldo');
        $this->assertSame('10 - 14 años', $fields['C']->label, 'columna C tiene etiqueta especifica en la fila inferior -- debe preferirse sobre la generica');
        $this->assertSame('15 - 19 años', $fields['D']->label);
        $this->assertSame('20 - 24 años', $fields['E']->label);
    }

    /**
     * El subtitulo de grupo que aparece despues del encabezado de dos filas
     * (ej. "TRATAMIENTO EN ATENCION PRIMARIA", sin ninguna otra columna
     * poblada) nunca debe usarse como fila de encabezado -- caso real que
     * producia el bug de A, H, I (0 patrones).
     */
    public function test_subtitle_row_after_header_is_never_used_as_header(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION H: GESTANTES ESTUDIADAS'],
            2 => ['B' => 'TOTAL', 'C' => 'RANGO ETARIO'],
            3 => ['C' => '10 - 14 años', 'D' => '15 - 19 años'],
            4 => ['A' => 'GESTANTES ESTUDIADAS PARA EGB EN APS'], // subtitulo puro, sin otras columnas
            5 => ['A' => 'Gestantes estudiadas para EGB en APS', 'B' => '=SUM(C5:D5)'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'H'));

        $this->assertSame(3, $section->filaHeader);
        $this->assertNotSame(4, $section->filaHeader, 'el subtitulo de la fila 4 nunca debe convertirse en encabezado');

        $fields = $this->fieldsByLetter($sections, 'H');
        $this->assertCount(3, $fields, 'debe detectar B, C y D -- no colapsar a 1 solo campo');
        $this->assertArrayHasKey('B', $fields);
        $this->assertArrayHasKey('C', $fields);
        $this->assertArrayHasKey('D', $fields);
    }

    /**
     * La primera fila de datos reales (con una oracion larga en A y una
     * formula auxiliar en otra columna) tampoco debe usarse como
     * encabezado -- caso real que producia el bug de B-G, J, K (columnas de
     * formulas de validacion tomadas como campos).
     */
    public function test_first_data_row_is_never_used_as_header(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION B: NIÑOS EXPUESTOS'],
            2 => ['B' => 'TOTAL', 'C' => 'Pueblos Originarios', 'D' => 'Migrantes'],
            3 => [
                'A' => 'Recién nacidos/as expuestos a sífilis que reciben tratamiento para sífilis congénita al nacer',
                'E' => '=CA3&CB3',
            ],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'B'));

        $this->assertSame(2, $section->filaHeader);
        $this->assertNotSame(3, $section->filaHeader, 'la primera fila de datos nunca debe convertirse en encabezado');
    }

    /**
     * Las formulas auxiliares de validacion (ej. mensajes de advertencia
     * IF(...), banderas 0/1, concatenaciones como "=CA22&CB22") viven SOLO
     * en las filas de datos, nunca en el encabezado real -- al usar la fila
     * de encabezado correcta, esas columnas quedan vacias ahi y por lo
     * tanto NUNCA se detectan como campos.
     */
    public function test_auxiliary_validation_formula_columns_are_never_detected_as_fields(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION B: NIÑOS EXPUESTOS'],
            2 => ['B' => 'TOTAL', 'C' => 'Pueblos Originarios', 'D' => 'Migrantes'],
            3 => [
                'A' => 'Recién nacidos/as expuestos a sífilis',
                'E' => '=CA3&CB3',
                'CA' => '=IF(AND(B3>0,C3=""),"* No olvide digitar Pueblos Originarios","")',
                'CB' => '=IF(AND(B3>0,D3=""),"* No olvide digitar Migrantes","")',
                'CG' => '=IF(AND(B3>0,C3=""),1,0)',
                'CH' => '=IF(AND(B3>0,D3=""),1,0)',
            ],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $fields = $this->fieldsByLetter($sections, 'B');

        foreach (['E', 'CA', 'CB', 'CG', 'CH'] as $letra) {
            $this->assertArrayNotHasKey($letra, $fields, "la columna {$letra} es una formula auxiliar de validacion, no debe detectarse como campo");
        }
        $this->assertArrayHasKey('B', $fields);
        $this->assertArrayHasKey('C', $fields);
        $this->assertArrayHasKey('D', $fields);
    }

    /**
     * Patron real de A11a/L, M, N: la fila de encabezado SI tiene texto en
     * columna A ("CONDICIÓN"), por lo que el criterio historico ya la
     * encuentra correctamente en su primer intento -- debe seguir sin
     * cambios (sin fila superior de respaldo), aunque el encabezado tambien
     * tenga una segunda fila con sub-etiquetas que hoy no se combinan (fuera
     * de alcance de esta correccion, ver hallazgo documentado).
     */
    public function test_condicion_style_header_with_text_in_column_a_is_unaffected(): void
    {
        $ws = $this->sheet([
            1 => ['A' => 'SECCION L: SCREENING'],
            2 => ['A' => 'CONDICIÓN', 'B' => 'TOTAL', 'C' => 'GRUPO DE EDAD'],
            3 => ['C' => '10 - 14 años', 'D' => '15 - 19 años'],
            4 => ['A' => 'Gestantes con examen solicitado', 'B' => '=SUM(C4:D4)'],
        ]);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $section = current(array_filter($sections, fn ($s) => $s->codigo === 'L'));

        $this->assertSame(2, $section->filaHeader, 'debe mantenerse igual al criterio historico: A tiene texto ("CONDICIÓN") desde la primera fila');

        $fields = $this->fieldsByLetter($sections, 'L');
        $this->assertSame('CONDICIÓN', $fields['A']->label);
        $this->assertSame('TOTAL', $fields['B']->label);
        $this->assertSame('GRUPO DE EDAD', $fields['C']->label);
    }
}
