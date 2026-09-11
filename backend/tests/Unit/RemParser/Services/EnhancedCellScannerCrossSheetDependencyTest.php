<?php

namespace Tests\Unit\RemParser\Services;

use App\Domain\RemParser\Services\EnhancedCellScanner;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

/**
 * REM BM -- FASE BM-2.6A (2026-09-11): soporte estructural generico de
 * dependencias cross-sheet.
 *
 * Hallazgo real de BM-2.5: antes de esta fase,
 * EnhancedCellScanner::extractDependencies() leia el nombre de una hoja
 * destino como si fuera una coordenada local -- para "=BM18A!D20" producia
 * ["BM18","D20"] (columna B+M fila 18, inexistente, en vez de reconocer
 * "BM18A" como el nombre de la hoja). Este archivo cubre el parser
 * corregido: dependencias same-sheet (legacy, sin cambios de shape/
 * comportamiento) y dependencias cross-sheet (representacion estructurada
 * nueva, aditiva). No prueba nada especifico de BM -- las hojas de prueba
 * se llaman HOJATEST/HOJADESTINO, genericas.
 */
class EnhancedCellScannerCrossSheetDependencyTest extends TestCase
{
    private function scanner(): EnhancedCellScanner
    {
        return new EnhancedCellScanner();
    }

    private function sheet(array $rows, bool $protect = true, string $title = 'HOJATEST'): Worksheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($title);

        foreach ($rows as $rowNumber => $cols) {
            foreach ($cols as $colLetter => $value) {
                $sheet->setCellValue($colLetter . $rowNumber, $value);
            }
        }

        if ($protect) {
            $sheet->getProtection()->setSheet(true);
        }

        return $sheet;
    }

    private function scanSingleFormulaCell(string $formula, string $targetCoord = 'B3'): \App\Domain\RemParser\DTOs\EnhancedCellDTO
    {
        $ws = $this->sheet([
            2 => ['A' => 'Concepto', 'B' => 'TOTAL'],
            3 => ['A' => 'Item 1', $this->colOf($targetCoord) => $formula],
        ]);

        $sectionData = [
            'filaHeader' => 2,
            'filaInicioDatos' => 3,
            'filaFinDatos' => 3,
            'fields' => [
                ['letra' => 'A', 'label' => 'Concepto'],
                ['letra' => 'B', 'label' => 'TOTAL'],
            ],
        ];

        $cells = $this->scanner()->scan($ws, $sectionData);

        return $cells[$targetCoord];
    }

    private function colOf(string $coord): string
    {
        preg_match('/^([A-Z]+)\d+$/', $coord, $m);
        return $m[1];
    }

    // ── SAME-SHEET (legacy) -- comportamiento intacto ──────────────────

    public function test_same_sheet_single_reference_legacy_behavior_intact(): void
    {
        $cell = $this->scanSingleFormulaCell('=A1');

        $this->assertTrue($cell->esFormula);
        $this->assertSame(['A1'], $cell->dependencias);
        $this->assertSame([], $cell->dependenciasCrossHoja);
    }

    public function test_same_sheet_range_legacy_behavior_intact(): void
    {
        $cell = $this->scanSingleFormulaCell('=SUM(A1:A10)');

        $expected = ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9', 'A10'];
        $this->assertSame($expected, $cell->dependencias);
        $this->assertSame([], $cell->dependenciasCrossHoja);
    }

    // ── CROSS-SHEET ──────────────────────────────────────────────────

    public function test_cross_sheet_simple_reference(): void
    {
        $cell = $this->scanSingleFormulaCell('=HOJADESTINO!D20');

        $this->assertTrue($cell->esFormula);
        $this->assertSame([], $cell->dependencias, 'no debe quedar ninguna dependencia same-sheet fantasma');
        $this->assertSame([
            ['hoja' => 'HOJADESTINO', 'tipo' => 'celda', 'celda' => 'D20', 'celda_inicio' => null, 'celda_fin' => null],
        ], $cell->dependenciasCrossHoja);
    }

    public function test_cross_sheet_simple_reference_absolute(): void
    {
        $cell = $this->scanSingleFormulaCell('=HOJADESTINO!$D$20');

        $this->assertSame([], $cell->dependencias);
        $this->assertSame([
            ['hoja' => 'HOJADESTINO', 'tipo' => 'celda', 'celda' => 'D20', 'celda_inicio' => null, 'celda_fin' => null],
        ], $cell->dependenciasCrossHoja);
    }

    public function test_cross_sheet_range(): void
    {
        $cell = $this->scanSingleFormulaCell('=SUM(HOJADESTINO!D92:D113)');

        $this->assertSame([], $cell->dependencias);
        $this->assertSame([
            ['hoja' => 'HOJADESTINO', 'tipo' => 'rango', 'celda' => null, 'celda_inicio' => 'D92', 'celda_fin' => 'D113'],
        ], $cell->dependenciasCrossHoja);
    }

    public function test_cross_sheet_range_absolute(): void
    {
        $cell = $this->scanSingleFormulaCell('=SUM(HOJADESTINO!$D$92:$D$113)');

        $this->assertSame([], $cell->dependencias);
        $this->assertSame([
            ['hoja' => 'HOJADESTINO', 'tipo' => 'rango', 'celda' => null, 'celda_inicio' => 'D92', 'celda_fin' => 'D113'],
        ], $cell->dependenciasCrossHoja);
    }

    public function test_cross_sheet_quoted_sheet_name_with_space(): void
    {
        $cell = $this->scanSingleFormulaCell("='Hoja X'!D20");

        $this->assertSame([], $cell->dependencias);
        $this->assertSame([
            ['hoja' => 'HOJA X', 'tipo' => 'celda', 'celda' => 'D20', 'celda_inicio' => null, 'celda_fin' => null],
        ], $cell->dependenciasCrossHoja);
    }

    public function test_cross_sheet_quoted_sheet_name_with_space_and_range(): void
    {
        $cell = $this->scanSingleFormulaCell("=SUM('Hoja X'!D20:D30)");

        $this->assertSame([], $cell->dependencias);
        $this->assertSame([
            ['hoja' => 'HOJA X', 'tipo' => 'rango', 'celda' => null, 'celda_inicio' => 'D20', 'celda_fin' => 'D30'],
        ], $cell->dependenciasCrossHoja);
    }

    public function test_mixed_same_sheet_and_cross_sheet_formula(): void
    {
        $cell = $this->scanSingleFormulaCell('=A1+HOJADESTINO!D20');

        $this->assertSame(['A1'], $cell->dependencias);
        $this->assertSame([
            ['hoja' => 'HOJADESTINO', 'tipo' => 'celda', 'celda' => 'D20', 'celda_inicio' => null, 'celda_fin' => null],
        ], $cell->dependenciasCrossHoja);
    }

    // ── Regresion explicita del hallazgo real de BM-2.5 ─────────────────

    public function test_bm18a_style_sheet_name_never_produces_spurious_local_dependency(): void
    {
        // Nombre de hoja con forma de coordenada Excel (letras+digitos+letra)
        // -- exactamente el patron real BM18/BM18A que disparo el hallazgo
        // original. No se hardcodea BM en el motor; este test solo prueba
        // que ese patron especifico de nombre de hoja no rompe el parser.
        $cell = $this->scanSingleFormulaCell('=BM18A!D20');

        $this->assertSame([], $cell->dependencias, 'BM18A nunca debe producir una dependencia local "BM18"');
        $this->assertNotContains('BM18', $cell->dependencias);
        $this->assertSame([
            ['hoja' => 'BM18A', 'tipo' => 'celda', 'celda' => 'D20', 'celda_inicio' => null, 'celda_fin' => null],
        ], $cell->dependenciasCrossHoja);
    }

    public function test_bm18_style_sum_range_never_produces_spurious_local_dependency(): void
    {
        $cell = $this->scanSingleFormulaCell('=SUM(BM18A!D92:D113)');

        $this->assertSame([], $cell->dependencias);
        $this->assertNotContains('BM18', $cell->dependencias);
        $this->assertSame([
            ['hoja' => 'BM18A', 'tipo' => 'rango', 'celda' => null, 'celda_inicio' => 'D92', 'celda_fin' => 'D113'],
        ], $cell->dependenciasCrossHoja);
    }
}
