<?php

namespace App\Domain\Calibration\Services;

use App\Domain\Calibration\Models\Calibration;
use App\Domain\Calibration\Models\CalibrationCell;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use App\Models\User;
use Illuminate\Support\Collection;

class CalibrationService
{
    public function __construct(
        private SectionCalibrationMatrixService $matrixService,
    ) {}

    public function list(?int $userId = null): Collection
    {
        $query = Calibration::with(['structure', 'creator']);

        if ($userId !== null) {
            $query->where('created_by', $userId);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function createDraft(User $user): Calibration
    {
        $structure = $this->resolveActiveStructure();

        $existing = Calibration::where('structure_id', $structure->id)
            ->where('hoja', 'A01')
            ->where('created_by', $user->id)
            ->where('status', 'draft')
            ->first();

        if ($existing) {
            throw new \DomainException(
                'Ya existe un borrador activo para esta estructura y hoja. '
                . 'Continúe editando el borrador existente o actívalo antes de crear uno nuevo.'
            );
        }

        $calibration = Calibration::create([
            'structure_id' => $structure->id,
            'serie' => 'A',
            'hoja' => 'A01',
            'anio' => $structure->anio,
            'version' => 1,
            'status' => 'draft',
            'progress_pct' => 0,
            'total_sections' => 12,
            'ready_sections' => 0,
            'created_by' => $user->id,
        ]);

        activity()
            ->performedOn($calibration)
            ->causedBy($user)
            ->withProperties([
                'structure_id' => $structure->id,
                'hoja' => 'A01',
                'anio' => $structure->anio,
            ])
            ->log('calibration_created');

        return $calibration->load(['structure', 'creator']);
    }

    public function findById(int $id): ?Calibration
    {
        return Calibration::with(['structure', 'creator'])->find($id);
    }

    public function getSectionCells(Calibration $calibration, string $section): array
    {
        $savedCells = $calibration->cells()
            ->where('section', $section)
            ->get()
            ->keyBy('coordinate');

        $matrix = null;
        try {
            $matrix = $this->matrixService->buildPatternMatrix(
                $calibration->hoja,
                $section
            );
        } catch (\Throwable $e) {
            $matrix = [
                'section' => [
                    'codigo' => $section,
                    'titulo' => '',
                    'status' => 'error',
                    'fila_header' => null,
                    'fila_inicio' => null,
                    'fila_fin' => null,
                    'total_filas_fisicas' => 0,
                    'total_campos' => 0,
                ],
                'rows' => [],
                'patterns' => [],
                'columnas_especiales' => [],
                'summary' => [],
                'questions' => [],
                'all_rows' => [],
                'header_labels' => [],
                'aggregated_rules' => [],
                'warnings' => ['No se pudo construir la matriz: ' . $e->getMessage()],
            ];
        }

        return [
            'cells' => $savedCells->values(),
            'matrix' => $matrix,
        ];
    }

    public function saveCell(Calibration $calibration, array $data, User $user): CalibrationCell
    {
        $cell = CalibrationCell::updateOrCreate(
            [
                'calibration_id' => $calibration->id,
                'section' => $data['section'],
                'coordinate' => $data['coordinate'],
            ],
            [
                'row' => $data['row'] ?? null,
                'column_letter' => $data['column_letter'] ?? null,
                'behavior' => $data['behavior'],
                'formula' => $data['formula'] ?? null,
                'formula_status' => $data['formula_status'] ?? null,
                'justification' => $data['justification'] ?? null,
                'source' => $data['source'] ?? 'manual',
                'rule_correction' => $data['rule_correction'] ?? null,
                'created_by' => $user->id,
            ]
        );

        activity()
            ->performedOn($cell)
            ->causedBy($user)
            ->withProperties([
                'calibration_id' => $calibration->id,
                'section' => $data['section'],
                'coordinate' => $data['coordinate'],
                'behavior' => $data['behavior'],
                'has_rule_correction' => isset($data['rule_correction']),
            ])
            ->log('calibration_cell_saved');

        $this->recalculateProgress($calibration);

        return $cell;
    }

    private function recalculateProgress(Calibration $calibration): void
    {
        $sections = $calibration->cells()
            ->selectRaw('section, COUNT(DISTINCT coordinate) as total')
            ->groupBy('section')
            ->get();

        $readySections = $sections->count();

        $totalExpected = $calibration->total_sections;

        $progress = $totalExpected > 0
            ? round(($readySections / $totalExpected) * 100, 2)
            : 0;

        $calibration->update([
            'ready_sections' => $readySections,
            'progress_pct' => $progress,
        ]);
    }

    private function resolveActiveStructure(): RemTemplateStructure
    {
        $structure = RemTemplateStructure::where('serie', 'A')
            ->where('status', 'active')
            ->orderByRaw('COALESCE(version_number, 0) DESC')
            ->orderBy('id', 'DESC')
            ->first();

        if (!$structure) {
            throw new \DomainException(
                'No se encontró una estructura activa para la serie A. '
                . 'Debe existir al menos una estructura con status=active en rem_template_structures.'
            );
        }

        return $structure;
    }
}
