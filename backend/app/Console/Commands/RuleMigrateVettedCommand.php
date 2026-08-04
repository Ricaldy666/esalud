<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Domain\RuleEngine\Services\RuleKeyGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RuleMigrateVettedCommand extends Command
{
    protected $signature = 'rule:migrate-vetted
                            {--structure=21 : ID de estructura destino}
                            {--dry-run : Mostrar lo que se haría sin escribir en DB}';

    protected $description = 'Migra reglas A01 validadas desde archivos JSON fuente a rem_rules';

    private const SOURCE_FILES = [
        'sum_equals' => 'database/seeders/data/reglas_sum_equals_CORREGIDAS.json',
        'required_and_le_parent' => 'database/seeders/data/reglas_json_required_and_le_parent.json',
        'sum2col' => 'database/seeders/data/reglas_json_sum2col.json',
    ];

    private array $fieldToSection = [];
    private array $sectionByRow = [];
    private array $sectionTotalField = [];

    public function handle(): int
    {
        $structureId = (int) $this->option('structure');
        $dryRun = $this->option('dry-run');

        $structure = RemTemplateStructure::withTrashed()->find($structureId);
        if (!$structure) {
            $this->error("Estructura ID {$structureId} no encontrada.");
            return self::FAILURE;
        }

        $this->buildFieldSectionMap($structure);

        $allRules = $this->loadRules();
        $this->line("Cargadas {$allRules['total']} reglas totales de archivos fuente.");
        $this->line("Filtradas A01: {$allRules['a01_count']}");

        if ($allRules['a01_count'] === 0) {
            $this->warn("No hay reglas A01 para migrar.");
            return self::SUCCESS;
        }

        $stats = $this->migrate($allRules['a01_rules'], $structureId, $structure, $dryRun);

        $this->renderStats($stats);

        return self::SUCCESS;
    }

    private function buildFieldSectionMap(RemTemplateStructure $structure): void
    {
        $est = $structure->estructura;
        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper($form['sheetName'] ?? '') !== 'A01') continue;
            foreach ($form['sections'] ?? [] as $sec) {
                $codigo = $sec['codigo'] ?? '?';
                $filaInicio = (int) ($sec['filaInicioDatos'] ?? 0);
                $filaFin = (int) ($sec['filaFinDatos'] ?? 0);

                $this->sectionByRow[] = [
                    'section' => $codigo,
                    'row_from' => $filaInicio,
                    'row_to' => $filaFin,
                ];

                foreach ($sec['fields'] ?? [] as $field) {
                    $letra = strtolower($field['letra'] ?? '');
                    if ($letra === '') continue;
                    $this->fieldToSection[$letra][] = [
                        'section' => $codigo,
                        'row_from' => $filaInicio,
                        'row_to' => $filaFin,
                    ];
                    if (($field['esTotal'] ?? false) && !isset($this->sectionTotalField[$codigo])) {
                        $this->sectionTotalField[$codigo] = $letra;
                    }
                }
            }
        }

        usort($this->sectionByRow, fn($a, $b) => $a['row_from'] <=> $b['row_from']);
    }

    private function findSection(string $column, int $rowFrom): string
    {
        $candidates = $this->fieldToSection[strtolower($column)] ?? [];

        if (count($candidates) === 1) return $candidates[0]['section'];

        if (count($candidates) > 1) {
            foreach ($candidates as $c) {
                if ($rowFrom >= $c['row_from'] && $rowFrom <= $c['row_to']) {
                    return $c['section'];
                }
            }
            return $candidates[0]['section'];
        }

        foreach ($this->sectionByRow as $sec) {
            if ($rowFrom >= $sec['row_from'] && $rowFrom <= $sec['row_to']) {
                return $sec['section'];
            }
        }

        return '?';
    }

    private function loadRules(): array
    {
        $a01Rules = [];
        $total = 0;

        foreach (self::SOURCE_FILES as $sourceType => $relativePath) {
            $path = base_path($relativePath);
            if (!file_exists($path)) {
                $this->warn("Archivo no encontrado: {$path}");
                continue;
            }
            $data = json_decode(file_get_contents($path), true);
            if (!is_array($data)) continue;

            foreach ($data as $rule) {
                $total++;
                if (($rule['section'] ?? '') !== 'A01') continue;
                $rule['_source_file'] = $sourceType;
                $a01Rules[] = $rule;
            }
        }

        return [
            'total' => $total,
            'a01_count' => count($a01Rules),
            'a01_rules' => $a01Rules,
        ];
    }

    private function buildConfig(array $ruleData): array
    {
        $type = $ruleData['type'];
        $rowFrom = $ruleData['row_range']['from'] ?? 0;
        $rowTo = $ruleData['row_range']['to'] ?? 0;

        $scope = $rowFrom !== $rowTo ? 'row_range' : 'per_row';

        $config = [
            'tipo' => $type,
            'scope' => $scope,
            'row_from' => $rowFrom,
            'row_to' => $rowTo,
            'rango_filas' => "{$rowFrom}:{$rowTo}",
            'row_range' => ['from' => $rowFrom, 'to' => $rowTo],
            'sheet' => 'A01',
        ];

        if ($type === 'sum_equals') {
            $sourceColumns = $ruleData['source_columns'] ?? [];
            $targetField = $ruleData['target_field'] ?? '?';

            if (strtolower($targetField) === 'total' || $targetField === '?') {
                $section = $this->findSectionByRow($rowFrom);
                $config['section'] = $section;
                $totalCol = strtoupper($this->sectionTotalField[$section] ?? '?');
                $config['column'] = $totalCol;
                $config['target_column'] = $totalCol;
                $config['columna_destino'] = $totalCol;
            } else {
                $config['section'] = $this->findSection($targetField, $rowFrom);
                $config['column'] = $targetField;
                $config['target_column'] = $targetField;
                $config['columna_destino'] = $targetField;
            }

            $config['source_columns'] = $sourceColumns;
            $config['source_letters'] = $sourceColumns;
            $config['columns'] = $sourceColumns;

        } elseif ($type === 'required_and_le_parent') {
            $childColumn = $ruleData['child_column'] ?? '?';
            $parentColumn = $ruleData['parent_column'] ?? 'total';
            $section = $this->findSection($childColumn, $rowFrom);

            // Resolve "total" to the actual TOTAL column letter of the section
            $resolvedParent = strtolower($parentColumn) === 'total'
                ? strtoupper($this->sectionTotalField[$section] ?? '?')
                : $parentColumn;

            $config['source_columns'] = [$resolvedParent];
            $config['source_letters'] = [$resolvedParent];
            $config['columns'] = [$resolvedParent];
            $config['column'] = $childColumn;
            $config['target_column'] = $childColumn;
            $config['columna_destino'] = $childColumn;
            $config['child_column'] = $childColumn;
            $config['parent_column'] = $resolvedParent;
            $config['section'] = $section;
        }

        return $config;
    }

    private function findSectionByRow(int $row): string
    {
        foreach ($this->sectionByRow as $sec) {
            if ($row >= $sec['row_from'] && $row <= $sec['row_to']) {
                return $sec['section'];
            }
        }
        return '?';
    }

    private function buildDescription(array $ruleData, array $config): string
    {
        $type = $ruleData['type'];
        $key = $ruleData['key'];
        $section = $config['section'] ?? '?';
        $column = $config['column'] ?? '?';
        $range = $config['rango_filas'] ?? '?';

        $desc = "A01/{$section} columna {$column} ({$type})";
        if ($type === 'sum_equals') {
            $src = implode(', ', $ruleData['source_columns'] ?? []);
            $desc .= " origen={$src}";
        }
        if ($type === 'required_and_le_parent') {
            $desc .= " child={$column} parent=" . ($ruleData['parent_column'] ?? 'total');
        }
        $desc .= " filas={$range}";
        return $desc;
    }

    private function migrate(array $rules, int $structureId, RemTemplateStructure $structure, bool $dryRun): array
    {
        $stats = [
            'created' => 0,
            'reused' => 0,
            'bindings_created' => 0,
            'bindings_skipped' => 0,
            'errors' => [],
        ];

        $serie = $structure->serie ?? 'A';
        $anio = $structure->anio ?? 2026;
        $version = $structure->version_number ?? 2;
        $versionStr = "{$version}.0.0";
        $sourceFilename = $structure->source_filename ?? '';

        DB::beginTransaction();

        try {
            foreach ($rules as $ruleData) {
                $ruleKey = $ruleData['key'];
                $type = $ruleData['type'];

                try {
                    $config = $this->buildConfig($ruleData);
                    $description = $this->buildDescription($ruleData, $config);

                    if ($dryRun) {
                        $this->line("  [DRY-RUN] {$ruleKey} -> section={$config['section']}, column={$config['column']}, rows={$config['rango_filas']}");
                        continue;
                    }

                    $existing = Rule::where('rule_key', $ruleKey)->first();

                    if ($existing) {
                        $existing->update([
                            'rule_type' => $type,
                            'config' => $config,
                            'description' => $description,
                            'severity' => $ruleData['severity'] ?? 'error',
                            'version' => $versionStr,
                            'updated_by' => null,
                        ]);
                        $stats['reused']++;
                        $ruleModel = $existing;
                    } else {
                        $ruleModel = Rule::create([
                            'rule_key' => $ruleKey,
                            'rule_type' => $type,
                            'source' => 'vetted_catalog',
                            'name' => "A01/{$config['section']} {$config['column']}: {$type}",
                            'description' => $description,
                            'category' => null,
                            'severity' => $ruleData['severity'] ?? 'error',
                            'scope' => $config['scope'] ?? 'per_row',
                            'config' => $config,
                            'status' => 'active',
                            'version' => $versionStr,
                            'metadata' => [
                                'source_structure_id' => $structureId,
                                'source_filename' => $sourceFilename,
                                'sheet' => 'A01',
                                'section' => $config['section'],
                                'column' => $config['column'],
                                'rule_key' => $ruleKey,
                            ],
                            'created_by' => null,
                            'updated_by' => null,
                        ]);

                        RuleVersion::create([
                            'rule_id' => $ruleModel->id,
                            'version' => $versionStr,
                            'config' => $config,
                            'changelog' => 'Migrada desde catálogo curado A01',
                            'created_by' => null,
                        ]);

                        $stats['created']++;
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
                    } else {
                        $stats['bindings_skipped']++;
                    }

                } catch (\Exception $e) {
                    $stats['errors'][] = "{$ruleKey}: {$e->getMessage()}";
                    $this->warn("  Error en {$ruleKey}: {$e->getMessage()}");
                }
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error fatal en transacción: {$e->getMessage()}");
            throw $e;
        }

        return $stats;
    }

    private function renderStats(array $stats): void
    {
        $this->newLine();
        $this->info('=== RESULTADOS DE MIGRACIÓN ===');
        $this->line("  Creadas:     {$stats['created']}");
        $this->line("  Reutilizadas: {$stats['reused']}");
        $this->line("  Bindings nuevos: {$stats['bindings_created']}");
        $this->line("  Bindings existentes: {$stats['bindings_skipped']}");

        if (!empty($stats['errors'])) {
            $this->newLine();
            $this->warn('  Errores: ' . count($stats['errors']));
            foreach ($stats['errors'] as $e) {
                $this->line("    ❌ {$e}");
            }
        }
    }
}
