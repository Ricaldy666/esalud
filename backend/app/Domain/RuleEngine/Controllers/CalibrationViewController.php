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
        ]);

        $this->functionalRuleService->saveQuestions($sheet, $section, $validated['questions']);

        return response()->json([
            'data' => ['success' => true],
            'message' => 'Respuestas guardadas',
            'errors' => null,
        ]);
    }
}
