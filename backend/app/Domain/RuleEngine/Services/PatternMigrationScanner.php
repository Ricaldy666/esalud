<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;

/**
 * Fase 5/8A (deuda tecnica #1, 2026-08-12): unica fuente de verdad para
 * clasificar, SECCION POR SECCION Y PATRON POR PATRON, en que estado de
 * migracion al fingerprint canonico v2 se encuentra cada seccion real de
 * la estructura activa. Extraida de RemSimulateFingerprintMigrationCommand
 * (Fase 5) para que RemMigrateAutoFingerprintsCommand (Fase 8A) reutilice
 * exactamente el mismo criterio -- nunca una lista de IDs precalculada,
 * siempre reclasificado en vivo contra el estado actual.
 *
 * 100% lectura -- no escribe nada en ningun momento.
 */
class PatternMigrationScanner
{
    public function __construct(
        private SectionCalibrationMatrixService $matrixService,
        private FunctionalRuleService $functionalRuleService,
        private PatternReconciliationService $reconciler,
        private RemSheetUsageStatusService $sheetUsageStatus,
    ) {
    }

    /**
     * @return array<string, array{sheet:string, code:string, category:string, patterns:array}>
     */
    public function scanAllSections(RemTemplateStructure $activeStructure): array
    {
        $estructura = is_string($activeStructure->estructura)
            ? json_decode($activeStructure->estructura, true)
            : $activeStructure->estructura;

        $result = [];
        $noUtilizadaCache = [];

        foreach ($estructura['forms'] ?? [] as $form) {
            $sheet = (string) ($form['sheetName'] ?? '');
            if ($sheet === '') {
                continue;
            }
            $sheetUpper = strtoupper($sheet);

            if (! array_key_exists($sheetUpper, $noUtilizadaCache)) {
                $noUtilizadaCache[$sheetUpper] = $this->sheetUsageStatus->getStatusFor(
                    (int) $activeStructure->anio,
                    $activeStructure->serie,
                    $sheet
                ) === RemSheetUsageStatusService::STATUS_NO_UTILIZADA;
            }

            foreach ($form['sections'] ?? [] as $sectionDecl) {
                $code = (string) ($sectionDecl['codigo'] ?? '');
                if ($code === '') {
                    continue;
                }

                $key = "{$sheet}_{$code}";
                $result[$key] = $this->scanSection($activeStructure, $sheet, $code, $sectionDecl, $noUtilizadaCache[$sheetUpper]);
            }
        }

        return $result;
    }

    /**
     * Reclasifica UNA seccion contra el estado vigente -- usado tanto por
     * el barrido completo como por la reverificacion inmediatamente antes
     * de persistir en el comando de migracion (nunca confia en un
     * resultado calculado antes).
     */
    public function scanSection(RemTemplateStructure $activeStructure, string $sheet, string $code, array $sectionDecl, ?bool $noUtilizadaOverride = null): array
    {
        $key = "{$sheet}_{$code}";
        $noUtilizada = $noUtilizadaOverride ?? ($this->sheetUsageStatus->getStatusFor(
            (int) $activeStructure->anio,
            $activeStructure->serie,
            $sheet
        ) === RemSheetUsageStatusService::STATUS_NO_UTILIZADA);

        if ($noUtilizada) {
            return ['sheet' => $sheet, 'code' => $code, 'category' => 'NO_UTILIZADA', 'patterns' => []];
        }

        $questions = $this->functionalRuleService->getQuestions($sheet, $code);
        $sectionReview = null;
        foreach ($questions as $q) {
            if (($q['type'] ?? '') === 'section_review') {
                $sectionReview = $q;
            }
        }

        if ($sectionReview === null) {
            return ['sheet' => $sheet, 'code' => $code, 'category' => 'NEW_SECTION', 'patterns' => []];
        }

        if (($sectionReview['response'] ?? '') === 'no_calibrable') {
            return ['sheet' => $sheet, 'code' => $code, 'category' => 'NOT_CALIBRATABLE', 'patterns' => []];
        }

        if (($sectionReview['review_status'] ?? '') !== 'section_reviewed') {
            return ['sheet' => $sheet, 'code' => $code, 'category' => PatternReconciliationService::MIGRATION_FULL_REVALIDATION, 'patterns' => []];
        }

        $patternQuestions = array_values(array_filter(
            $questions,
            fn ($q) => in_array($q['type'] ?? '', ['pattern_question', 'pattern_confirmation'], true)
        ));
        if (empty($patternQuestions)) {
            return ['sheet' => $sheet, 'code' => $code, 'category' => PatternReconciliationService::MIGRATION_FULL_REVALIDATION, 'patterns' => []];
        }

        $byPatternId = [];
        foreach ($patternQuestions as $q) {
            $byPatternId[$q['pattern_id']][] = $q;
        }

        $histStructVersion = null;
        foreach ($patternQuestions as $q) {
            if (! empty($q['structure_version'])) {
                $histStructVersion = (int) $q['structure_version'];

                break;
            }
        }
        $histSectionDecl = $this->findHistoricalSectionDeclaration($histStructVersion, $sheet, $code);
        $structureIdentical = $histSectionDecl === null ? null : (json_encode($histSectionDecl) === json_encode($sectionDecl));
        $columnDiff = $this->diffColumns($histSectionDecl, $sectionDecl);

        $matrix = $this->matrixService->buildPatternMatrix($sheet, $code);
        $this->matrixService->forgetSectionCache($sheet, $code);

        $patternResults = [];
        foreach ($matrix['patterns'] ?? [] as $pattern) {
            $pid = $pattern['id'];
            $patternQs = $byPatternId[$pid] ?? [];
            $liveRows = $pattern['filas'];
            $liveFingerprint = $pattern['canonical_fingerprint'] ?? null;

            if (empty($patternQs)) {
                $patternResults[] = [
                    'pattern_id' => $pid, 'category' => PatternReconciliationService::MIGRATION_FULL_REVALIDATION,
                    'live_canonical_fingerprint' => $liveFingerprint, 'live_rows' => $liveRows,
                    'already_v2_matching' => false, 'question_count' => 0,
                ];

                continue;
            }

            $v2Question = null;
            foreach ($patternQs as $q) {
                if ($this->reconciler->resolveFingerprintVersion($q) === PatternReconciliationService::FINGERPRINT_VERSION_CANONICAL && ! empty($q['pattern_fingerprint'])) {
                    $v2Question = $q;

                    break;
                }
            }

            if ($v2Question !== null) {
                $agrees = $v2Question['pattern_fingerprint'] === $liveFingerprint;
                $patternResults[] = [
                    'pattern_id' => $pid,
                    'category' => $agrees ? PatternReconciliationService::MIGRATION_AUTO_MIGRATE : PatternReconciliationService::MIGRATION_MISMATCH,
                    'live_canonical_fingerprint' => $liveFingerprint, 'live_rows' => $liveRows,
                    'already_v2_matching' => $agrees, 'question_count' => count($patternQs),
                ];

                continue;
            }

            $historicalRows = $this->extractRowsFromQuestionText($patternQs[0]['question'] ?? '');
            $category = $this->reconciler->classifyLegacyPatternForMigration($historicalRows, $liveRows, $structureIdentical);

            $patternResults[] = [
                'pattern_id' => $pid, 'category' => $category,
                'live_canonical_fingerprint' => $liveFingerprint, 'live_rows' => $liveRows,
                'already_v2_matching' => false, 'question_count' => count($patternQs),
                // Resumen de la decision historica -- SOLO para mostrar en UI
                // (Fase 3), nunca se usa para decidir la clasificacion.
                'historical_answer' => $this->summarizeHistoricalAnswer($patternQs),
                'historical_rows' => $historicalRows,
            ];
        }

        return [
            'sheet' => $sheet, 'code' => $code,
            'category' => $this->mostConservativeCategory(array_column($patternResults, 'category')),
            'patterns' => $patternResults,
            'column_diff' => $columnDiff,
        ];
    }

    /**
     * Resumen minimo, en lenguaje simple, de la respuesta historica de un
     * patron -- consumido por QuickRevalidationPanel (Fase 3) para mostrar
     * "decision anterior / revisado por / fecha" sin exponer fingerprints
     * ni ids tecnicos en la vista simple.
     */
    private function summarizeHistoricalAnswer(array $patternQs): ?array
    {
        $primary = null;
        foreach ($patternQs as $q) {
            if (($q['type'] ?? '') === 'pattern_question' && ! empty($q['response'])) {
                $primary = $q;

                break;
            }
        }
        $primary ??= $patternQs[0] ?? null;
        if ($primary === null) {
            return null;
        }

        return [
            'response' => $primary['response'] ?? null,
            'reviewed_by' => $primary['reviewed_by'] ?? null,
            'reviewed_at' => $primary['reviewed_at'] ?? null,
        ];
    }

    /**
     * Diferencia de columnas declaradas entre la version historica y la
     * vigente -- solo letras agregadas/eliminadas, para la tarjeta "que
     * cambio" de QuickRevalidationPanel. No compara editabilidad/formulas
     * (eso vive en cell-data, no en la estructura declarada) -- esa
     * evidencia mas fina queda para "Ver evidencia tecnica" via
     * live_canonical_fingerprint, no se expande aqui deliberadamente.
     */
    private function diffColumns(?array $histSectionDecl, array $currentSectionDecl): array
    {
        if ($histSectionDecl === null) {
            return ['added' => [], 'removed' => [], 'unknown' => true];
        }

        $histLetters = array_map(fn ($f) => strtoupper((string) ($f['letra'] ?? '')), $histSectionDecl['fields'] ?? []);
        $currentLetters = array_map(fn ($f) => strtoupper((string) ($f['letra'] ?? '')), $currentSectionDecl['fields'] ?? []);

        return [
            'added' => array_values(array_diff($currentLetters, $histLetters)),
            'removed' => array_values(array_diff($histLetters, $currentLetters)),
            'unknown' => false,
        ];
    }

    private function findHistoricalSectionDeclaration(?int $histStructureId, string $sheet, string $code): ?array
    {
        if ($histStructureId === null) {
            return null;
        }

        $histStructure = RemTemplateStructure::withTrashed()->find($histStructureId);
        if (! $histStructure) {
            return null;
        }

        $histEst = is_string($histStructure->estructura) ? json_decode($histStructure->estructura, true) : $histStructure->estructura;
        $histSectionDecl = null;
        foreach ($histEst['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) === strtoupper($sheet)) {
                foreach ($form['sections'] ?? [] as $s) {
                    if (($s['codigo'] ?? null) === $code) {
                        $histSectionDecl = $s;
                    }
                }
            }
        }

        return $histSectionDecl;
    }

    public function extractRowsFromQuestionText(string $question): ?array
    {
        if (! preg_match('/\(Patr[oó]n\s+\d+:\s*([\d,\s]+)\)/u', $question, $m)) {
            return null;
        }

        $parts = array_map('trim', explode(',', $m[1]));
        $rows = [];
        foreach ($parts as $part) {
            if ($part === '' || ! ctype_digit($part)) {
                return null;
            }
            $rows[] = (int) $part;
        }

        return empty($rows) ? null : $rows;
    }

    /**
     * Cuando una seccion tiene varios patrones con categorias distintas, se
     * reporta la mas conservadora (la que exige mas trabajo humano) --
     * nunca se "promedia" ni se oculta un MISMATCH detras de otros patrones
     * en buen estado.
     */
    public function mostConservativeCategory(array $categories): string
    {
        $priority = [
            PatternReconciliationService::MIGRATION_MISMATCH => 4,
            PatternReconciliationService::MIGRATION_FULL_REVALIDATION => 3,
            PatternReconciliationService::MIGRATION_QUICK_CONFIRMATION => 2,
            PatternReconciliationService::MIGRATION_AUTO_MIGRATE => 1,
        ];

        $best = PatternReconciliationService::MIGRATION_AUTO_MIGRATE;
        $bestPriority = 1;
        foreach ($categories as $c) {
            $p = $priority[$c] ?? 3;
            if ($p > $bestPriority) {
                $bestPriority = $p;
                $best = $c;
            }
        }

        return $best;
    }
}
