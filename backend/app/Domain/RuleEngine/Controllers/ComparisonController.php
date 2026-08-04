<?php

namespace App\Domain\RuleEngine\Controllers;

use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Testing\ComparisonReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ComparisonController extends Controller
{
    public function __construct(
        private ComparisonReport $comparisonReport,
    ) {}

    public function run(Request $request): JsonResponse
    {
        $request->validate([
            'structure_id' => 'required|integer|min:1',
            'upload_id' => 'required|integer|min:1',
        ]);

        $structureId = (int) $request->structure_id;
        $uploadId = (int) $request->upload_id;

        $report = $this->comparisonReport->generateReport($structureId, $uploadId);

        if (isset($report['error'])) {
            return response()->json([
                'data' => null,
                'message' => $report['error'],
                'errors' => ['builder' => $report['error']],
            ], 422);
        }

        $differences = [];
        foreach ($report['differences'] as $diff) {
            $severity = null;
            $ruleKey = $diff['new_key'] ?? null;
            if ($ruleKey) {
                $rule = Rule::where('rule_key', $ruleKey)->first(['severity']);
                $severity = $rule?->severity;
            }
            $differences[] = array_merge($diff, ['severity' => $severity]);
        }

        $structure = \App\Domain\RemParser\Models\RemTemplateStructure::withTrashed()
            ->find($structureId, ['id', 'serie', 'anio', 'version_number', 'status']);

        $uploadModel = \App\Domain\REM\Models\RemUpload::find($uploadId, ['id', 'original_filename', 'year', 'month']);

        return response()->json([
            'data' => [
                'structure_id' => $structureId,
                'upload_id' => $uploadId,
                'structure' => $structure ? [
                    'id' => $structure->id,
                    'serie' => $structure->serie,
                    'anio' => $structure->anio,
                    'version_number' => $structure->version_number,
                    'status' => $structure->status,
                ] : null,
                'upload' => $uploadModel ? [
                    'id' => $uploadModel->id,
                    'filename' => $uploadModel->original_filename,
                    'period' => $uploadModel->year . '-' . $uploadModel->month,
                ] : null,
                'summary' => $report['summary'] ?? null,
                'differences' => $differences,
                'execution_time_ms' => $report['execution_time_ms'] ?? 0,
            ],
            'message' => null,
            'errors' => null,
        ]);
    }
}
