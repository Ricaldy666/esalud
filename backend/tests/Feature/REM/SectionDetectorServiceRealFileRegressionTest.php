<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Services\SectionDetectorService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Regresion de findDataEndRow()/findImplicitDataEndRow() contra el Excel REM
 * real -- hallazgo funcional de A11a/N (2026-08-07).
 *
 * Antes de la correccion, la ULTIMA seccion de cada hoja se extendia hasta
 * getHighestRow() (la fila mas alta de TODA la hoja), arrastrando un total
 * general de hoja distante como si fuera parte de la seccion. Auditado
 * contra el REM real (20260529190913_SA_26_V1.2-2.xlsm), el mismo patron
 * aparece en 4 hojas:
 *
 *   - A01/H.2: bug producia 258, correcto es 200 (ya compensado manualmente
 *     en la estructura activa id=44).
 *   - A09/K: bug producia 385, correcto es 358 (ya compensado manualmente).
 *   - A11/H: bug producia 311, correcto es 272 (nunca compensado --
 *     filaFinDatos quedaba null en la estructura activa).
 *   - A11a/N: bug producia 150, correcto es 123 (nunca compensado --
 *     filaFinDatos quedaba null en la estructura activa, causando que
 *     RemParserService::buildSectionMaps() descartara la seccion completa
 *     durante la carga real de REM).
 *
 * Este test corre el detector REAL (sin mocks) contra el archivo REM real y
 * confirma: (a) las 4 secciones afectadas ahora resuelven al valor correcto;
 * (b) TODAS las demas secciones de las 4 hojas permanecen exactamente
 * iguales a lo que ya esta guardado en la estructura activa -- ninguna
 * seccion no afectada cambia por efecto de esta correccion.
 *
 * ACTUALIZACION 2026-08-10: hallazgo de A31 (TOTAL final inmediato sin gap,
 * ver SectionDetectorService::excludeTrailingTotalRows()) descalzo 7 de los
 * valores de referencia de este fixture en 1 fila cada uno -- A01/D
 * (74->73), A01/H.2 (200->199), A09/B (39->38), A09/C (67->66), A09/F
 * (143->142), A09/F.1 (158->157), A09/F.2 (174->173). Confirmado
 * empiricamente contra el Excel real que la fila excluida en cada caso es
 * un TOTAL final genuino (concepto "TOTAL"/similar, formulas que agregan
 * exclusivamente filas anteriores de la misma seccion) -- las estructuras
 * activas de A01/A09 NO fueron retocadas, solo este fixture de test.
 */
class SectionDetectorServiceRealFileRegressionTest extends TestCase
{
    /**
     * El archivo real (27+ hojas) es pesado de parsear con formulas
     * preservadas -- se carga UNA sola vez para las 3 pruebas de esta clase
     * en vez de una vez por metodo, para evitar agotar el limite de memoria
     * del proceso de PHPUnit.
     */
    private static ?Spreadsheet $spreadsheet = null;

    private function spreadsheet(): Spreadsheet
    {
        if (self::$spreadsheet === null) {
            $path = storage_path('app/rem-uploads/2026/01/1/20260529190913_SA_26_V1.2-2.xlsm');
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(false);
            self::$spreadsheet = $reader->load($path);
        }

        return self::$spreadsheet;
    }

    /** @return array<string, array<string, int>> hoja => [codigo => filaFinDatos esperado] */
    private function expectedFilaFinDatos(): array
    {
        return [
            'A01' => [
                'A' => 32, 'B' => 39, 'C' => 66, 'D' => 73, 'E' => 83, 'F' => 93,
                'G.' => 112, 'G.1' => 131, 'G.2' => 150,
                'H.' => 167, 'H.1' => 184, 'H.2' => 199,
            ],
            'A09' => [
                'A' => 17, 'B' => 38, 'C' => 66, 'D' => 81, 'E' => 86,
                'F' => 142, 'F.1' => 157, 'F.2' => 173,
                'G' => 227, 'G.1' => 237, 'H' => 246, 'I' => 336, 'J' => 345, 'K' => 358,
            ],
            'A11' => [
                'A.1' => 30, 'A.2' => 52, 'A.3' => 74, 'A.4' => 96, 'A.5' => 118, 'A.6' => 140,
                'B.1' => 148, 'B.2' => 156, 'C.1' => 183, 'C.2' => 210,
                'D' => 221, 'E' => 232, 'F' => 259, 'G' => 265, 'H' => 272,
            ],
            'A11a' => [
                'A' => 19, 'B' => 23, 'C' => 32, 'D' => 36, 'E' => 47, 'F' => 53, 'G' => 59,
                'H' => 73, 'I' => 88, 'J' => 92, 'K' => 96, 'L' => 105, 'M' => 113, 'N' => 123,
            ],
        ];
    }

    /**
     * Las 4 secciones directamente implicadas por el hallazgo dejan de
     * arrastrar el total general de hoja y resuelven a su ultima fila real
     * de datos.
     */
    public function test_the_four_audited_last_of_sheet_sections_stop_at_their_real_last_data_row(): void
    {
        $spreadsheet = $this->spreadsheet();

        $svc = new SectionDetectorService();

        $cases = [
            ['A01', 'H.2', 199, 258],
            ['A09', 'K', 358, 385],
            ['A11', 'H', 272, 311],
            ['A11a', 'N', 123, 150],
        ];

        foreach ($cases as [$sheetName, $codigo, $correcto, $bugAnterior]) {
            $ws = $spreadsheet->getSheetByName($sheetName);
            $sections = $svc->detect($ws, $sheetName);
            $sec = current(array_filter($sections, fn ($s) => $s->codigo === $codigo));

            $this->assertNotNull($sec, "{$sheetName}/{$codigo} debe seguir detectandose");
            $this->assertSame(
                $correcto,
                $sec->filaFinDatos,
                "{$sheetName}/{$codigo}: filaFinDatos debe ser {$correcto}, no el total general de hoja ({$bugAnterior})"
            );
        }
    }

    /**
     * A11a/N especificamente: su CANTIDAD de campos (36) y filaHeader (115)
     * no cambian por el fix de filaFinDatos -- eso quedo confirmado y
     * aplicado localmente el 2026-08-07 (estructura activa id=45).
     *
     * ACTUALIZACION (mismo dia, hallazgo posterior de la auditoria de
     * A19a): al generalizar la deteccion de encabezados de 2-3 niveles
     * (SectionDetectorService::findTrailingHeaderRows()), se confirmo que
     * A11a/N tiene el MISMO patron de encabezado de 3 niveles que motivo
     * ese trabajo (categoria "RANGO ETARIO Y SEXO" + rango etario + sexo,
     * en filas 115+116+117) -- antes colapsaba a 32 columnas con la misma
     * etiqueta generica; ahora cada columna resuelve a una etiqueta unica
     * y filaInicioDatos pasa de 116 a 118 (las filas 116-117 son
     * encabezado, no datos). Este fix NO fue aplicado/persistido a A11a
     * (fuera del alcance autorizado del trabajo de A19a) -- este test
     * documenta el comportamiento del parser tal como esta HOY en el
     * codigo, no el contenido de la estructura activa 45 (que conserva las
     * etiquetas antiguas hasta que se autorice un parche separado de A11a).
     */
    public function test_a11a_n_fields_reflect_current_parser_including_the_later_multilevel_header_fix(): void
    {
        $svc = new SectionDetectorService();
        $ws = $this->spreadsheet()->getSheetByName('A11a');
        $sections = $svc->detect($ws, 'A11a');
        $n = current(array_filter($sections, fn ($s) => $s->codigo === 'N'));

        $this->assertCount(36, $n->fields);
        $this->assertSame(115, $n->filaHeader, 'filaHeader de N no cambia');
        $this->assertSame(118, $n->filaInicioDatos, 'filas 116-117 (encabezado de 3 niveles) ya no se cuentan como datos');

        $labels = array_map(fn ($f) => $f->label, $n->fields);
        $this->assertSame(count($labels), count(array_unique($labels)), 'ninguna columna debe compartir etiqueta con otra -- antes 32/36 columnas colapsaban a "RANGO ETARIO Y SEXO"');

        $byLetter = [];
        foreach ($n->fields as $f) {
            $byLetter[$f->letra] = $f->label;
        }
        $this->assertSame('CONDICIÓN', $byLetter['A']);
        $this->assertSame('TOTAL / Ambos sexos', $byLetter['B']);
        $this->assertSame('RANGO ETARIO Y SEXO / Menos de 1 año / Hombres', $byLetter['E']);
        $this->assertSame('RANGO ETARIO Y SEXO / 70 y más años / Mujeres', $byLetter['AJ']);
    }

    /**
     * Cobertura completa: TODAS las secciones de A01, A09, A11 y A11a (no
     * solo las 4 afectadas) deben resolver exactamente a los valores ya
     * confirmados -- ninguna seccion no afectada cambia por efecto de esta
     * correccion (item 6 del alcance solicitado: "A-K sin cambios respecto
     * de la estructura 44", "L y M sin cambios", etc).
     */
    public function test_all_sections_of_the_four_sheets_match_expected_fila_fin_datos(): void
    {
        $spreadsheet = $this->spreadsheet();

        $svc = new SectionDetectorService();

        foreach ($this->expectedFilaFinDatos() as $sheetName => $expectedByCode) {
            $ws = $spreadsheet->getSheetByName($sheetName);
            $sections = $svc->detect($ws, $sheetName);
            $byCode = [];
            foreach ($sections as $s) {
                $byCode[$s->codigo] = $s->filaFinDatos;
            }

            $this->assertEqualsCanonicalizing(
                array_keys($expectedByCode),
                array_keys($byCode),
                "{$sheetName}: el conjunto de codigos de seccion detectados no debe cambiar"
            );

            foreach ($expectedByCode as $codigo => $expectedEnd) {
                $this->assertSame(
                    $expectedEnd,
                    $byCode[$codigo],
                    "{$sheetName}/{$codigo}: filaFinDatos esperado {$expectedEnd}, obtenido " . ($byCode[$codigo] ?? 'null')
                );
            }
        }
    }
}
