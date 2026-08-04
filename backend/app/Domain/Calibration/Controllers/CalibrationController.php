<?php

namespace App\Domain\Calibration\Controllers;

use App\Domain\Calibration\Models\Calibration;
use App\Domain\Calibration\Services\CalibrationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalibrationController extends Controller
{
    public function __construct(
        private CalibrationService $calibrationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Calibration::class);

        $user = $request->user();
        $calibrations = $user->hasRole('Superadmin')
            ? $this->calibrationService->list()
            : $this->calibrationService->list($user->id);

        return response()->json([
            'data' => $calibrations,
            'message' => 'Calibraciones obtenidas',
            'errors' => null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Calibration::class);

        try {
            $calibration = $this->calibrationService->createDraft($request->user());

            return response()->json([
                'data' => $calibration,
                'message' => 'Borrador de calibración creado',
                'errors' => null,
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'data' => null,
                'message' => $e->getMessage(),
                'errors' => ['calibration' => [$e->getMessage()]],
            ], 422);
        }
    }

    public function show(Calibration $calibration): JsonResponse
    {
        $this->authorize('view', $calibration);

        $calibration->load(['structure', 'creator', 'cells']);

        return response()->json([
            'data' => $calibration,
            'message' => 'Calibración obtenida',
            'errors' => null,
        ]);
    }

    public function sectionCells(Calibration $calibration, string $section): JsonResponse
    {
        $this->authorize('view', $calibration);

        $data = $this->calibrationService->getSectionCells($calibration, $section);
        $matrixStatus = $data['matrix']['section']['status'] ?? 'ok';
        if ($matrixStatus === 'invalid_structure') {
            return response()->json([
                'data' => $data,
                'message' => 'La estructura de calibración no tiene un formato compatible',
                'errors' => [
                    'structure' => $data['matrix']['warnings'] ?? ['Estructura incompatible'],
                ],
            ], 422);
        }

        return response()->json([
            'data' => $data,
            'message' => 'Celdas de sección obtenidas',
            'errors' => null,
        ]);
    }

    public function saveCell(Request $request, Calibration $calibration): JsonResponse
    {
        $this->authorize('update', $calibration);

        $validated = $request->validate([
            'section' => 'required|string|max:10',
            'coordinate' => 'required|string|max:15',
            'row' => 'nullable|integer|min:1',
            'column_letter' => 'nullable|string|max:5',
            'behavior' => 'required|string|in:required,required_numeric,optional,not_applicable,blocked,calculated',
            'formula' => 'nullable|string|max:1000',
            'formula_status' => 'nullable|string|in:pending,correct,incorrect,needs_adjustment',
            'justification' => 'nullable|string|max:2000',
            'source' => 'nullable|string|in:manual,auto_detected',
            'rule_correction' => 'nullable|array',
            'rule_correction.rule_id' => 'required_with:rule_correction|integer|exists:rem_rules,id',
            'rule_correction.target_original' => 'required_with:rule_correction|string|max:10',
            'rule_correction.target_corrected' => 'required_with:rule_correction|string|max:10',
            'rule_correction.reason' => 'nullable|string|max:1000',
        ]);

        $cell = $this->calibrationService->saveCell(
            $calibration,
            $validated,
            $request->user()
        );

        return response()->json([
            'data' => $cell,
            'message' => 'Configuración de celda guardada',
            'errors' => null,
        ]);
    }
}
