<?php

namespace Tests\Unit\REM\Services;

use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Services\RemValidationService;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RemValidationServiceTest extends TestCase
{
    public function test_required_and_le_parent_skips_blocked_child_cell_from_cell_data(): void
    {
        $service = new RemValidationService(new class extends CellDataStorageService {
            public function getCellForCoordinate(string $sheet, string $section, string $coordinate): ?array
            {
                return $coordinate === 'W28'
                    ? ['esta_bloqueada' => true, 'es_editable' => false]
                    : null;
            }
        });

        $result = $this->invokeRequiredAndLeParent($service, [
            'key' => 'a01_w_11_required_le_c',
            'section' => 'A01',
            'child_column' => 'W',
            'parent_column' => 'C',
        ], [
            'concept' => 'Ginecologico',
            'professional' => 'Matrona/on',
            'row_number' => 28,
            'rem_section_code' => 'A',
            'values' => ['C' => 218, 'V' => 218, 'W' => null],
        ]);

        $this->assertNull($result);
    }

    public function test_required_and_le_parent_keeps_warning_for_editable_empty_child_cell(): void
    {
        $service = new RemValidationService(new class extends CellDataStorageService {
            public function getCellForCoordinate(string $sheet, string $section, string $coordinate): ?array
            {
                return $coordinate === 'W31'
                    ? ['esta_bloqueada' => false, 'es_editable' => true]
                    : null;
            }
        });

        $result = $this->invokeRequiredAndLeParent($service, [
            'key' => 'a01_w_31_required_le_c',
            'section' => 'A01',
            'child_column' => 'W',
            'parent_column' => 'C',
        ], [
            'concept' => 'Climaterio',
            'professional' => 'Matrona/on',
            'row_number' => 31,
            'rem_section_code' => 'A',
            'values' => ['C' => 10, 'W' => null],
        ]);

        $this->assertIsArray($result);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Campo W requerido', $result['message']);
    }

    private function invokeRequiredAndLeParent(RemValidationService $service, array $rule, array $rowData): ?array
    {
        $method = (new ReflectionClass($service))->getMethod('evaluateRequiredAndLeParent');
        $method->setAccessible(true);

        $upload = new RemUpload();
        $upload->id = 123;

        return $method->invoke($service, $rule, $rowData, $upload, 456);
    }
}
