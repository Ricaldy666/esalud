<?php

namespace Tests\Unit\RuleEngine\Services;

use App\Domain\RuleEngine\Services\CertificationService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\PatternMigrationScanner;
use App\Domain\RuleEngine\Services\PatternReconciliationService;
use App\Domain\RuleEngine\Services\RemSheetUsageStatusService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use PHPUnit\Framework\TestCase;

/**
 * Cubre PatternMigrationScanner::matchLivePatternsToHistorical() --
 * emparejamiento de patrones vivos con preguntas historicas por identidad
 * de CONTENIDO (conjunto de filas), no por pattern_id posicional.
 *
 * Causa raiz (auditoria 2026-08-24): buildDynamicPatternDefinitions()
 * asigna pattern_id secuencialmente segun orden de deteccion; si un patron
 * completo desaparece del conjunto vivo (ej. fila TOTAL lider aislada,
 * mecanismo #6), todos los pattern_id posteriores se corren, y el
 * emparejamiento posicional anterior comparaba filas reales sin ningun
 * cambio funcional contra metadata historica de un patron distinto.
 *
 * 100% pure -- no toca base de datos, disco ni cell-data (no requiere
 * RefreshDatabase). El metodo bajo prueba solo recibe/devuelve arrays.
 */
class PatternMigrationScannerIdentityMatchingTest extends TestCase
{
    private function scanner(): PatternMigrationScanner
    {
        return new PatternMigrationScanner(
            new SectionCalibrationMatrixService(
                new CertificationService(),
                new FunctionalRuleService(),
                new \App\Domain\RuleEngine\Services\CellDataStorageService(),
                new \App\Domain\REM\Services\ColumnRoleResolverService(),
            ),
            new FunctionalRuleService(),
            new PatternReconciliationService(),
            new RemSheetUsageStatusService(),
        );
    }

    private function livePattern(int $id, array $rows): array
    {
        return ['id' => $id, 'filas' => $rows];
    }

    private function historicalQuestion(array $rows, string $question = ''): array
    {
        return ['pattern_rows' => $rows, 'question' => $question];
    }

    /**
     * Caso A05/G: historico P1=[111] (TOTAL lider, excluido del vivo),
     * P2=[112,113,114]. Vivo (tras aplicar el mecanismo #6): un unico
     * patron, id=1, filas=[112,113,114] -- NO debe emparejarse con el
     * historico P1 por posicion; debe reconocerse como el mismo P2 de
     * siempre por coincidencia EXACTA de filas.
     */
    public function test_a05_g_shift_matches_by_exact_rows_not_position(): void
    {
        $live = [$this->livePattern(1, [112, 113, 114])];
        $historical = [
            1 => [$this->historicalQuestion([111])],
            2 => [$this->historicalQuestion([112, 113, 114])],
        ];

        $result = $this->scanner()->matchLivePatternsToHistorical($live, $historical);

        $this->assertSame(2, $result['matches'][1] ?? null, 'el patron vivo id=1 debe emparejarse con el historico P2 (mismas filas), no con P1 por posicion');
        $this->assertSame([1], $result['orphaned_historical_pattern_ids'], 'P1 (fila 111, TOTAL lider excluida) queda huerfano, no se descarta silenciosamente sin reportar');
    }

    /**
     * Caso A05/C: historico P1=[35] (TOTAL), P2=[36..44,47..51], P3=[45,46].
     * Vivo tras excluir 35: id=1=[36..44,47..51], id=2=[45,46]. Ambos deben
     * emparejarse con SU historico correcto (P2 y P3 respectivamente), no
     * desplazarse.
     */
    public function test_a05_c_multiple_shifted_patterns_match_correctly(): void
    {
        $p2Rows = [36, 37, 38, 39, 40, 41, 42, 43, 44, 47, 48, 49, 50, 51];
        $live = [
            $this->livePattern(1, $p2Rows),
            $this->livePattern(2, [45, 46]),
        ];
        $historical = [
            1 => [$this->historicalQuestion([35])],
            2 => [$this->historicalQuestion($p2Rows)],
            3 => [$this->historicalQuestion([45, 46])],
        ];

        $result = $this->scanner()->matchLivePatternsToHistorical($live, $historical);

        $this->assertSame(2, $result['matches'][1] ?? null);
        $this->assertSame(3, $result['matches'][2] ?? null);
        $this->assertSame([1], $result['orphaned_historical_pattern_ids']);
    }

    /**
     * Caso A09/G: los patrones NO desaparecen, solo se encogen (la fila
     * TOTAL lider sale, pero quedan otras filas). Debe seguir
     * identificandose como el mismo patron via subconjunto unico (fase 2).
     */
    public function test_a09_g_shrinking_pattern_matches_via_subset(): void
    {
        // P2 historico = [183,190,191] (183 = TOTAL lider); vivo tras excluirla = [190,191].
        $live = [$this->livePattern(1, [190, 191])];
        $historical = [
            2 => [$this->historicalQuestion([183, 190, 191])],
        ];

        $result = $this->scanner()->matchLivePatternsToHistorical($live, $historical);

        $this->assertSame(2, $result['matches'][1] ?? null, '[190,191] es subconjunto unico de [183,190,191] -- debe matchear con P2');
        $this->assertSame([], $result['orphaned_historical_pattern_ids']);
    }

    /**
     * Caso sin cambios: filas identicas, IDs identicos -- debe matchear
     * trivialmente sin ninguna sorpresa (mismo comportamiento que el
     * sistema anterior para el caso comun).
     */
    public function test_unchanged_section_matches_trivially(): void
    {
        $live = [
            $this->livePattern(1, [10, 11]),
            $this->livePattern(2, [12, 13, 14]),
        ];
        $historical = [
            1 => [$this->historicalQuestion([10, 11])],
            2 => [$this->historicalQuestion([12, 13, 14])],
        ];

        $result = $this->scanner()->matchLivePatternsToHistorical($live, $historical);

        $this->assertSame(1, $result['matches'][1] ?? null);
        $this->assertSame(2, $result['matches'][2] ?? null);
        $this->assertSame([], $result['orphaned_historical_pattern_ids']);
    }

    /**
     * Caso realmente nuevo: un patron vivo cuyas filas nunca existieron en
     * ningun patron historico -- NO debe emparejarse artificialmente con
     * nada (queda sin match, cae a FULL_REVALIDATION en scanSection()).
     */
    public function test_genuinely_new_pattern_is_not_artificially_matched(): void
    {
        $live = [
            $this->livePattern(1, [10, 11]),
            $this->livePattern(2, [500, 501]), // patron nuevo, sin relacion con nada historico
        ];
        $historical = [
            1 => [$this->historicalQuestion([10, 11])],
        ];

        $result = $this->scanner()->matchLivePatternsToHistorical($live, $historical);

        $this->assertSame(1, $result['matches'][1] ?? null);
        $this->assertArrayNotHasKey(2, $result['matches'], 'patron sin ninguna evidencia historica no debe matchear con nada');
    }

    /**
     * Caso SPLIT: un patron historico [36..51] ahora aparece dividido en
     * dos patrones vivos disjuntos [36..44] y [47..51] (ambos subconjuntos
     * del mismo historico). Ninguno de los dos debe comprometerse --
     * ambiguedad real, no se "adivina" cual es la continuacion legitima.
     */
    public function test_split_pattern_is_not_guessed(): void
    {
        $live = [
            $this->livePattern(1, [36, 37, 38, 39, 40, 41, 42, 43, 44]),
            $this->livePattern(2, [47, 48, 49, 50, 51]),
        ];
        $historical = [
            2 => [$this->historicalQuestion([36, 37, 38, 39, 40, 41, 42, 43, 44, 47, 48, 49, 50, 51])],
        ];

        $result = $this->scanner()->matchLivePatternsToHistorical($live, $historical);

        $this->assertArrayNotHasKey(1, $result['matches'], 'split: ninguno de los dos candidatos debe comprometerse');
        $this->assertArrayNotHasKey(2, $result['matches']);
        $this->assertSame([2], $result['orphaned_historical_pattern_ids'], 'el historico dividido queda huerfano, visible para auditoria, no descartado en silencio');
    }

    /**
     * Caso MERGE: dos patrones historicos distintos ([10,11] y [12,13]) hoy
     * aparecen fusionados en un unico patron vivo [10,11,12,13]. Ninguno de
     * los dos historicos debe comprometerse con el vivo fusionado.
     */
    public function test_merge_pattern_is_not_guessed(): void
    {
        $live = [$this->livePattern(1, [10, 11, 12, 13])];
        $historical = [
            1 => [$this->historicalQuestion([10, 11])],
            2 => [$this->historicalQuestion([12, 13])],
        ];

        $result = $this->scanner()->matchLivePatternsToHistorical($live, $historical);

        $this->assertArrayNotHasKey(1, $result['matches'], 'merge: el patron vivo fusionado no debe adoptar ninguno de los dos historicos por adivinanza');
        $this->assertEqualsCanonicalizing([1, 2], $result['orphaned_historical_pattern_ids']);
    }

    /**
     * Caso overlap mayoritario (fase 3): un patron vivo perdio/gano una
     * fila suelta respecto de su historico (no es subconjunto/superconjunto
     * exacto), pero comparten >=50% de filas y son candidatos unicos en
     * ambos sentidos -- debe matchear igual.
     */
    public function test_majority_overlap_matches_when_unique(): void
    {
        // vivo [10,11,12] vs historico [10,11,13] -- interseccion {10,11}, union {10,11,12,13} = 0.5
        $live = [$this->livePattern(1, [10, 11, 12])];
        $historical = [
            5 => [$this->historicalQuestion([10, 11, 13])],
        ];

        $result = $this->scanner()->matchLivePatternsToHistorical($live, $historical);

        $this->assertSame(5, $result['matches'][1] ?? null);
    }

    /**
     * Caso negativo: overlap insuficiente (<50%) -- no debe matchear ni
     * siquiera como ultimo recurso.
     */
    public function test_insufficient_overlap_does_not_match(): void
    {
        // interseccion {10}, union {10,11,12,13,14} = 0.2
        $live = [$this->livePattern(1, [10, 11, 12])];
        $historical = [
            5 => [$this->historicalQuestion([10, 13, 14])],
        ];

        $result = $this->scanner()->matchLivePatternsToHistorical($live, $historical);

        $this->assertArrayNotHasKey(1, $result['matches']);
    }
}
