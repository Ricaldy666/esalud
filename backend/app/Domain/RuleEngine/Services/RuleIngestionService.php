<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use Illuminate\Support\Facades\DB;

class RuleIngestionService
{
    public function __construct(
        private RuleKeyGeneratorService $keyGenerator,
        private RuleConfigNormalizerService $configNormalizer,
    ) {}

    public function ingest(int $structureId): array
    {
        $structure = RemTemplateStructure::withTrashed()->findOrFail($structureId);
        $est = $structure->estructura;
        $version = $structure->version_number;
        $anio = $structure->anio;
        $serie = $structure->serie;
        $sourceFilename = $structure->source_filename;

        $stats = [
            'structure_id' => $structureId,
            'anio' => $anio,
            'serie' => $serie,
            'version' => $version,
            'total_detected' => 0,
            'skipped_control_oculto' => 0,
            'created' => 0,
            'reused' => 0,
            'bindings_created' => 0,
            'distribution' => [],
        ];

        $rulesToIngest = [];

        foreach ($est['forms'] ?? [] as $form) {
            $sheet = $form['sheetName'] ?? '?';

            foreach ($form['sections'] ?? [] as $section) {
                $sectionCodigo = $section['codigo'] ?? 'IMPLICITA';

                foreach ($section['fields'] ?? [] as $field) {
                    $regla = $field['reglaDetectada'] ?? null;
                    if ($regla === null) {
                        continue;
                    }

                    $stats['total_detected']++;

                    $tipo = is_array($regla) ? ($regla['tipo'] ?? '') : $regla;

                    if ($tipo === 'control_oculto') {
                        $stats['skipped_control_oculto']++;
                        continue;
                    }

                    $letra = $field['letra'] ?? '?';
                    $columnasOrigen = is_array($regla) ? ($regla['columnasOrigen'] ?? []) : [];
                    $columnaDestino = is_array($regla) ? ($regla['columnaDestino'] ?? null) : null;
                    $rangoFilas = is_array($regla) ? ($regla['rangoFilas'] ?? null) : null;

                    $ruleKey = $this->keyGenerator->generate(
                        $sheet, $sectionCodigo, $letra, $tipo
                    );

                    $stats['distribution'][$tipo] = ($stats['distribution'][$tipo] ?? 0) + 1;

                    $rulesToIngest[] = [
                        'rule_key' => $ruleKey,
                        'sheet' => $sheet,
                        'section' => $sectionCodigo,
                        'letra' => $letra,
                        'tipo' => $tipo,
                        'columnasOrigen' => $columnasOrigen,
                        'columnaDestino' => $columnaDestino,
                        'rangoFilas' => $rangoFilas,
                        'label' => $field['label'] ?? '',
                    ];
                }
            }
        }

        DB::transaction(function () use ($rulesToIngest, $structureId, $anio, $serie, $version, $sourceFilename, &$stats) {
            foreach ($rulesToIngest as $ruleData) {
                $existing = Rule::where('rule_key', $ruleData['rule_key'])->first();

                $config = $this->configNormalizer->normalize(
                    $ruleData['tipo'],
                    $ruleData['columnasOrigen'],
                    $ruleData['columnaDestino'],
                    $ruleData['rangoFilas'],
                    $ruleData['letra'],
                );

                $description = "Detectada de Excel: {$ruleData['sheet']}/{$ruleData['section']}, ";
                $description .= "columna {$ruleData['letra']} ({$ruleData['label']}), ";
                $description .= "tipo {$ruleData['tipo']}";
                if ($ruleData['rangoFilas']) {
                    $description .= ", filas {$ruleData['rangoFilas']}";
                }

                $severity = match ($ruleData['tipo']) {
                    'sum_equals' => 'error',
                    'required_and_le_parent' => 'warning',
                    default => 'error',
                };

                $scope = 'per_row';
                if ($ruleData['rangoFilas'] !== null && preg_match('/^(\d+):(\d+)$/', $ruleData['rangoFilas'], $m)) {
                    $scope = ((int) $m[1] !== (int) $m[2]) ? 'row_range' : 'per_row';
                }

                if ($existing) {
                    $existing->update([
                        'version' => "{$version}.0.0",
                        'updated_by' => null,
                    ]);
                    $ruleModel = $existing;
                    $stats['reused']++;
                } else {
                    $ruleModel = Rule::create([
                        'rule_key' => $ruleData['rule_key'],
                        'rule_type' => $ruleData['tipo'],
                        'source' => 'excel_formula',
                        'name' => "{$ruleData['sheet']}/{$ruleData['section']} {$ruleData['letra']}: {$ruleData['tipo']}",
                        'description' => $description,
                        'category' => null,
                        'severity' => $severity,
                        'scope' => $scope,
                        'config' => $config,
                        'status' => 'active',
                        'version' => "{$version}.0.0",
                        'metadata' => [
                            'source_structure_id' => $structureId,
                            'source_filename' => $sourceFilename,
                            'sheet' => $ruleData['sheet'],
                            'section' => $ruleData['section'],
                            'letra' => $ruleData['letra'],
                            'label' => $ruleData['label'],
                        ],
                        'created_by' => null,
                        'updated_by' => null,
                    ]);
                    $stats['created']++;

                    RuleVersion::create([
                        'rule_id' => $ruleModel->id,
                        'version' => "{$version}.0.0",
                        'config' => $config,
                        'changelog' => 'Ingestada desde estructura REM detectada por Excel',
                        'created_by' => null,
                    ]);
                }

                $existingBinding = RuleBinding::where('rule_id', $ruleModel->id)
                    ->where('bindable_type', 'structure')
                    ->where('bindable_id', $structureId)
                    ->exists();

                if (!$existingBinding) {
                    RuleBinding::create([
                        'rule_id' => $ruleModel->id,
                        'bindable_type' => 'structure',
                        'bindable_id' => $structureId,
                        'serie' => $serie,
                        'anio' => $anio,
                        'active' => true,
                    ]);
                    $stats['bindings_created']++;
                }
            }
        });

        return $stats;
    }
}
