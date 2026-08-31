<?php

namespace App\Domain\RuleEngine\Controllers;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\MismatchResolutionAuditService;
use App\Domain\RuleEngine\Services\PatternMigrationScanner;
use App\Domain\RuleEngine\Services\PatternReconciliationService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CalibrationViewController extends Controller
{
    public function __construct(
        private SectionCalibrationMatrixService $matrixService,
        private FunctionalRuleService $functionalRuleService,
    ) {}

    public function matrixData(string $sheet, string $section): JsonResponse
    {
        $matrix = $this->matrixService->buildPatternMatrix($sheet, $section);

        return response()->json([
            'data' => $matrix,
            'message' => 'Matriz de patrones obtenida',
            'errors' => null,
        ]);
    }

    /**
     * Agregado de progreso de calibracion de toda la estructura activa,
     * agrupado por hoja -- consumido por Dashboard/Plantilla/Serie para
     * mostrar avance real sin requerir N requests por seccion desde el
     * navegador (ver SectionCalibrationMatrixService::buildStructureCalibrationSummary()).
     */
    public function calibrationSummary(): JsonResponse
    {
        $summary = $this->matrixService->buildStructureCalibrationSummary();

        return response()->json([
            'data' => $summary,
            'message' => 'Resumen de calibración obtenido',
            'errors' => null,
        ]);
    }

    public function saveQuestions(Request $request, string $sheet, string $section): JsonResponse
    {
        $validated = $request->validate([
            'questions' => 'required|array',
            'questions.*.id' => 'sometimes|string',
            'questions.*.type' => 'sometimes|string',
            'questions.*.question' => 'required|string',
            'questions.*.response' => 'nullable|string',
            'questions.*.observation' => 'nullable|string',
            'questions.*.pattern_id' => 'nullable|integer',
            'questions.*.pattern_key' => 'nullable|string',
            'questions.*.review_status' => 'nullable|in:pending,reviewed,section_reviewed',
            'questions.*.closure_reason' => 'nullable|string|max:100',
            'questions.*.reviewed_at' => 'nullable|string',
            'questions.*.reviewed_by' => 'nullable|string|max:255',
            'questions.*.status' => 'nullable|in:pending,answered,clarification',
            'questions.*.responsible' => 'nullable|string|max:255',
            'questions.*.date' => 'nullable|string|max:50',
            'questions.*.source_type' => 'nullable|in:manual,sugerida,heredada,reported',
            'questions.*.source_sheet' => 'nullable|string|max:50',
            'questions.*.source_section' => 'nullable|string|max:50',
            'questions.*.technical_signature' => 'nullable|string',
            'questions.*.structure_version' => 'nullable|string|max:100',
            'questions.*.pattern_rows' => 'nullable|array',
            'questions.*.pattern_rows.*' => 'integer',
            'questions.*.pattern_fingerprint' => 'nullable|string|max:100',
            'questions.*.backfill_status' => 'nullable|string|max:50',
            'questions.*.reconciliation_status' => 'nullable|in:reviewed,pending,requiere_revalidacion,unresolved',
            'questions.*.derived_from_fingerprint' => 'nullable|array',
            'questions.*.derived_from_fingerprint.*' => 'string',
        ]);

        $this->functionalRuleService->saveQuestions($sheet, $section, $validated['questions']);

        return response()->json([
            'data' => ['success' => true],
            'message' => 'Respuestas guardadas',
            'errors' => null,
        ]);
    }

    /**
     * Clasificacion de migracion (Fase 3, 2026-08-12) -- 100% lectura, no
     * conectada a reconcileLive()/applyPatternReconciliation() ni al
     * calculo de progreso general (buildStructureCalibrationSummary()).
     * Unico proposito: que el frontend sepa si debe mostrar
     * QuickRevalidationPanel (categoria QUICK_CONFIRMATION) en vez del
     * flujo normal de calibracion, sin activar el mecanismo v2 en
     * produccion. No escribe nada.
     */
    public function migrationPlan(string $sheet, string $section, PatternMigrationScanner $scanner): JsonResponse
    {
        $activeStructure = RemTemplateStructure::where('status', 'active')->first();
        if (! $activeStructure) {
            return response()->json(['data' => null, 'message' => 'No hay ninguna estructura activa.', 'errors' => ['no_active_structure']], 422);
        }

        $estructura = is_string($activeStructure->estructura)
            ? json_decode($activeStructure->estructura, true)
            : $activeStructure->estructura;

        $sectionDecl = null;
        foreach ($estructura['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) === strtoupper($sheet)) {
                foreach ($form['sections'] ?? [] as $s) {
                    if (($s['codigo'] ?? null) === $section) {
                        $sectionDecl = $s;
                    }
                }
            }
        }
        if ($sectionDecl === null) {
            return response()->json(['data' => null, 'message' => "No se encontró la sección {$sheet}/{$section}.", 'errors' => ['section_not_found']], 404);
        }

        $plan = $scanner->scanSection($activeStructure, $sheet, $section, $sectionDecl);

        return response()->json([
            'data' => $plan,
            'message' => 'Plan de migración obtenido',
            'errors' => null,
        ]);
    }

    /**
     * Flujo QUICK_CONFIRMATION (Fase 1, 2026-08-12). Deliberadamente NO
     * reutiliza saveQuestions(): ese endpoint acepta response/reviewed_by/
     * reviewed_at/source_type libremente desde el body, lo cual es correcto
     * para calibracion normal pero inseguro para una revalidacion tecnica
     * (el frontend no debe poder alterar ni la decision original ni quien
     * la reviso). Este endpoint no recibe del body nada mas que la
     * identificacion de hoja/seccion/patron -- todo lo demas (fingerprint
     * vigente, filas vigentes, usuario que revalida, timestamp) se calcula
     * o se obtiene exclusivamente en el servidor.
     */
    public function confirmQuickRevalidation(Request $request, string $sheet, string $section, int $patternId, PatternMigrationScanner $scanner): JsonResponse
    {
        $activeStructure = RemTemplateStructure::where('status', 'active')->first();
        if (! $activeStructure) {
            return response()->json([
                'data' => null,
                'message' => 'No hay ninguna estructura activa.',
                'errors' => ['no_active_structure'],
            ], 422);
        }

        $estructura = is_string($activeStructure->estructura)
            ? json_decode($activeStructure->estructura, true)
            : $activeStructure->estructura;

        $sectionDecl = null;
        foreach ($estructura['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) === strtoupper($sheet)) {
                foreach ($form['sections'] ?? [] as $s) {
                    if (($s['codigo'] ?? null) === $section) {
                        $sectionDecl = $s;
                    }
                }
            }
        }
        if ($sectionDecl === null) {
            return response()->json([
                'data' => null,
                'message' => "No se encontró la sección {$sheet}/{$section} en la estructura activa.",
                'errors' => ['section_not_found'],
            ], 404);
        }

        // Reclasificacion EN VIVO, justo antes de decidir si se puede
        // escribir -- nunca se confia en lo que el frontend muestra que
        // haya cargado antes.
        $plan = $scanner->scanSection($activeStructure, $sheet, $section, $sectionDecl);

        if ($plan['category'] !== PatternReconciliationService::MIGRATION_QUICK_CONFIRMATION) {
            return response()->json([
                'data' => ['category' => $plan['category']],
                'message' => 'Esta sección cambió desde que se cargó y ya no corresponde a una confirmación rápida. Debe revisarse completa.',
                'errors' => ['no_longer_quick_confirmation'],
            ], 409);
        }

        $patternPlan = null;
        foreach ($plan['patterns'] as $p) {
            if ($p['pattern_id'] === $patternId) {
                $patternPlan = $p;

                break;
            }
        }

        if ($patternPlan === null || $patternPlan['category'] !== PatternReconciliationService::MIGRATION_QUICK_CONFIRMATION) {
            return response()->json([
                'data' => ['category' => $patternPlan['category'] ?? 'not_found'],
                'message' => 'Este patrón cambió desde que se cargó y ya no corresponde a una confirmación rápida. Debe revisarse completo.',
                'errors' => ['no_longer_quick_confirmation'],
            ], 409);
        }

        $sortedRows = $patternPlan['live_rows'];
        sort($sortedRows, SORT_NUMERIC);

        // 2026-08-24 (hallazgo de corrupcion real A09/G P3): la escritura
        // DEBE dirigirse a la identidad historica resuelta por
        // matchLivePatternsToHistorical() ($patternPlan['historical_pattern_id']),
        // NUNCA al $patternId vivo/posicional -- ver docblock extenso en
        // FunctionalRuleService::applyQuickRevalidation(). Si por alguna
        // razon no hay identidad resuelta (no deberia ocurrir: la categoria
        // ya se valido como QUICK_CONFIRMATION arriba, lo que exige un
        // match), se rechaza en vez de arriesgar escribir sobre el patron
        // equivocado.
        $historicalPatternId = $patternPlan['historical_pattern_id'] ?? null;
        if ($historicalPatternId === null) {
            return response()->json([
                'data' => null,
                'message' => 'No se pudo resolver la identidad histórica de este patrón de forma inequívoca -- no se escribe nada.',
                'errors' => ['historical_identity_unresolved'],
            ], 409);
        }

        $revalidatedBy = $request->user()?->name ?? $request->user()?->email;
        if (! $revalidatedBy) {
            return response()->json([
                'data' => null,
                'message' => 'No se pudo identificar al usuario autenticado.',
                'errors' => ['unauthenticated'],
            ], 401);
        }

        try {
            $updated = $this->functionalRuleService->applyQuickRevalidation(
                $sheet,
                $section,
                $historicalPatternId,
                canonicalFingerprint: $patternPlan['live_canonical_fingerprint'],
                patternRows: $sortedRows,
                revalidatedBy: $revalidatedBy,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'data' => null,
                'message' => $e->getMessage(),
                'errors' => ['pattern_not_found'],
            ], 404);
        }

        return response()->json([
            'data' => ['questions' => $updated],
            'message' => 'Revalidación confirmada.',
            'errors' => null,
        ]);
    }

    /**
     * Flujo de resolución MISMATCH (2026-08-21). Endpoint de solo lectura --
     * expone qué cambió (columnas, filas, fórmula vigente) y la categoría de
     * resolución ya auditada (safe_reconfirm / human_review /
     * structural_review) para que el frontend decida qué botón mostrar.
     * Nunca escribe nada.
     */
    public function mismatchResolutionDetails(string $sheet, string $section, int $patternId, PatternMigrationScanner $scanner, MismatchResolutionAuditService $audit): JsonResponse
    {
        $activeStructure = RemTemplateStructure::where('status', 'active')->first();
        if (! $activeStructure) {
            return response()->json(['data' => null, 'message' => 'No hay ninguna estructura activa.', 'errors' => ['no_active_structure']], 422);
        }

        $estructura = is_string($activeStructure->estructura)
            ? json_decode($activeStructure->estructura, true)
            : $activeStructure->estructura;

        $sectionDecl = null;
        foreach ($estructura['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) === strtoupper($sheet)) {
                foreach ($form['sections'] ?? [] as $s) {
                    if (($s['codigo'] ?? null) === $section) {
                        $sectionDecl = $s;
                    }
                }
            }
        }
        if ($sectionDecl === null) {
            return response()->json(['data' => null, 'message' => "No se encontró la sección {$sheet}/{$section}.", 'errors' => ['section_not_found']], 404);
        }

        $plan = $scanner->scanSection($activeStructure, $sheet, $section, $sectionDecl);

        $patternPlan = null;
        foreach ($plan['patterns'] as $p) {
            if ($p['pattern_id'] === $patternId) {
                $patternPlan = $p;

                break;
            }
        }

        if ($patternPlan === null) {
            return response()->json(['data' => null, 'message' => 'Patrón no encontrado.', 'errors' => ['pattern_not_found']], 404);
        }

        // 2026-08-24: getTag() ahora exige las filas vivas para resolver por
        // identidad estable (nunca por pattern_id posicional a ciegas) --
        // ver docblock de MismatchResolutionAuditService::getTag().
        $tag = $audit->getTag($sheet, $section, $patternId, $patternPlan['live_rows']);

        return response()->json([
            'data' => [
                'live_category' => $patternPlan['category'],
                'live_rows' => $patternPlan['live_rows'],
                'live_canonical_fingerprint' => $patternPlan['live_canonical_fingerprint'],
                'historical_answer' => $patternPlan['historical_answer'] ?? null,
                'historical_rows' => $patternPlan['historical_rows'] ?? null,
                'column_diff' => $plan['column_diff'] ?? null,
                'resolution_tag' => $tag,
            ],
            'message' => 'Detalle de resolución obtenido.',
            'errors' => null,
        ]);
    }

    /**
     * Confirma un patron MISMATCH etiquetado previamente como
     * safe_reconfirm -- reutiliza applyQuickRevalidation() tal cual (mismos
     * 6 campos protegidos, nunca toca response/reviewed_by/reviewed_at/
     * review_status). Rechaza explícitamente cualquier otra categoría
     * (human_review nunca se confirma por esta vía rápida; structural_review
     * nunca se confirma por ninguna vía rápida; sin tag = sin auditar, se
     * rechaza por defecto -- nunca se asume "seguro" sin evidencia).
     *
     * Revalida en vivo, igual que confirmQuickRevalidation(): si la
     * categoría ya no es MISMATCH, o si el fingerprint/filas vigentes ya no
     * coinciden con lo que se auditó (el patrón cambió de nuevo desde que se
     * etiquetó), rechaza con 409 en vez de escribir.
     */
    public function confirmMismatchResolution(Request $request, string $sheet, string $section, int $patternId, PatternMigrationScanner $scanner, MismatchResolutionAuditService $audit): JsonResponse
    {
        $activeStructure = RemTemplateStructure::where('status', 'active')->first();
        if (! $activeStructure) {
            return response()->json(['data' => null, 'message' => 'No hay ninguna estructura activa.', 'errors' => ['no_active_structure']], 422);
        }

        $estructura = is_string($activeStructure->estructura)
            ? json_decode($activeStructure->estructura, true)
            : $activeStructure->estructura;

        $sectionDecl = null;
        foreach ($estructura['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) === strtoupper($sheet)) {
                foreach ($form['sections'] ?? [] as $s) {
                    if (($s['codigo'] ?? null) === $section) {
                        $sectionDecl = $s;
                    }
                }
            }
        }
        if ($sectionDecl === null) {
            return response()->json(['data' => null, 'message' => "No se encontró la sección {$sheet}/{$section}.", 'errors' => ['section_not_found']], 404);
        }

        $plan = $scanner->scanSection($activeStructure, $sheet, $section, $sectionDecl);

        if ($plan['category'] !== PatternReconciliationService::MIGRATION_MISMATCH) {
            return response()->json([
                'data' => ['category' => $plan['category']],
                'message' => 'Esta sección cambió desde que se cargó y ya no corresponde a MISMATCH.',
                'errors' => ['no_longer_mismatch'],
            ], 409);
        }

        $patternPlan = null;
        foreach ($plan['patterns'] as $p) {
            if ($p['pattern_id'] === $patternId) {
                $patternPlan = $p;

                break;
            }
        }

        if ($patternPlan === null || $patternPlan['category'] !== PatternReconciliationService::MIGRATION_MISMATCH) {
            return response()->json([
                'data' => ['category' => $patternPlan['category'] ?? 'not_found'],
                'message' => 'Este patrón cambió desde que se cargó y ya no corresponde a MISMATCH.',
                'errors' => ['no_longer_mismatch'],
            ], 409);
        }

        // 2026-08-24: getTag() ahora exige las filas vivas para resolver por
        // identidad estable (nunca por pattern_id posicional a ciegas) --
        // ver docblock de MismatchResolutionAuditService::getTag().
        $tag = $audit->getTag($sheet, $section, $patternId, $patternPlan['live_rows']);

        if ($tag === null) {
            return response()->json([
                'data' => null,
                'message' => 'Este patrón todavía no fue auditado -- no puede confirmarse sin una clasificación explícita (safe_reconfirm/human_review/structural_review).',
                'errors' => ['not_audited'],
            ], 409);
        }

        // 2026-08-24: structural_row_exclusion se agrega como categoria
        // confirmable ADEMAS de safe_reconfirm -- cada una con su propio
        // gate completo mas abajo (el bloque de safe_reconfirm es
        // EXACTAMENTE el mismo de siempre, sin ninguna linea tocada).
        // human_review/structural_review (o cualquier otra) siguen sin ser
        // confirmables por esta via, igual que antes.
        if (
            $tag['category'] !== MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM
            && $tag['category'] !== MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION
        ) {
            return response()->json([
                'data' => ['resolution_category' => $tag['category']],
                'message' => $tag['category'] === MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW
                    ? 'Este patrón requiere revisión funcional completa -- no admite confirmación rápida.'
                    : 'Este patrón es un cambio estructural -- debe resolverse por el flujo estructural, no por confirmación rápida.',
                'errors' => ['requires_full_review'],
            ], 409);
        }

        $sortedLiveRows = $patternPlan['live_rows'];
        sort($sortedLiveRows, SORT_NUMERIC);
        $sortedAuditedRows = $tag['audited_rows'];
        sort($sortedAuditedRows, SORT_NUMERIC);

        // 2026-08-24 (hallazgo de corrupcion real A09/G P3): la escritura
        // DEBE dirigirse a la identidad historica resuelta por
        // matchLivePatternsToHistorical() ($patternPlan['historical_pattern_id']),
        // NUNCA al $patternId vivo/posicional -- compartido por ambas ramas
        // (safe_reconfirm y structural_row_exclusion) de abajo. Ver docblock
        // extenso en FunctionalRuleService::applyQuickRevalidation().
        $historicalPatternId = $patternPlan['historical_pattern_id'] ?? null;
        if ($historicalPatternId === null) {
            return response()->json([
                'data' => null,
                'message' => 'No se pudo resolver la identidad histórica de este patrón de forma inequívoca -- no se escribe nada.',
                'errors' => ['historical_identity_unresolved'],
            ], 409);
        }

        $revalidatedBy = $request->user()?->name ?? $request->user()?->email;
        if (! $revalidatedBy) {
            return response()->json(['data' => null, 'message' => 'No se pudo identificar al usuario autenticado.', 'errors' => ['unauthenticated']], 401);
        }

        if ($tag['category'] === MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM) {
            if ($tag['audited_fingerprint'] !== $patternPlan['live_canonical_fingerprint'] || $sortedAuditedRows !== $sortedLiveRows) {
                return response()->json([
                    'data' => null,
                    'message' => 'El patrón cambió desde que se auditó como safe_reconfirm -- requiere volver a auditarse antes de confirmar.',
                    'errors' => ['audit_stale'],
                ], 409);
            }

            try {
                $updated = $this->functionalRuleService->applyQuickRevalidation(
                    $sheet,
                    $section,
                    $historicalPatternId,
                    canonicalFingerprint: $patternPlan['live_canonical_fingerprint'],
                    patternRows: $sortedLiveRows,
                    revalidatedBy: $revalidatedBy,
                );
            } catch (\RuntimeException $e) {
                return response()->json(['data' => null, 'message' => $e->getMessage(), 'errors' => ['pattern_not_found']], 404);
            }

            return response()->json([
                'data' => ['questions' => $updated],
                'message' => 'MISMATCH resuelto (safe_reconfirm).',
                'errors' => null,
            ]);
        }

        // ── structural_row_exclusion ──────────────────────────────────
        // Gate mecanico completo, revalidado 100% en vivo (nunca confia en
        // lo guardado en el tag salvo para comparar contra el estado
        // fresco): existencia y completitud de los 4 campos nuevos del tag,
        // identidad historica sin cambios desde la auditoria, union exacta
        // filas_vivas+excluidas=historicas, sin filas adicionales, y CADA
        // fila excluida reverificada EN VIVO contra el mecanismo que el tag
        // dice haber usado (nunca se asume que seguir ausente del conjunto
        // vivo implica que sigue siendo un TOTAL/subtotal legitimo).
        //
        // 2026-08-28 (SAFE_TO_EXTEND_STRUCTURAL_ROW_EXCLUSION_TO_12):
        // $tagMechanism ahora puede ser CUALQUIERA de los 2 mecanismos
        // soportados (ALLOWED_EXCLUSION_MECHANISMS) -- el valor exacto
        // guardado en el tag (creado por RuleTagMismatchResolutionCommand,
        // que ya garantiza que las filas excluidas resuelven todas al MISMO
        // mecanismo) determina cual metodo del scanner se usa para la
        // re-verificacion mas abajo.
        $tagHistoricalRows = $tag['historical_rows'] ?? null;
        $tagExcludedRows = $tag['excluded_total_rows'] ?? null;
        $tagMechanism = $tag['exclusion_mechanism'] ?? null;

        if (
            $tagHistoricalRows === null
            || empty($tagExcludedRows)
            || ! in_array($tagMechanism, MismatchResolutionAuditService::ALLOWED_EXCLUSION_MECHANISMS, true)
        ) {
            return response()->json([
                'data' => null,
                'message' => 'El tag de exclusión estructural está incompleto (falta historical_rows, excluded_total_rows, o el mecanismo no es uno de los soportados) -- no puede confirmarse.',
                'errors' => ['incomplete_structural_exclusion_tag'],
            ], 409);
        }

        $sortedTagHistorical = $tagHistoricalRows;
        sort($sortedTagHistorical, SORT_NUMERIC);
        $sortedTagExcluded = $tagExcludedRows;
        sort($sortedTagExcluded, SORT_NUMERIC);

        // Identidad historica re-resuelta AHORA (matchLivePatternsToHistorical,
        // nunca pattern_id crudo) debe seguir siendo la misma que se audito.
        $freshHistoricalRows = $patternPlan['historical_rows'] ?? null;
        $sortedFreshHistorical = $freshHistoricalRows;
        if ($sortedFreshHistorical !== null) {
            sort($sortedFreshHistorical, SORT_NUMERIC);
        }

        if ($sortedFreshHistorical === null || $sortedFreshHistorical !== $sortedTagHistorical) {
            return response()->json([
                'data' => null,
                'message' => 'El patrón histórico emparejado por identidad cambió desde que se auditó -- requiere volver a auditarse.',
                'errors' => ['audit_stale'],
            ], 409);
        }

        if ($tag['audited_fingerprint'] !== $patternPlan['live_canonical_fingerprint'] || $sortedAuditedRows !== $sortedLiveRows) {
            return response()->json([
                'data' => null,
                'message' => 'El patrón cambió desde que se auditó como structural_row_exclusion -- requiere volver a auditarse antes de confirmar.',
                'errors' => ['audit_stale'],
            ], 409);
        }

        $addedRows = array_values(array_diff($sortedLiveRows, $sortedTagHistorical));
        if (! empty($addedRows)) {
            return response()->json([
                'data' => null,
                'message' => 'Existen filas vivas que no estaban en el histórico -- cambio estructural adicional no explicado, no puede confirmarse vía exclusión estructural.',
                'errors' => ['structural_exclusion_mismatch'],
            ], 409);
        }

        $unionRows = array_values(array_unique(array_merge($sortedLiveRows, $sortedTagExcluded)));
        sort($unionRows, SORT_NUMERIC);
        if ($unionRows !== $sortedTagHistorical) {
            return response()->json([
                'data' => null,
                'message' => 'Filas vivas + filas excluidas no reconstruyen exactamente las filas históricas -- diferencia adicional no explicada, no puede confirmarse.',
                'errors' => ['structural_exclusion_mismatch'],
            ], 409);
        }

        // Etiquetas EXACTAS preservadas para mecanismo #6 (regresion
        // existente, StructuralRowExclusionConfirmTest::
        // test_valid_structural_exclusion_tag_is_confirmed espera el
        // mensaje de exito literal completo) -- mecanismo #12 usa el mismo
        // formato de frase, sustituyendo unicamente el nombre del mecanismo.
        $isMechanism12 = $tagMechanism === MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_BACKWARD_SUBTOTAL;
        $mechanismLabel = $isMechanism12 ? 'mecanismo #12' : 'mecanismo #6';
        $exclusionKindLabel = $isMechanism12 ? 'subtotal embebido hacia atrás' : 'fila TOTAL líder';

        foreach ($sortedTagExcluded as $excludedRow) {
            $cumpleMecanismo = $tagMechanism === MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_BACKWARD_SUBTOTAL
                ? $scanner->isEmbeddedBackwardSubtotalRow($sheet, $section, $excludedRow, $sectionDecl)
                : $scanner->isEmbeddedLeadingTotalRow($sheet, $section, $excludedRow, $sectionDecl);

            if (! $cumpleMecanismo) {
                return response()->json([
                    'data' => null,
                    'message' => "La fila {$excludedRow} ya no cumple el {$mechanismLabel} ({$exclusionKindLabel}) verificado en vivo -- no puede confirmarse vía exclusión estructural.",
                    'errors' => ['structural_exclusion_mismatch'],
                ], 409);
            }
        }

        try {
            $updated = $this->functionalRuleService->applyQuickRevalidation(
                $sheet,
                $section,
                $historicalPatternId,
                canonicalFingerprint: $patternPlan['live_canonical_fingerprint'],
                patternRows: $sortedLiveRows,
                revalidatedBy: $revalidatedBy,
                revalidationSourceType: MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
                historicalRowsBeforeExclusion: $sortedTagHistorical,
                excludedTotalRows: $sortedTagExcluded,
                exclusionMechanism: $tagMechanism,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['data' => null, 'message' => $e->getMessage(), 'errors' => ['pattern_not_found']], 404);
        }

        return response()->json([
            'data' => ['questions' => $updated],
            // Texto EXACTO preservado para #6 ("... de fila TOTAL líder,
            // mecanismo #6.") -- StructuralRowExclusionConfirmTest lo
            // afirma literal; #12 usa la misma forma de frase.
            'message' => "MISMATCH resuelto (exclusión estructural de {$exclusionKindLabel}, {$mechanismLabel}).",
            'errors' => null,
        ]);
    }
}
