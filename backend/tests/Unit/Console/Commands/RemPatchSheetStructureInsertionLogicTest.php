<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\RemPatchSheetStructureCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Cubre la nueva capacidad de rem:patch-sheet-structure para INSERTAR una
 * seccion ausente en la estructura base pero detectada por el parser
 * corregido -- hallazgo real de A26/A (y tambien A05/F, A06/K, A24/A-B,
 * A25/C-D, A32/D): builds anteriores omitieron secciones completas, no
 * solo calcularon mal sus limites de fila.
 *
 * Prueba directamente insertSectionInFreshOrder() y deepDiffOutsideScope()
 * via reflection (ambos son metodos privados que operan sobre arrays, sin
 * dependencias de BD/Excel) para cubrir con precision los casos de borde
 * de las guardas de seguridad, incluyendo escenarios que el flujo normal
 * del comando nunca produciria por construccion (ej. "aparece una seccion
 * no autorizada") pero que deben seguir abortando si algo mas en el codigo
 * llegara a producirlos.
 */
class RemPatchSheetStructureInsertionLogicTest extends TestCase
{
    private function command(): RemPatchSheetStructureCommand
    {
        return new RemPatchSheetStructureCommand();
    }

    private function invokeInsert(array $sections, string $insertCode, array $freshByCode, array $freshCodesInOrder): array
    {
        $method = new ReflectionMethod(RemPatchSheetStructureCommand::class, 'insertSectionInFreshOrder');
        $method->setAccessible(true);

        return $method->invoke($this->command(), $sections, $insertCode, $freshByCode, $freshCodesInOrder);
    }

    private function invokeDiff(array $oldForms, array $newForms, string $targetSheet, array $requestedCodes): array
    {
        $method = new ReflectionMethod(RemPatchSheetStructureCommand::class, 'deepDiffOutsideScope');
        $method->setAccessible(true);

        return $method->invoke($this->command(), $oldForms, $newForms, $targetSheet, $requestedCodes);
    }

    private function section(string $codigo): array
    {
        return ['codigo' => $codigo, 'filaHeader' => 1, 'filaInicioDatos' => 2, 'filaFinDatos' => 3, 'fields' => []];
    }

    // --- insertSectionInFreshOrder() ---

    public function test_inserts_missing_section_at_the_beginning(): void
    {
        $freshCodesInOrder = ['A', 'A.1', 'B'];
        $freshByCode = ['A' => $this->section('A'), 'A.1' => $this->section('A.1'), 'B' => $this->section('B')];
        $sections = [$this->section('A.1'), $this->section('B')]; // A ausente

        $result = $this->invokeInsert($sections, 'A', $freshByCode, $freshCodesInOrder);

        $this->assertSame(['A', 'A.1', 'B'], array_column($result, 'codigo'));
    }

    public function test_inserts_missing_section_between_two_existing(): void
    {
        $freshCodesInOrder = ['A', 'B', 'C'];
        $freshByCode = ['A' => $this->section('A'), 'B' => $this->section('B'), 'C' => $this->section('C')];
        $sections = [$this->section('A'), $this->section('C')]; // B ausente

        $result = $this->invokeInsert($sections, 'B', $freshByCode, $freshCodesInOrder);

        $this->assertSame(['A', 'B', 'C'], array_column($result, 'codigo'));
    }

    public function test_inserts_missing_section_at_the_end_when_it_is_the_last_of_the_sheet(): void
    {
        $freshCodesInOrder = ['A', 'B', 'C'];
        $freshByCode = ['A' => $this->section('A'), 'B' => $this->section('B'), 'C' => $this->section('C')];
        $sections = [$this->section('A'), $this->section('B')]; // C ausente, es la ultima

        $result = $this->invokeInsert($sections, 'C', $freshByCode, $freshCodesInOrder);

        $this->assertSame(['A', 'B', 'C'], array_column($result, 'codigo'));
    }

    // --- deepDiffOutsideScope(): insercion autorizada ---

    public function test_diff_allows_authorized_insertion_at_the_beginning(): void
    {
        $oldForms = [['sheetName' => 'A26', 'sections' => [$this->section('A.1'), $this->section('B')]]];
        $newForms = [['sheetName' => 'A26', 'sections' => [$this->section('A'), $this->section('A.1'), $this->section('B')]]];

        $diffs = $this->invokeDiff($oldForms, $newForms, 'A26', ['A']);

        $this->assertSame([], $diffs, 'una insercion explicitamente autorizada, en la posicion correcta, no debe generar diffs');
    }

    // --- deepDiffOutsideScope(): guardas de seguridad ---

    public function test_diff_aborts_when_an_unauthorized_new_section_appears(): void
    {
        $oldForms = [['sheetName' => 'A26', 'sections' => [$this->section('A.1'), $this->section('B')]]];
        // "Z" aparece pero nunca fue solicitada.
        $newForms = [['sheetName' => 'A26', 'sections' => [$this->section('A.1'), $this->section('Z'), $this->section('B')]]];

        $diffs = $this->invokeDiff($oldForms, $newForms, 'A26', ['A']); // solo A estaba autorizada

        $this->assertNotEmpty($diffs);
        $this->assertStringContainsString('no autorizadas', implode(' ', $diffs));
        $this->assertStringContainsString('Z', implode(' ', $diffs));
    }

    public function test_diff_aborts_when_an_existing_section_disappears(): void
    {
        $oldForms = [['sheetName' => 'A26', 'sections' => [$this->section('A.1'), $this->section('B'), $this->section('C')]]];
        // B desaparece sin haber sido solicitada.
        $newForms = [['sheetName' => 'A26', 'sections' => [$this->section('A.1'), $this->section('C')]]];

        $diffs = $this->invokeDiff($oldForms, $newForms, 'A26', ['C']);

        $this->assertNotEmpty($diffs);
        $this->assertStringContainsString('desaparecieron', implode(' ', $diffs));
        $this->assertStringContainsString('B', implode(' ', $diffs));
    }

    public function test_diff_aborts_when_relative_order_of_existing_sections_changes(): void
    {
        $oldForms = [['sheetName' => 'A26', 'sections' => [$this->section('A.1'), $this->section('B'), $this->section('C')]]];
        // B y C invierten su orden relativo -- no es una insercion, es una reordenacion.
        $newForms = [['sheetName' => 'A26', 'sections' => [$this->section('A.1'), $this->section('C'), $this->section('B')]]];

        $diffs = $this->invokeDiff($oldForms, $newForms, 'A26', ['B', 'C']);

        $this->assertNotEmpty($diffs);
        $this->assertStringContainsString('orden relativo', implode(' ', $diffs));
    }

    public function test_diff_still_aborts_when_unrequested_section_content_changes(): void
    {
        // Regresion: el comportamiento historico (contenido de una seccion
        // no solicitada que cambia) debe seguir detectandose igual que
        // antes de agregar soporte de insercion.
        $oldForms = [['sheetName' => 'A26', 'sections' => [$this->section('A.1'), $this->section('B')]]];
        $bModificada = $this->section('B');
        $bModificada['filaFinDatos'] = 999; // cambio no autorizado
        $newForms = [['sheetName' => 'A26', 'sections' => [$this->section('A.1'), $bModificada]]];

        $diffs = $this->invokeDiff($oldForms, $newForms, 'A26', ['A.1']);

        $this->assertNotEmpty($diffs);
        $this->assertStringContainsString("seccion 'B'", implode(' ', $diffs));
    }

    public function test_diff_still_aborts_when_another_sheet_changes_without_being_requested(): void
    {
        // Regresion: el aislamiento entre hojas no debe verse afectado.
        $oldForms = [
            ['sheetName' => 'A01', 'sections' => [$this->section('A')]],
            ['sheetName' => 'A26', 'sections' => [$this->section('A.1')]],
        ];
        $a01Modificada = ['sheetName' => 'A01', 'sections' => [$this->section('A'), $this->section('B')]];
        $newForms = [
            $a01Modificada,
            ['sheetName' => 'A26', 'sections' => [$this->section('A'), $this->section('A.1')]],
        ];

        $diffs = $this->invokeDiff($oldForms, $newForms, 'A26', ['A']);

        $this->assertNotEmpty($diffs);
        $this->assertStringContainsString("Hoja 'A01'", implode(' ', $diffs));
    }
}
