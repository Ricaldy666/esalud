<?php

namespace Tests\Unit\RuleEngine\Services;

use App\Domain\REM\Services\ColumnRoleResolverService;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\CertificationService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Cubre SectionCalibrationMatrixService::applyExceptionClassification() --
 * la senal automatica de "posible excepcion de negocio" para patrones de
 * fila unica o grupos claramente minoritarios dentro de una seccion, nacida
 * de la auditoria de A11 (2026-08-06): filas como "Mujeres en control
 * ginecologico" o "Donantes de sangre" quedan solas o en pares porque su
 * formula suma un rango de columnas distinto al patron dominante -- deben
 * marcarse para que la calibracion nunca les aplique por defecto la
 * configuracion general de la seccion.
 *
 * El metodo es privado (solo se invoca desde buildPatternMatrix(), que
 * requiere una estructura+cell_data completas para construir $enriched); se
 * prueba via reflection con arrays sinteticos minimos (solo id/filas, que es
 * todo lo que el metodo lee) en vez de montar un fixture Excel completo.
 */
class SectionCalibrationMatrixServiceExceptionClassificationTest extends TestCase
{
    private function service(): SectionCalibrationMatrixService
    {
        return new SectionCalibrationMatrixService(
            new CertificationService(),
            new FunctionalRuleService(),
            new CellDataStorageService(),
            new ColumnRoleResolverService(),
        );
    }

    /**
     * @param  array<int, int[]>  $patternsFilas  id => filas
     */
    private function classify(array $patternsFilas): array
    {
        $enriched = [];
        foreach ($patternsFilas as $id => $filas) {
            $enriched[] = ['id' => $id, 'filas' => $filas];
        }

        $method = new ReflectionMethod(SectionCalibrationMatrixService::class, 'applyExceptionClassification');
        $method->setAccessible(true);

        $result = $method->invoke($this->service(), $enriched);

        $byId = [];
        foreach ($result as $p) {
            $byId[$p['id']] = $p;
        }

        return $byId;
    }

    public function test_single_pattern_section_never_flags_anything(): void
    {
        $result = $this->classify([1 => [10, 11, 12]]);

        $this->assertFalse($result[1]['possible_business_exception']);
        $this->assertSame('majority', $result[1]['pattern_size_class']);
        $this->assertNull($result[1]['exception_reason']);
    }

    public function test_single_row_pattern_is_flagged_as_exception(): void
    {
        // Caso real: A11/A.1 patron 4, fila 22 unica frente al patron
        // dominante de 7 filas.
        $result = $this->classify([
            1 => [13, 14, 15, 16, 17, 19, 20],
            4 => [22],
        ]);

        $this->assertTrue($result[4]['possible_business_exception']);
        $this->assertSame('minority', $result[4]['pattern_size_class']);
        $this->assertStringContainsString('Fila única', $result[4]['exception_reason']);
        $this->assertFalse($result[1]['possible_business_exception']);
        $this->assertSame('majority', $result[1]['pattern_size_class']);
    }

    public function test_minority_group_below_threshold_is_flagged(): void
    {
        // Caso real: A11/A.1 patron 2, 2 filas frente a 7 (2/7 ~ 0.29 <= 0.3).
        $result = $this->classify([
            1 => [13, 14, 15, 16, 17, 19, 20],
            2 => [18, 24],
        ]);

        $this->assertTrue($result[2]['possible_business_exception']);
        $this->assertStringContainsString('Grupo minoritario', $result[2]['exception_reason']);
        $this->assertStringContainsString('2 de 7', $result[2]['exception_reason']);
    }

    public function test_group_above_threshold_is_not_flagged(): void
    {
        // Caso real: A11/A.1 patron 6, 4 filas frente a 7 (4/7 ~ 0.57 > 0.3)
        // -- suficientemente grande para no tratarse como excepcion aislada.
        $result = $this->classify([
            1 => [13, 14, 15, 16, 17, 19, 20],
            6 => [25, 28, 29, 30],
        ]);

        $this->assertFalse($result[6]['possible_business_exception']);
        $this->assertSame('majority', $result[6]['pattern_size_class']);
        $this->assertNull($result[6]['exception_reason']);
    }

    public function test_equally_sized_patterns_are_never_flagged_against_each_other(): void
    {
        $result = $this->classify([
            1 => [1, 2, 3, 4, 5],
            2 => [6, 7, 8, 9, 10],
        ]);

        $this->assertFalse($result[1]['possible_business_exception']);
        $this->assertFalse($result[2]['possible_business_exception']);
    }

    public function test_full_a11_a1_signature_matches_audit_findings(): void
    {
        // Reproduce exactamente la matriz real de A11/A.1 encontrada en la
        // auditoria del 2026-08-06: patrones 3, 4 y 7 (fila unica) y el
        // patron 2 (2 filas) deben quedar marcados; 1, 5 y 6 no.
        $result = $this->classify([
            1 => [13, 14, 15, 16, 17, 19, 20],
            2 => [18, 24],
            3 => [21],
            4 => [22],
            5 => [23, 26],
            6 => [25, 28, 29, 30],
            7 => [27],
        ]);

        foreach ([1, 6] as $majorityId) {
            $this->assertFalse($result[$majorityId]['possible_business_exception'], "patron {$majorityId} no deberia marcarse");
        }
        foreach ([2, 3, 4, 5, 7] as $exceptionId) {
            $this->assertTrue($result[$exceptionId]['possible_business_exception'], "patron {$exceptionId} deberia marcarse");
        }
    }
}
