<?php

namespace App\Domain\RuleEngine\Controllers;

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
}
