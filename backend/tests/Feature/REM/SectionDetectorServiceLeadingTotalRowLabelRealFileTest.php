<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Services\SectionDetectorService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Regresion del fix de label/esTotal en fila TOTAL lider (hallazgo A30/A, C,
 * D, E -- 2026-08-21) contra el Excel real que activo la estructura 66/v34
 * (20260818151651_SA_26_V1.2-2.xlsm).
 *
 * Confirma con el archivo real (no un fixture sintetico):
 *  (a) las 4 secciones de A30 con fila TOTAL lider (A, C, D, E) ya NO
 *      contaminan el label/esTotal de su columna de concepto;
 *  (b) sus limites estructurales (filaHeader/filaInicioDatos/filaFinDatos)
 *      permanecen EXACTAMENTE iguales a los ya persistidos en la estructura
 *      activa 66 -- el fix es puramente de etiquetado, no debe mover ningun
 *      limite de fila;
 *  (c) A30/B, F, G (sin fila TOTAL lider) no se ven afectadas.
 */
class SectionDetectorServiceLeadingTotalRowLabelRealFileTest extends TestCase
{
    private static ?Spreadsheet $spreadsheet = null;

    private function spreadsheet(): Spreadsheet
    {
        if (self::$spreadsheet === null) {
            $path = storage_path('app/rem-uploads/2026/08/1/20260818151651_SA_26_V1.2-2.xlsm');
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(false);
            self::$spreadsheet = $reader->load($path);
        }

        return self::$spreadsheet;
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

    /** @return array<string, array{filaHeader:int, filaInicioDatos:int, filaFinDatos:?int}> */
    private function expectedBoundaries(): array
    {
        // Ya persistido hoy en la estructura activa 66/v34 -- capturado
        // antes de este fix, debe permanecer identico despues.
        return [
            'A' => ['filaHeader' => 9, 'filaInicioDatos' => 13, 'filaFinDatos' => 67],
            'C' => ['filaHeader' => 76, 'filaInicioDatos' => 81, 'filaFinDatos' => 93],
            'D' => ['filaHeader' => 95, 'filaInicioDatos' => 99, 'filaFinDatos' => 108],
            'E' => ['filaHeader' => 110, 'filaInicioDatos' => 115, 'filaFinDatos' => 118],
        ];
    }

    public function test_a30_sections_with_leading_total_row_no_longer_contaminate_concept_column(): void
    {
        $worksheet = $this->spreadsheet()->getSheetByName('A30');
        $service = new SectionDetectorService();
        $sections = $service->detect($worksheet, 'A30');

        foreach ($this->expectedBoundaries() as $codigo => $boundaries) {
            $section = current(array_filter($sections, fn ($s) => $s->codigo === $codigo));
            $this->assertNotFalse($section, "seccion A30/{$codigo} debe seguir detectandose");

            $this->assertSame($boundaries['filaHeader'], $section->filaHeader, "A30/{$codigo}: filaHeader no debe cambiar");
            $this->assertSame($boundaries['filaInicioDatos'], $section->filaInicioDatos, "A30/{$codigo}: filaInicioDatos no debe cambiar (la fila TOTAL lider debe seguir excluida)");
            $this->assertSame($boundaries['filaFinDatos'], $section->filaFinDatos, "A30/{$codigo}: filaFinDatos no debe cambiar");

            $fields = $this->fieldsByLetter($sections, $codigo);
            $conceptField = $fields['A'];

            $this->assertStringNotContainsString(
                'TOTAL',
                mb_strtoupper($conceptField->label),
                "A30/{$codigo}: la columna de concepto (A) ya no debe contener \"TOTAL\" en su label"
            );
            $this->assertFalse($conceptField->esTotal, "A30/{$codigo}: la columna de concepto (A) ya no debe marcarse esTotal=true");
        }
    }

    public function test_a30_sections_without_leading_total_row_are_unaffected(): void
    {
        $worksheet = $this->spreadsheet()->getSheetByName('A30');
        $service = new SectionDetectorService();
        $sections = $service->detect($worksheet, 'A30');

        foreach (['B', 'F', 'G'] as $codigo) {
            $section = current(array_filter($sections, fn ($s) => $s->codigo === $codigo));
            $this->assertNotFalse($section, "seccion A30/{$codigo} debe seguir detectandose");

            $fields = $this->fieldsByLetter($sections, $codigo);
            $this->assertArrayHasKey('A', $fields, "A30/{$codigo} debe seguir teniendo columna A");
        }
    }
}
