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
     * Expone el mecanismo #6 (TOTAL lider embebido,
     * SectionCalibrationMatrixService::isEmbeddedLeadingTotalRow()) a
     * traves del scanner -- unico punto de entrada usado por el flujo
     * structural_row_exclusion (RuleTagMismatchResolutionCommand) para
     * verificar, fila por fila, que cada fila que un tag dice "excluir"
     * cumple REALMENTE el mecanismo, en vez de asumirlo porque esta ausente
     * del conjunto vivo.
     */
    public function isEmbeddedLeadingTotalRow(string $sheet, string $section, int $row, array $sectionData): bool
    {
        return $this->matrixService->isEmbeddedLeadingTotalRow($sheet, $section, $row, $sectionData);
    }

    /**
     * Expone el mecanismo #12 (subtotal embebido hacia atras,
     * SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()) a
     * traves del scanner -- mismo patron exacto que isEmbeddedLeadingTotalRow()
     * (mecanismo #6) de arriba, agregado para que structural_row_exclusion
     * (RuleTagMismatchResolutionCommand/CalibrationViewController) pueda
     * verificar, fila por fila, evidencia real de mecanismo #12 ademas de #6
     * (2026-08-28, auditoria SAFE_TO_EXTEND_STRUCTURAL_ROW_EXCLUSION_TO_12).
     */
    public function isEmbeddedBackwardSubtotalRow(string $sheet, string $section, int $row, array $sectionData): bool
    {
        return $this->matrixService->isEmbeddedBackwardSubtotalRow($sheet, $section, $row, $sectionData);
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

        $identity = $this->matchLivePatternsToHistorical($matrix['patterns'] ?? [], $byPatternId);
        $matchedHistoricalPid = $identity['matches'];

        $patternResults = [];
        foreach ($matrix['patterns'] ?? [] as $pattern) {
            // $pid es el identificador VIVO (posicional, tal como lo ve/clickea
            // el usuario en la UI y tal como lo recibe rule:tag-mismatch-resolution
            // -- nunca cambia el "nombre" del patron mostrado). Las preguntas
            // historicas asociadas ($patternQs), en cambio, se resuelven por
            // IDENTIDAD DE CONTENIDO via matchLivePatternsToHistorical(), no por
            // este mismo numero -- ver docblock de ese metodo para la causa raiz.
            $pid = $pattern['id'];
            $matchedPid = $matchedHistoricalPid[$pid] ?? null;
            $patternQs = $matchedPid !== null ? ($byPatternId[$matchedPid] ?? []) : [];
            $liveRows = $pattern['filas'];
            $liveFingerprint = $pattern['canonical_fingerprint'] ?? null;

            if (empty($patternQs)) {
                $patternResults[] = [
                    'pattern_id' => $pid, 'category' => PatternReconciliationService::MIGRATION_FULL_REVALIDATION,
                    'live_canonical_fingerprint' => $liveFingerprint, 'live_rows' => $liveRows,
                    'already_v2_matching' => false, 'question_count' => 0,
                    // Sin match por identidad (nuevo, o ambiguo/split/merge --
                    // ver matchLivePatternsToHistorical()): no hay filas
                    // historicas que ofrecer, nunca se adivina.
                    'historical_rows' => null,
                    'historical_pattern_id' => null,
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
                    // Hallazgo 2026-08-21 (verificacion UI del flujo MISMATCH):
                    // el path legacy ya exponia esto para mostrar "decision
                    // anterior" en el panel -- el path canonico (v2) nunca lo
                    // seteaba, dejando la decision historica real invisible
                    // para practicamente todos los MISMATCH reales de la
                    // campaña (todos v2). Solo lectura, mismo criterio que el
                    // path legacy, nunca participa de la clasificacion.
                    'historical_answer' => $this->summarizeHistoricalAnswer($patternQs),
                    // Filas del patron historico REALMENTE emparejado por
                    // identidad ($patternQs, resuelto arriba via
                    // matchLivePatternsToHistorical()) -- nunca del pattern_id
                    // crudo/posicional. Consumido por
                    // RuleTagMismatchResolutionCommand para el gate de
                    // seguridad "filas vivas == filas historicas" del
                    // safe_reconfirm (hallazgo 2026-08-24: antes de esto, el
                    // comando hacia su propio lookup independiente por
                    // pattern_id crudo, rompiendo el gate exactamente en los
                    // casos donde el pattern_id vivo se desplazo).
                    'historical_rows' => $this->historicalRowsForPatternQuestions($patternQs),
                    // pattern_id CRUDO/historico (almacenado en las preguntas
                    // emparejadas, $matchedPid) -- distinto de $pid (vivo,
                    // posicional). Hallazgo 2026-08-24 (corrupcion real A09/G
                    // P3): FunctionalRuleService::applyQuickRevalidation()
                    // escribia filtrando por el pattern_id VIVO, no por este.
                    // Cuando un patron se desplaza de posicion (ej. tras
                    // excluir una fila TOTAL lider), esa escritura terminaba
                    // sobrescribiendo las preguntas de un patron historico
                    // TOTALMENTE DISTINTO que por coincidencia comparte el
                    // mismo numero posicional hoy. CalibrationViewController
                    // debe usar SIEMPRE este campo (nunca pattern_id) como
                    // argumento de escritura hacia applyQuickRevalidation().
                    'historical_pattern_id' => $matchedPid,
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
                // Ver comentario extenso arriba (rama v2) -- misma razon,
                // mismo campo, tambien necesario para la rama legacy.
                'historical_pattern_id' => $matchedPid,
            ];
        }

        return [
            'sheet' => $sheet, 'code' => $code,
            'category' => $this->mostConservativeCategory(array_column($patternResults, 'category')),
            'patterns' => $patternResults,
            'column_diff' => $columnDiff,
            // Diagnostico de matchLivePatternsToHistorical(): pattern_id
            // historicos (reglas-funcionales.json) sin ningun patron vivo
            // correspondiente -- normal cuando una fila (ej. TOTAL lider)
            // sale por completo del conjunto vivo. Nunca participa de la
            // clasificacion, solo visibilidad/auditoria.
            'orphaned_historical_pattern_ids' => $identity['orphaned_historical_pattern_ids'],
        ];
    }

    /**
     * Empareja patrones VIVOS (identificados por su conjunto de filas,
     * $pattern['filas']) con grupos de preguntas HISTORICAS (agrupadas por
     * su pattern_id almacenado en reglas-funcionales.json) usando
     * IDENTIDAD DE CONTENIDO en vez de posicion.
     *
     * Causa raiz (auditoria 2026-08-24, hallazgo A05/C, A05/G, A19b/A):
     * pattern_id es puramente secuencial -- buildDynamicPatternDefinitions()
     * asigna $id=1,2,3... en el orden en que cada grupo de filas aparece
     * por primera vez (ver SectionCalibrationMatrixService). Antes de este
     * metodo, scanSection() empataba directamente `$byPatternId[$pattern['id']]`
     * -- si un patron completo desaparece del conjunto vivo (ej. una fila
     * TOTAL lider que hoy es unico miembro de su propio patron, ver
     * mecanismo #6 recien conectado en classifyRow()), TODOS los pattern_id
     * posteriores se corren una posicion, y scanSection() terminaba
     * comparando filas reales sin ningun cambio funcional contra metadata
     * historica de un patron completamente distinto -- MISMATCH artificial,
     * y en el peor caso, "patron no encontrado" para preguntas historicas
     * huerfanas cuyo id ya no lo ocupa nadie.
     *
     * Estrategia en fases, cada una estrictamente mas laxa que la anterior.
     * NINGUNA fase "adivina": un candidato solo se compromete si es
     * inequivoco en AMBOS sentidos (el patron vivo tiene un unico candidato
     * historico Y ese patron historico tiene un unico candidato vivo). Si
     * hay mas de un candidato de cualquier lado (split: un historico se
     * dividio en varios vivos; merge: varios historicos se fusionaron en
     * uno vivo) NINGUNO de esos candidatos se compromete -- el patron vivo
     * queda sin pareja historica, cae naturalmente a FULL_REVALIDATION en
     * scanSection() (mismo camino que ya usa hoy para "patron nuevo, sin
     * ninguna pregunta historica"), nunca se elige arbitrariamente uno.
     *
     *  1. Coincidencia EXACTA de conjunto de filas -- cubre el caso mas
     *     comun (sin cambios) y tambien el shift puro P2->P1 sin ningun
     *     cambio de contenido (A05/G: vivo [112,113,114] == historico P2).
     *  2. Subconjunto/superconjunto UNICO -- un patron se encogio o crecio
     *     pero sigue habiendo una correspondencia 1:1 (A09/G P2 historico
     *     [183,190,191] -> vivo [190,191] tras excluir el TOTAL lider 183).
     *  3. Overlap Jaccard >=50% UNICO -- red de seguridad adicional para
     *     variaciones menores que no son subconjunto/superconjunto exacto.
     *
     * @param array $livePatterns Patrones vivos, cada uno con 'id' (int) y 'filas' (array<int>).
     * @param array<int, array> $historicalPatternsByPid Preguntas historicas agrupadas por pattern_id.
     * @return array{matches: array<int,int>, orphaned_historical_pattern_ids: array<int>}
     */
    public function matchLivePatternsToHistorical(array $livePatterns, array $historicalPatternsByPid): array
    {
        $liveRowSets = [];
        foreach ($livePatterns as $pattern) {
            $liveRowSets[$pattern['id']] = $this->normalizeRows($pattern['filas'] ?? []);
        }

        $histRowSets = [];
        foreach ($historicalPatternsByPid as $pid => $questions) {
            $rows = $this->historicalRowsForPatternQuestions($questions);
            if ($rows !== null) {
                $histRowSets[$pid] = $this->normalizeRows($rows);
            }
        }

        $matches = [];
        // $usedHist/$usedLive: SOLO pares realmente comprometidos (matches) --
        // gobiernan el calculo final de orphaned_historical_pattern_ids.
        $usedHist = [];
        $usedLive = [];
        // $excludedHist/$excludedLive: comprometidos + AMBIGUOS ya vistos en
        // una fase anterior mas estricta -- gobiernan que candidatos se
        // consideran en las fases siguientes. Sin esta distincion, un
        // historico marcado ambiguo por la fase 2 (subconjunto, split real)
        // podia volver a evaluarse en la fase 3 (overlap, umbral mas laxo)
        // y matchear por casualidad con SOLO UNO de sus varios candidatos si
        // el overlap resultaba asimetrico -- exactamente el "adivinar"
        // prohibido. Una vez que una fase encuentra ambiguedad real, ese
        // patron (de cualquier lado) queda excluido de fases posteriores,
        // nunca "se resuelve solo" con un criterio mas laxo.
        $excludedHist = [];
        $excludedLive = [];

        // Fase 1: coincidencia exacta.
        foreach ($liveRowSets as $liveId => $liveRows) {
            if (empty($liveRows)) {
                continue;
            }
            foreach ($histRowSets as $pid => $histRows) {
                if (isset($excludedHist[$pid])) {
                    continue;
                }
                if ($liveRows === $histRows) {
                    $matches[$liveId] = $pid;
                    $usedHist[$pid] = true;
                    $usedLive[$liveId] = true;
                    $excludedHist[$pid] = true;
                    $excludedLive[$liveId] = true;

                    break;
                }
            }
        }

        // Fase 2: subconjunto/superconjunto, solo si es inequivoco en ambos sentidos.
        $this->matchByCandidateRule(
            $liveRowSets, $histRowSets, $excludedLive, $excludedHist, $usedLive, $usedHist, $matches,
            fn (array $liveRows, array $histRows) => $this->isSubsetOrSuperset($liveRows, $histRows)
        );

        // Fase 3: overlap Jaccard >=50%, solo si es inequivoco en ambos sentidos.
        // Los patrones que la fase 2 marco ambiguos (split/merge real) ya
        // estan en $excludedLive/$excludedHist y NUNCA vuelven a
        // considerarse aqui, aunque el overlap parezca resolverlos.
        $this->matchByCandidateRule(
            $liveRowSets, $histRowSets, $excludedLive, $excludedHist, $usedLive, $usedHist, $matches,
            fn (array $liveRows, array $histRows) => $this->jaccardOverlap($liveRows, $histRows) >= 0.5
        );

        $orphanedHistorical = array_values(array_diff(array_keys($histRowSets), array_keys($usedHist)));

        return [
            'matches' => $matches,
            'orphaned_historical_pattern_ids' => $orphanedHistorical,
        ];
    }

    /**
     * Aplica una regla de candidatura (subconjunto/superconjunto u overlap)
     * y compromete SOLO los pares inequivocos (un candidato en cada
     * sentido) -- mutación in-place de $usedLive/$usedHist/$matches (pares
     * REALMENTE comprometidos) y $excludedLive/$excludedHist (comprometidos
     * + ambiguos, para que fases posteriores nunca reconsideren un caso que
     * esta fase ya determino ambiguo). Mismo patron usado por las fases 2 y
     * 3 de matchLivePatternsToHistorical().
     */
    private function matchByCandidateRule(array $liveRowSets, array $histRowSets, array &$excludedLive, array &$excludedHist, array &$usedLive, array &$usedHist, array &$matches, callable $isCandidate): void
    {
        $candidatesByLive = [];
        $candidatesByHist = [];
        foreach ($liveRowSets as $liveId => $liveRows) {
            if (isset($excludedLive[$liveId]) || empty($liveRows)) {
                continue;
            }
            foreach ($histRowSets as $pid => $histRows) {
                if (isset($excludedHist[$pid])) {
                    continue;
                }
                if ($isCandidate($liveRows, $histRows)) {
                    $candidatesByLive[$liveId][] = $pid;
                    $candidatesByHist[$pid][] = $liveId;
                }
            }
        }

        foreach ($candidatesByLive as $liveId => $pids) {
            if (count($pids) !== 1) {
                // ambiguo del lado vivo (un patron vivo que superpone a mas de
                // un historico) -- excluido de aqui en adelante, nunca matcheado.
                $excludedLive[$liveId] = true;
                foreach ($pids as $pid) {
                    $excludedHist[$pid] = true;
                }

                continue;
            }
            $pid = $pids[0];
            if (count($candidatesByHist[$pid] ?? []) !== 1) {
                // ambiguo del lado historico (split: mas de un vivo candidatea
                // al mismo historico) -- excluido de aqui en adelante.
                $excludedHist[$pid] = true;
                foreach ($candidatesByHist[$pid] as $otherLiveId) {
                    $excludedLive[$otherLiveId] = true;
                }

                continue;
            }
            $matches[$liveId] = $pid;
            $usedHist[$pid] = true;
            $usedLive[$liveId] = true;
            $excludedHist[$pid] = true;
            $excludedLive[$liveId] = true;
        }
    }

    private function normalizeRows(array $rows): array
    {
        $normalized = array_values(array_unique(array_map('intval', $rows)));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function isSubsetOrSuperset(array $a, array $b): bool
    {
        if (empty($a) || empty($b)) {
            return false;
        }

        return empty(array_diff($a, $b)) || empty(array_diff($b, $a));
    }

    private function jaccardOverlap(array $a, array $b): float
    {
        if (empty($a) || empty($b)) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Filas de un patron historico a partir de su grupo de preguntas --
     * prioriza el campo v2 canonico pattern_rows (ya normalizado, la fuente
     * mas confiable); cae a extractRowsFromQuestionText() (parseo del texto
     * "Patron N: 12,13,14") solo si ninguna pregunta v2 lo tiene, mismo
     * fallback ya usado en el resto de esta clase para preguntas legacy.
     */
    private function historicalRowsForPatternQuestions(array $questions): ?array
    {
        foreach ($questions as $q) {
            if (! empty($q['pattern_rows'])) {
                return $q['pattern_rows'];
            }
        }

        foreach ($questions as $q) {
            $rows = $this->extractRowsFromQuestionText($q['question'] ?? '');
            if ($rows !== null) {
                return $rows;
            }
        }

        return null;
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
