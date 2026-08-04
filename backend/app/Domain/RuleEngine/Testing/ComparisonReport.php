<?php

namespace App\Domain\RuleEngine\Testing;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\RemFormulaRuleBuilder;
use App\Domain\RuleEngine\Services\RuleKeyGeneratorService;
use App\Domain\REM\Models\RemData;
use Illuminate\Support\Facades\DB;

class ComparisonReport
{
    public function __construct(
        private RuleKeyGeneratorService $keyGenerator,
    ) {}

    public function buildKeyMap(int $structureId): array
    {
        $structure = RemTemplateStructure::withTrashed()->findOrFail($structureId);
        $est = $structure->estructura;

        try {
            $builder = new RemFormulaRuleBuilder;
            $dtos = $builder->build($structure);
        } catch (\TypeError $e) {
            return ['error' => 'Builder legacy no soporta esta estructura: ' . $e->getMessage()];
        }

        $matchableFields = [];

        foreach ($est['forms'] ?? [] as $form) {
            $sheetLower = strtolower($form['sheetName'] ?? '');

            foreach ($form['sections'] ?? [] as $section) {
                $sectionCodigo = $section['codigo'] ?? 'IMPLICITA';

                foreach ($section['fields'] ?? [] as $field) {
                    $regla = $field['reglaDetectada'] ?? null;
                    if ($regla === null) continue;

                    $tipo = is_array($regla) ? ($regla['tipo'] ?? '') : $regla;
                    if ($tipo === 'control_oculto') continue;

                    $letra = $field['letra'] ?? '?';
                    $rangoFilas = is_array($regla) ? ($regla['rangoFilas'] ?? null) : null;

                    $matchableFields[] = [
                        'sheet' => $sheetLower,
                        'section' => $sectionCodigo,
                        'letra' => $letra,
                        'tipo' => $tipo,
                        'rango_filas' => $rangoFilas,
                    ];
                }
            }
        }

        $map = [];

        foreach ($dtos as $idx => $dto) {
            $mf = $matchableFields[$idx] ?? null;
            if (!$mf) continue;

            $sectionCodigo = $mf['section'];
            $rangoFilas = $mf['rango_filas'];
            $letra = $mf['letra'];
            $tipo = $mf['tipo'];

            $rowFrom = $dto->rowFrom;
            $rowTo = $dto->rowTo;

            $compKey = $this->makeCompKey($mf['sheet'], $sectionCodigo, $letra, $tipo, $rowFrom, $rowTo);
            $newKey = $this->keyGenerator->generate($mf['sheet'], $sectionCodigo, $letra, $tipo);

            $map[$compKey] = [
                'comp_key' => $compKey,
                'legacy_key' => $dto->ruleKey,
                'new_key' => $newKey,
                'sheet' => $mf['sheet'],
                'section' => $sectionCodigo,
                'letra' => $letra,
                'tipo' => $tipo,
                'row_from' => $rowFrom,
                'row_to' => $rowTo,
                'rango_filas' => $rangoFilas,
            ];
        }

        return $map;
    }

    public function makeCompKey(
        string $sheet,
        string $section,
        string $letra,
        string $tipo,
        ?int $rowFrom,
        ?int $rowTo,
    ): string {
        $rf = $rowFrom !== null ? (string) $rowFrom : '';
        $rt = $rowTo !== null ? (string) $rowTo : '';
        return strtolower("{$sheet}|{$section}|{$letra}|{$tipo}|{$rf}|{$rt}");
    }

    public function compare(array $legacyData, array $newResults, array $keyMap): array
    {
        $differences = [];
        $matchCount = 0;
        $stats = [
            'legacy' => ['skipped' => 0, 'passed' => 0, 'failed' => 0],
            'engine' => ['skipped' => 0, 'passed' => 0, 'failed' => 0],
        ];

        $legacyResults = $legacyData['results'] ?? [];
        $newByKey = [];
        foreach ($newResults as $r) {
            $newByKey[$r['rule_key']] = $r;
        }

        $idx = 0;
        foreach ($keyMap as $compKey => $mapping) {
            $legacy = $legacyResults[$idx] ?? null;
            $newKey = $mapping['new_key'];
            $new = $newByKey[$newKey] ?? null;
            $idx++;

            if (!$legacy || !$new) {
                continue;
            }

            $stats['legacy'][$legacy['status']]++;
            $stats['engine'][$new['status']]++;

            $sameStatus = $legacy['status'] === $new['status'];
            $sameRows = $legacy['total_rows'] === $new['total_rows'];
            $sameFailed = $legacy['failed_rows'] === $new['failed_rows'];

            if (!$sameStatus || !$sameRows || !$sameFailed) {
                $differences[] = [
                    'comp_key' => $compKey,
                    'new_key' => $newKey,
                    'sheet' => $mapping['sheet'],
                    'section' => $mapping['section'],
                    'letra' => $mapping['letra'],
                    'tipo' => $mapping['tipo'],
                    'row_from' => $mapping['row_from'],
                    'row_to' => $mapping['row_to'],
                    'status_match' => $sameStatus,
                    'rows_match' => $sameRows,
                    'failed_match' => $sameFailed,
                    'legacy' => [
                        'status' => $legacy['status'],
                        'total_rows' => $legacy['total_rows'],
                        'failed_rows' => $legacy['failed_rows'],
                    ],
                    'engine' => [
                        'status' => $new['status'],
                        'total_rows' => $new['total_rows'],
                        'failed_rows' => $new['failed_rows'],
                    ],
                ];
            } else {
                $matchCount++;
            }
        }

        return [
            'total_rules_in_map' => count($keyMap),
            'match_count' => $matchCount,
            'difference_count' => count($differences),
            'stats' => $stats,
            'differences' => $differences,
        ];
    }

    public function generateReport(int $structureId, int $uploadId, ?string $outputPath = null): array
    {
        $startTime = microtime(true);

        $keyMapResult = $this->buildKeyMap($structureId);
        $hasBuilderError = isset($keyMapResult['error']);

        if (!$hasBuilderError) {
            $legacySim = new LegacySimulator;
            $legacyResults = $legacySim->simulate($structureId, $uploadId);
        } else {
            $legacyResults = [];
        }

        $engine = app(\App\Domain\RuleEngine\Services\RuleEngineService::class);
        $engine->registerEvaluator(new \App\Domain\RuleEngine\Evaluators\SumEqualsEvaluator);
        $engine->registerEvaluator(new \App\Domain\RuleEngine\Evaluators\RequiredAndLeParentEvaluator);
        $engineStats = $engine->execute($uploadId, $structureId, false);
        $elapsed = (int) ((microtime(true) - $startTime) * 1000);

        if ($hasBuilderError) {
            $s = ['skipped' => 0, 'passed' => 0, 'failed' => 0];
            foreach ($engineStats['details'] as $r) {
                $s[$r['status']]++;
            }
            $report = [
                'command' => 'rule:compare-legacy',
                'timestamp' => now()->toIso8601String(),
                'parameters' => ['structure_id' => $structureId, 'upload_id' => $uploadId],
                'error' => $keyMapResult['error'],
                'engine_only' => true,
                'engine_summary' => $s,
                'total_engine_rules' => $engineStats['total_rules'],
                'execution_time_ms' => $elapsed,
            ];
        } else {
            $comparison = $this->compare($legacyResults, $engineStats['details'], $keyMapResult);

            $report = [
                'command' => 'rule:compare-legacy',
                'timestamp' => now()->toIso8601String(),
                'parameters' => [
                    'structure_id' => $structureId,
                    'upload_id' => $uploadId,
                ],
                'summary' => [
                    'total_rules_in_map' => $comparison['total_rules_in_map'],
                    'match_count' => $comparison['match_count'],
                    'difference_count' => $comparison['difference_count'],
                    'match_percentage' => $comparison['total_rules_in_map'] > 0
                        ? round($comparison['match_count'] / $comparison['total_rules_in_map'] * 100, 2)
                        : 0,
                    'legacy' => $comparison['stats']['legacy'],
                    'engine' => $comparison['stats']['engine'],
                ],
                'differences' => array_slice($comparison['differences'], 0, 50),
                'execution_time_ms' => $elapsed,
            ];
        }

        if ($outputPath) {
            $dir = dirname($outputPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $report;
    }
}
