<?php

namespace App\Domain\RuleEngine\Controllers;

use App\Domain\RemParser\Models\RemTemplateStructure;
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
                $patternId,
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
}
