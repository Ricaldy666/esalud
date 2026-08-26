<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\REM\Services\ColumnRoleResolverService;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\CertificationService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Cubre SectionCalibrationMatrixService::isEmbeddedLeadingTotalRow() --
 * Hallazgo A09/G filas 183 y 196 (2026-08-24, auditoria de los 3
 * REQUIRES_INVESTIGATION de Tanda 2B-6): patron OPUESTO a
 * isEmbeddedBackwardSubtotalRow() (mecanismo #12) -- una fila TOTAL LIDER
 * embebida que agrega EXCLUSIVAMENTE filas posteriores debe clasificarse
 * como 'total' (no 'data') en classifyRow(), igual que ya ocurria para el
 * mecanismo #12, para que quede automaticamente fuera de la construccion
 * de patrones.
 *
 * Antes de este fix, classifyRow() solo invocaba
 * isEmbeddedBackwardSubtotalRow() -- el mecanismo #6 (TOTAL lider, ya
 * existente en SectionDetectorService::isLeadingTotalRow() y
 * RemParserService::isEmbeddedLeadingTotalRow()) nunca estaba conectado a
 * la construccion de patrones de calibracion, dejando filas TOTAL lider
 * reales mezcladas como 'data' dentro del mismo pattern_id que filas
 * heterogeneas (A09/G P2: fila 183 TOTAL lider + filas 190/191 inertes;
 * A09/G P4: fila 196 TOTAL lider + filas 192-194/199/200).
 *
 * Misma logica estructural que RemParserService::isEmbeddedLeadingTotalRow(),
 * duplicada aqui de forma independiente sobre cell_data (mismo patron ya
 * usado por isEmbeddedBackwardSubtotalRow() en esta misma clase).
 */
class SectionCalibrationMatrixServiceEmbeddedLeadingTotalRowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): SectionCalibrationMatrixService
    {
        return new SectionCalibrationMatrixService(
            new CertificationService(),
            new FunctionalRuleService(),
            new CellDataStorageService(),
            new ColumnRoleResolverService(),
        );
    }

    private function cell(bool $editable, bool $blocked, bool $formula = false, ?string $formulaText = null, ?string $valorBruto = null): array
    {
        return [
            'valor_bruto' => $valorBruto,
            'es_editable' => $editable,
            'esta_bloqueada' => $blocked,
            'es_formula' => $formula,
            'formula' => $formulaText,
        ];
    }

    private function isLeadingTotal(string $sheet, string $section, int $row, array $sectionData): bool
    {
        $method = new ReflectionMethod(SectionCalibrationMatrixService::class, 'isEmbeddedLeadingTotalRow');
        $method->setAccessible(true);

        return $method->invoke($this->service(), $sheet, $section, $row, $sectionData);
    }

    private function isBackwardSubtotal(string $sheet, string $section, int $row, array $sectionData): bool
    {
        $method = new ReflectionMethod(SectionCalibrationMatrixService::class, 'isEmbeddedBackwardSubtotalRow');
        $method->setAccessible(true);

        return $method->invoke($this->service(), $sheet, $section, $row, $sectionData);
    }

    private function classifyRow(string $sheet, string $section, int $row, int $headerRow, array $sectionData): string
    {
        $method = new ReflectionMethod(SectionCalibrationMatrixService::class, 'classifyRow');
        $method->setAccessible(true);

        return $method->invoke($this->service(), $sheet, $section, $row, $headerRow, $sectionData);
    }

    private function fields(): array
    {
        return [
            ['letra' => 'A', 'label' => 'Concepto', 'esControlOculto' => false],
            ['letra' => 'B', 'label' => 'SubConcepto', 'esControlOculto' => false],
            ['letra' => 'C', 'label' => 'Concepto2', 'esControlOculto' => false],
            ['letra' => 'D', 'label' => 'Data1', 'esControlOculto' => false],
        ];
    }

    /**
     * Patron real de A09/G fila 183: "TOTAL" en columna C, columna D es
     * formula que agrega EXCLUSIVAMENTE filas posteriores (184-189, el
     * bloque del patron P3 vecino).
     */
    public function test_a09_g_fila_183_pattern_is_classified_as_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('A09', 'G', [
            'C183' => $this->cell(false, true, false, null, 'TOTAL'),
            'D183' => $this->cell(false, true, true, '=SUM(D184:D189)'),
        ]);

        $result = $this->isLeadingTotal('A09', 'G', 183, ['filaInicioDatos' => 177, 'fields' => $this->fields()]);

        $this->assertTrue($result, 'fila con "TOTAL" en columna C y formula EXCLUSIVAMENTE hacia adelante debe clasificarse como total lider');
    }

    /**
     * Patron real de A09/G fila 196: mismo mecanismo que 183, agrega el
     * bloque del patron P6 vecino (197-198).
     */
    public function test_a09_g_fila_196_pattern_is_classified_as_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('A09', 'G', [
            'C196' => $this->cell(false, true, false, null, 'TOTAL'),
            'D196' => $this->cell(false, true, true, '=SUM(D197:D198)'),
        ]);

        $result = $this->isLeadingTotal('A09', 'G', 196, ['filaInicioDatos' => 177, 'fields' => $this->fields()]);

        $this->assertTrue($result);
    }

    /**
     * Patron EXACTO real de A09/G filas 183/196 (verificado contra
     * cell-data real): encabezado multi-columna "PROGRAMA - ACTIVIDAD" en
     * A/B/C -- la columna B tiene texto de concepto real ("Altas
     * integrales...", NO es "TOTAL"), y recien la columna C tiene el
     * marcador "TOTAL". La deteccion debe seguir buscando mas alla de la
     * primera columna de texto plano (B) hasta encontrar la que
     * verdaderamente parece TOTAL (C) -- de lo contrario la fila nunca se
     * detecta (bug encontrado durante el desarrollo de este fix, corregido
     * antes de aplicarlo).
     */
    public function test_a09_g_fila_183_exact_real_pattern_with_concept_before_total_marker(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('A09', 'G', [
            'A183' => $this->cell(false, true, false, null, null),
            'B183' => $this->cell(false, true, false, null, 'Altas integrales '),
            'C183' => $this->cell(false, true, false, null, 'TOTAL'),
            // D/F: subtotal HORIZONTAL de la propia fila (patron real
            // D183=SUM(F183), F183=SUM(AD183+...) -- neutral, no es la
            // evidencia de agregacion hacia adelante).
            'D183' => $this->cell(false, true, true, '=SUM(F183)'),
            'F183' => $this->cell(false, true, true, '=SUM(AD183+AF183)'),
            // AD/AF: evidencia real de agregacion hacia adelante (184-189).
            'AD183' => $this->cell(false, true, true, '=SUM(AD184:AD189)'),
            'AF183' => $this->cell(false, true, true, '=SUM(AF184:AF189)'),
        ]);

        $result = $this->isLeadingTotal('A09', 'G', 183, ['filaInicioDatos' => 177, 'fields' => [
            ['letra' => 'A', 'label' => 'PROGRAMA - ACTIVIDAD', 'esControlOculto' => false],
            ['letra' => 'B', 'label' => 'PROGRAMA - ACTIVIDAD', 'esControlOculto' => false],
            ['letra' => 'C', 'label' => 'PROGRAMA - ACTIVIDAD', 'esControlOculto' => false],
            ['letra' => 'D', 'label' => 'TOTAL', 'esControlOculto' => false],
            ['letra' => 'F', 'label' => 'TOTAL', 'esControlOculto' => false],
            ['letra' => 'AD', 'label' => 'RANGO ETARIO Y SEXO', 'esControlOculto' => false],
            ['letra' => 'AF', 'label' => 'RANGO ETARIO Y SEXO', 'esControlOculto' => false],
        ]]);

        $this->assertTrue($result, 'debe encontrar la etiqueta TOTAL en C y reconocer la evidencia hacia adelante en AD/AF pese a los subtotales horizontales D/F');
    }

    /**
     * Caso negativo/neutral: una formula que SOLO referencia su propia
     * fila (subtotal horizontal puro, sin ninguna columna con referencia
     * hacia adelante) no debe, por si sola, disqualificar NI contar como
     * evidencia -- sin evidencia hacia adelante en ninguna otra columna,
     * el resultado final debe ser false.
     */
    public function test_formula_referencing_only_own_row_is_neutral_not_sufficient_evidence(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('A09', 'G', [
            'C183' => $this->cell(false, true, false, null, 'TOTAL'),
            'D183' => $this->cell(false, true, true, '=SUM(F183)'),
            'F183' => $this->cell(false, true, true, '=SUM(AD183+AF183)'),
        ]);

        $result = $this->isLeadingTotal('A09', 'G', 183, ['filaInicioDatos' => 177, 'fields' => [
            ['letra' => 'C', 'label' => 'PROGRAMA - ACTIVIDAD', 'esControlOculto' => false],
            ['letra' => 'D', 'label' => 'TOTAL', 'esControlOculto' => false],
            ['letra' => 'F', 'label' => 'TOTAL', 'esControlOculto' => false],
        ]]);

        $this->assertFalse($result, 'sin ninguna referencia estrictamente posterior en ninguna columna, no hay evidencia de TOTAL lider');
    }

    /**
     * classifyRow() end-to-end: fila 183 debe devolver 'total', no 'data'
     * -- confirma que classifyRow() efectivamente invoca el nuevo
     * mecanismo y que su resultado se propaga.
     */
    public function test_classify_row_returns_total_for_a09_g_fila_183(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('A09', 'G', [
            'C183' => $this->cell(false, true, false, null, 'TOTAL'),
            'D183' => $this->cell(false, true, true, '=SUM(D184:D189)'),
        ]);

        $result = $this->classifyRow('A09', 'G', 183, 176, [
            'filaInicioDatos' => 177,
            'filaFinDatos' => 227,
            'fields' => $this->fields(),
        ]);

        $this->assertSame('total', $result);
    }

    /**
     * Caso negativo real: una fila de dato normal (ej. patron A09/G P1,
     * fila 179 "N°Auditorías") sin ninguna etiqueta TOTAL, con celdas
     * editables reales -- nunca debe excluirse, debe seguir clasificandose
     * 'data'.
     */
    public function test_normal_data_row_without_total_label_is_not_classified_as_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('A09', 'G', [
            'B179' => $this->cell(false, true, false, null, 'N°Auditorías'),
            'D179' => $this->cell(true, false, false, null, null),
        ]);

        $result = $this->isLeadingTotal('A09', 'G', 179, ['filaInicioDatos' => 177, 'fields' => $this->fields()]);

        $this->assertFalse($result, 'fila de dato real capturable nunca debe clasificarse como total lider');

        $classified = $this->classifyRow('A09', 'G', 179, 176, [
            'filaInicioDatos' => 177,
            'filaFinDatos' => 227,
            'fields' => $this->fields(),
        ]);
        $this->assertSame('data', $classified);
    }

    /**
     * Caso negativo: fila con etiqueta "TOTAL" pero SIN ninguna formula
     * (fila inerte, ej. patron A09/G filas 190/191/199/200 "Total
     * Egresos/Altas" -- 100% bloqueada, cero formula, cero editable). Sin
     * evidencia de formula hacia adelante, no debe excluirse -- permanece
     * como 'data' (inerte, pero no un TOTAL lider funcional).
     */
    public function test_total_label_without_any_formula_is_not_classified_as_leading_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('A09', 'G', [
            'C190' => $this->cell(false, true, false, null, 'Total Egresos/Altas'),
            'D190' => $this->cell(false, true, false, null, null),
        ]);

        $result = $this->isLeadingTotal('A09', 'G', 190, ['filaInicioDatos' => 177, 'fields' => $this->fields()]);

        $this->assertFalse($result, 'fila inerte sin formula no es un TOTAL lider aunque su etiqueta contenga "TOTAL"');
    }

    /**
     * Caso negativo: formula que referencia una fila ANTERIOR (o la propia
     * fila) -- no es un TOTAL lider (seria backward, mecanismo #12, o
     * neutral). No debe clasificarse como total lider.
     */
    public function test_formula_referencing_prior_row_is_not_classified_as_leading_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('A09', 'G', [
            'C210' => $this->cell(false, true, false, null, 'TOTAL'),
            'D210' => $this->cell(false, true, true, '=SUM(D205:D209)'),
        ]);

        $result = $this->isLeadingTotal('A09', 'G', 210, ['filaInicioDatos' => 177, 'fields' => $this->fields()]);

        $this->assertFalse($result);
    }

    /**
     * Caso negativo: alguna celda de la fila es genuinamente capturable
     * (editable, no bloqueada) pese a que otra celda tenga formula hacia
     * adelante y etiqueta TOTAL -- se trata como dato real, nunca se
     * excluye.
     */
    public function test_row_with_real_editable_cell_is_never_classified_as_leading_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('A09', 'G', [
            'C220' => $this->cell(false, true, false, null, 'TOTAL'),
            'D220' => $this->cell(false, true, true, '=SUM(D221:D223)'),
            'A220' => $this->cell(true, false, false, null, null),
        ]);

        $result = $this->isLeadingTotal('A09', 'G', 220, ['filaInicioDatos' => 177, 'fields' => [
            ['letra' => 'A', 'label' => 'Editable', 'esControlOculto' => false],
            ...$this->fields(),
        ]]);

        $this->assertFalse($result);
    }

    /**
     * Regresion: el mecanismo #12 (backward) sigue funcionando sin cambios
     * tras agregar el mecanismo #6 -- misma fixture que el test existente
     * de isEmbeddedBackwardSubtotalRow (A26/A.1 fila 41).
     */
    public function test_backward_subtotal_mechanism_still_works_after_adding_leading_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('A26', 'A.1', [
            'A41' => $this->cell(false, true, false, null, 'TOTAL'),
            'C41' => $this->cell(false, true, true, '=SUM(C38:C40)'),
        ]);

        $backward = $this->isBackwardSubtotal('A26', 'A.1', 41, ['filaInicioDatos' => 38, 'fields' => $this->fields()]);
        $leading = $this->isLeadingTotal('A26', 'A.1', 41, ['filaInicioDatos' => 38, 'fields' => $this->fields()]);

        $this->assertTrue($backward, 'el mecanismo #12 debe seguir detectando el subtotal hacia atras');
        $this->assertFalse($leading, 'una fila backward no debe tambien detectarse como leading (son mutuamente excluyentes por construccion)');
    }

    /**
     * Regresion: un TOTAL lider real (mecanismo #6) nunca debe disparar
     * tambien el mecanismo #12 (backward).
     */
    public function test_leading_total_mechanism_does_not_trigger_backward_mechanism(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('A09', 'G', [
            'C183' => $this->cell(false, true, false, null, 'TOTAL'),
            'D183' => $this->cell(false, true, true, '=SUM(D184:D189)'),
        ]);

        $leading = $this->isLeadingTotal('A09', 'G', 183, ['filaInicioDatos' => 177, 'fields' => $this->fields()]);
        $backward = $this->isBackwardSubtotal('A09', 'G', 183, ['filaInicioDatos' => 177, 'fields' => $this->fields()]);

        $this->assertTrue($leading);
        $this->assertFalse($backward, 'una fila leading no debe tambien detectarse como backward');
    }
}
