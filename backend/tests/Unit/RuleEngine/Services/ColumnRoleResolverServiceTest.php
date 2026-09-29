<?php

namespace Tests\Unit\RuleEngine\Services;

use App\Domain\REM\Services\ColumnRoleResolverService;
use PHPUnit\Framework\TestCase;

class ColumnRoleResolverServiceTest extends TestCase
{
    private function cell(
        string $value = '',
        bool $formula = false,
        bool $merged = false,
        ?string $range = null,
    ): array {
        return [
            'valor_bruto' => $value,
            'es_formula' => $formula,
            'es_combinada' => $merged,
            'rango_combinado' => $range,
        ];
    }

    /**
     * Celda con metadata de proteccion explicita (es_editable/esta_bloqueada),
     * requerida por detectStructuralLabelColumn().
     */
    private function protectedCell(
        ?string $value = null,
        bool $editable = false,
        bool $locked = true,
        bool $formula = false,
        ?string $range = null,
    ): array {
        return [
            'valor_bruto' => $value,
            'es_formula' => $formula,
            'es_editable' => $editable,
            'esta_bloqueada' => $locked,
            'es_combinada' => $range !== null,
            'rango_combinado' => $range,
        ];
    }

    /**
     * Forma real de A25/A.3 (columnas C/D, filas 31-61): D fusionada con C en
     * la mayoria de las filas, texto propio solo en D43/D44, todo bloqueado.
     *
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function a25LikeRows(): array
    {
        $rows = [];
        for ($row = 31; $row <= 61; $row++) {
            $range = "C{$row}:D{$row}";
            $rows[$row] = [
                'C' => $this->protectedCell("Subtipo {$row}", range: $range),
                'D' => $this->protectedCell(null, range: $range),
                'F' => $this->protectedCell(null, editable: true, locked: false),
            ];
        }
        $rows[43]['C'] = $this->protectedCell('Con perdida de conciencia', range: 'C43:C44');
        $rows[44]['C'] = $this->protectedCell(null, range: 'C43:C44');
        $rows[43]['D'] = $this->protectedCell('<60 segundos, sin complicaciones');
        $rows[44]['D'] = $this->protectedCell('>= 60 segundos y/o convulsiones o incontinencia');

        return $rows;
    }

    private function detectStructural(array $rows, array $columns = ['D', 'F']): ?string
    {
        $fields = array_map(fn ($c) => ['letra' => $c, 'esTotal' => false], $columns);

        return (new ColumnRoleResolverService())->detectStructuralLabelColumn($rows, $fields, [], 31, 61, 'C');
    }

    public function test_two_real_hierarchical_labels_are_not_enough_under_current_threshold(): void
    {
        /*
         * Regresion observada en A25/A.3:
         *
         * D43 = "<60 segundos, sin complicaciones"
         * D44 = ">= 60 segundos y/o convulsiones o incontinencia"
         *
         * El detector estricto (detectSubcategoryColumn) se mantiene sin
         * cambios: con solo dos etiquetas sigue devolviendo null. El caso se
         * resuelve con detectStructuralLabelColumn() (tests de abajo), que el
         * parser solo usa cuando este detector no encuentra nada.
         */
        $service = new ColumnRoleResolverService();

        $rows = [];

        for ($row = 31; $row <= 61; $row++) {
            $rows[$row] = [
                'C' => $this->cell(),
                'D' => $this->cell(),
                'F' => $this->cell(),
            ];
        }

        $rows[43]['C'] = $this->cell('Con perdida de conciencia');
        $rows[43]['D'] = $this->cell('<60 segundos, sin complicaciones');

        $rows[44]['D'] = $this->cell('>= 60 segundos y/o convulsiones o incontinencia');

        $fields = [
            ['letra' => 'D', 'esTotal' => false],
            ['letra' => 'F', 'esTotal' => false],
        ];

        $result = $service->detectSubcategoryColumn(
            $rows,
            $fields,
            [],
            31,
            61,
            'C',
        );

        // Este es precisamente el bug que queremos demostrar primero:
        // con solo dos etiquetas reales, el algoritmo actual devuelve null.
        $this->assertNull($result);
    }

    public function test_three_consistent_text_labels_are_detected_as_hierarchical_column(): void
    {
        $service = new ColumnRoleResolverService();

        $rows = [];

        for ($row = 31; $row <= 61; $row++) {
            $rows[$row] = [
                'C' => $this->cell(),
                'D' => $this->cell(),
            ];
        }

        $rows[43]['D'] = $this->cell('Etiqueta descriptiva 1');
        $rows[44]['D'] = $this->cell('Etiqueta descriptiva 2');
        $rows[45]['D'] = $this->cell('Etiqueta descriptiva 3');

        $fields = [
            ['letra' => 'D', 'esTotal' => false],
        ];

        $result = $service->detectSubcategoryColumn(
            $rows,
            $fields,
            [],
            31,
            61,
            'C',
        );

        $this->assertSame('D', $result);
    }

    public function test_numeric_evidence_prevents_column_from_being_classified_as_hierarchical(): void
    {
        $service = new ColumnRoleResolverService();

        $rows = [];

        for ($row = 31; $row <= 61; $row++) {
            $rows[$row] = [
                'C' => $this->cell(),
                'D' => $this->cell(),
            ];
        }

        $rows[40]['D'] = $this->cell('Etiqueta 1');
        $rows[41]['D'] = $this->cell('Etiqueta 2');
        $rows[42]['D'] = $this->cell('Etiqueta 3');

        // Evidencia real de captura numerica: la columna no puede
        // reclasificarse como un nivel descriptivo.
        $rows[50]['D'] = $this->cell('7');

        $fields = [
            ['letra' => 'D', 'esTotal' => false],
        ];

        $result = $service->detectSubcategoryColumn(
            $rows,
            $fields,
            [],
            31,
            61,
            'C',
        );

        $this->assertNull($result);
    }

    public function test_structural_label_column_detects_a25_fourth_level(): void
    {
        $this->assertSame('D', $this->detectStructural($this->a25LikeRows()));
    }

    public function test_structural_rule_rejects_capture_column_with_leaked_header_rows(): void
    {
        // Proteccion original del umbral de 3 filas: columna de captura real
        // con 2 filas de texto de encabezado filtradas en el rango de datos.
        $rows = $this->a25LikeRows();
        for ($row = 50; $row <= 61; $row++) {
            $rows[$row]['D'] = $this->protectedCell(null, editable: true, locked: false);
        }

        $this->assertNull($this->detectStructural($rows));
    }

    public function test_structural_rule_rejects_cells_without_protection_metadata(): void
    {
        $rows = $this->a25LikeRows();
        unset($rows[43]['D']['es_editable'], $rows[43]['D']['esta_bloqueada']);

        $this->assertNull($this->detectStructural($rows));
    }

    public function test_structural_rule_rejects_numeric_evidence(): void
    {
        $rows = $this->a25LikeRows();
        $rows[50]['D'] = $this->protectedCell('7');

        $this->assertNull($this->detectStructural($rows));
    }

    public function test_structural_rule_rejects_formula_cells(): void
    {
        $rows = $this->a25LikeRows();
        $rows[50]['D'] = $this->protectedCell(null, formula: true);

        $this->assertNull($this->detectStructural($rows));
    }

    public function test_structural_rule_requires_merge_continuity_with_parent_level(): void
    {
        // Columna completamente bloqueada con 2 textos, pero nunca fusionada
        // con el nivel padre: no hay evidencia de que pertenezca a su jerarquia.
        $rows = $this->a25LikeRows();
        for ($row = 31; $row <= 61; $row++) {
            if ($rows[$row]['D']['es_combinada']) {
                $rows[$row]['D'] = $this->protectedCell(null);
            }
        }

        $this->assertNull($this->detectStructural($rows));
    }

    public function test_structural_rule_requires_at_least_one_independent_label(): void
    {
        $rows = $this->a25LikeRows();
        $rows[43]['D'] = $this->protectedCell(null);
        $rows[44]['D'] = $this->protectedCell(null);

        $this->assertNull($this->detectStructural($rows));
    }

    public function test_structural_rule_never_selects_editable_numeric_column(): void
    {
        // F es una columna de captura (editable) en todas las filas.
        $this->assertNull($this->detectStructural($this->a25LikeRows(), ['F']));
    }
}
