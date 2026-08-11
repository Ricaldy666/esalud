<?php

namespace Tests\Unit\RemParser\Services;

use App\Domain\RemParser\Services\EnhancedCellScanner;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

/**
 * Cubre extendScanForTrailingTotalRows() -- una fila TOTAL final excluida
 * de filaFinDatos (SectionDetectorService::excludeTrailingTotalRows(), ver
 * SectionDetectorServiceTrailingTotalRowTest) debe seguir siendo escaneada
 * y visible en cell_data como referencia tecnica (bloqueada/formula),
 * aunque quede fuera del rango de datos usado para rem_data/patrones.
 */
class EnhancedCellScannerTrailingTotalRowTest extends TestCase
{
    private function scanner(): EnhancedCellScanner
    {
        return new EnhancedCellScanner();
    }

    private function sheet(array $rows, bool $protect = true): Worksheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('HOJATEST');

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

    public function test_trailing_total_row_excluded_from_fila_fin_datos_is_still_scanned(): void
    {
        $ws = $this->sheet([
            2 => ['A' => 'Concepto', 'B' => 'TOTAL'],
            3 => ['A' => 'Item 1', 'B' => '=SUM(C3:D3)'],
            4 => ['A' => 'Item 2', 'B' => '=SUM(C4:D4)'],
            5 => ['A' => 'TOTAL', 'B' => '=SUM(B3:B4)'],
        ]);

        $sectionData = [
            'filaHeader' => 2,
            'filaInicioDatos' => 3,
            'filaFinDatos' => 4, // ya excluye la fila 5 (TOTAL), como haria SectionDetectorService
            'fields' => [
                ['letra' => 'A', 'label' => 'Concepto'],
                ['letra' => 'B', 'label' => 'TOTAL'],
            ],
        ];

        $cells = $this->scanner()->scan($ws, $sectionData);

        $this->assertArrayHasKey('B5', $cells, 'la fila TOTAL (5) debe seguir visible en cell_data aunque quede fuera de filaFinDatos');
        $this->assertTrue($cells['B5']->esFormula, 'B5 debe registrarse como formula');
        $this->assertSame('=SUM(B3:B4)', $cells['B5']->formula);
    }

    public function test_row_beyond_trailing_total_is_not_included(): void
    {
        $ws = $this->sheet([
            2 => ['A' => 'Concepto', 'B' => 'TOTAL'],
            3 => ['A' => 'Item 1', 'B' => '=SUM(C3:D3)'],
            4 => ['A' => 'Item 2', 'B' => '=SUM(C4:D4)'],
            5 => ['A' => 'TOTAL', 'B' => '=SUM(B3:B4)'],
            6 => ['A' => 'SECCION SIGUIENTE'],
        ]);

        $sectionData = [
            'filaHeader' => 2,
            'filaInicioDatos' => 3,
            'filaFinDatos' => 4,
            'fields' => [
                ['letra' => 'A', 'label' => 'Concepto'],
                ['letra' => 'B', 'label' => 'TOTAL'],
            ],
        ];

        $cells = $this->scanner()->scan($ws, $sectionData);

        $this->assertArrayNotHasKey('A6', $cells, 'no debe extenderse mas alla de la fila TOTAL real -- fila 6 (otra seccion) no debe escanearse');
    }

    /**
     * Hallazgo real de A32/F2 fila 151 (2026-08-10): el concepto de la fila
     * TOTAL final no vive en columna A (vacia), sino en B -- patron de
     * grupo con concepto puesto solo en la primera fila del bloque.
     */
    public function test_trailing_total_row_with_concept_in_column_b_is_still_scanned(): void
    {
        $ws = $this->sheet([
            2 => ['A' => 'Especialidad', 'B' => 'TOTAL', 'C' => 'RANGO'],
            3 => ['A' => 'Grupo Medico', 'B' => 'Pediatría', 'C' => '=SUM(D3:E3)'],
            4 => ['B' => 'Medicina interna', 'C' => '=SUM(D4:E4)'],
            5 => ['B' => 'TOTAL', 'C' => '=SUM(C3:C4)', 'D' => '=SUM(D3:D4)', 'E' => '=SUM(E3:E4)'],
        ]);

        $sectionData = [
            'filaHeader' => 2,
            'filaInicioDatos' => 3,
            'filaFinDatos' => 4, // ya excluye la fila 5 (TOTAL en columna B)
            'fields' => [
                ['letra' => 'A', 'label' => 'Especialidad'],
                ['letra' => 'B', 'label' => 'TOTAL'],
                ['letra' => 'C', 'label' => 'RANGO'],
                ['letra' => 'D', 'label' => 'RANGO / algo'],
                ['letra' => 'E', 'label' => 'RANGO / algo mas'],
            ],
        ];

        $cells = $this->scanner()->scan($ws, $sectionData);

        $this->assertArrayHasKey('B5', $cells, 'la fila TOTAL con concepto en columna B (no A) debe seguir visible en cell_data aunque quede fuera de filaFinDatos');
        $this->assertTrue($cells['D5']->esFormula);
        $this->assertSame('=SUM(D3:D4)', $cells['D5']->formula);
    }

    public function test_section_without_trailing_total_scans_exactly_up_to_fila_fin_datos(): void
    {
        $ws = $this->sheet([
            2 => ['A' => 'Concepto', 'B' => 'Total'],
            3 => ['A' => 'Item 1', 'B' => 5],
            4 => ['A' => 'Item 2', 'B' => 3],
        ]);

        $sectionData = [
            'filaHeader' => 2,
            'filaInicioDatos' => 3,
            'filaFinDatos' => 4,
            'fields' => [
                ['letra' => 'A', 'label' => 'Concepto'],
                ['letra' => 'B', 'label' => 'Total'],
            ],
        ];

        $cells = $this->scanner()->scan($ws, $sectionData);

        $this->assertArrayHasKey('B4', $cells);
        $this->assertArrayNotHasKey('A5', $cells, 'sin patron de TOTAL final, el escaneo no debe extenderse');
    }
}
