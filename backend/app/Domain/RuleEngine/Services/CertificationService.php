<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class CertificationService
{
    private const CERT_DIR = 'certificacion';
    private const CERT_FILE = 'serie-a-catalogo.json';

    // BM-2 (2026-09-11): keyeado por $structure->id (antes un solo slot sin
    // key) -- una misma instancia de servicio resolviendo dos series
    // distintas en el mismo ciclo de vida (ej. dos requests reutilizando el
    // mismo objeto en un test, o un futuro consumidor que compare series)
    // ya NO puede devolver silenciosamente la estructura de la primera
    // serie cacheada para la segunda. Ver parseEstructura() abajo.
    private array $structureDataByStructureId = [];

    // string $serie = 'A' (BM-2, 2026-09-11): compatibilidad historica --
    // ningun consumidor actual (CatalogController) pasa todavia otra serie.
    public function getRules(array $filters = [], string $serie = 'A'): Collection
    {
        $structure = $this->getActiveStructure($serie);
        if (!$structure) {
            return collect();
        }

        $ruleIds = RuleBinding::where('bindable_type', 'structure')
            ->where('bindable_id', $structure->id)
            ->where('active', true)
            ->pluck('rule_id');

        $query = Rule::whereIn('id', $ruleIds)->where('status', 'active');

        if (!empty($filters['sheet'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('config->sheet', $filters['sheet']);
            });
        }

        if (!empty($filters['rule_key'])) {
            $query->where('rule_key', $filters['rule_key']);
        }

        if (!empty($filters['rule_type'])) {
            $query->where('rule_type', $filters['rule_type']);
        }

        return $query->orderBy('rule_key')->get();
    }

    public function buildCertificationCard(Rule $rule, string $serie = 'A'): array
    {
        $config = $rule->config ?? [];
        $structure = $this->getActiveStructure($serie);
        $structureArr = $structure ? $this->parseEstructura($structure) : null;
        $sheet = $config['sheet'] ?? $this->extractSheetFromKey($rule->rule_key);
        $section = $config['section'] ?? $this->extractSectionFromKey($rule->rule_key);

        $structureEvidence = $this->findStructureEvidence($rule, $structureArr);
        $sourceColumns = $this->getSourceColumns($rule, $structureEvidence);
        $destColumn = $this->getDestColumn($rule);
        $rowRange = $this->getRowRange($rule);
        $interpretation = $this->interpretFormula($rule, $sourceColumns, $destColumn, $rowRange);

        $certStatus = $this->loadCertificationStatus();
        $existing = $certStatus[$rule->rule_key] ?? null;

        return [
            'rule_key' => $rule->rule_key,
            'rule_type' => $rule->rule_type,
            'severity' => $rule->severity,
            'description' => $rule->description,
            'hoja' => strtoupper($sheet),
            'seccion' => $section,
            'columnas_origen' => $sourceColumns,
            'columna_destino' => $destColumn,
            'rango_filas' => $rowRange,
            'formula_interpretada' => $interpretation,
            'evidencia_xlsm' => $structureEvidence,
            'evidencia_manual_rem' => '',
            'estado' => $existing['estado'] ?? 'Pendiente',
            'observaciones' => $existing['observaciones'] ?? '',
            'certificado_por' => $existing['certificado_por'] ?? '',
            'certificado_en' => $existing['certificado_en'] ?? null,
        ];
    }

    public function interpretFormula(Rule $rule, array $sourceColumns = [], ?string $destColumn = null, ?string $rowRangeStr = null): string
    {
        $config = $rule->config ?? [];
        $type = $rule->rule_type;

        if (!empty($config['rule_logic'])) {
            return $config['rule_logic'];
        }

        $column = $destColumn ?? $config['column'] ?? '';

        if ($type === 'sum_equals') {
            $rangeStr = $rowRangeStr ?? '';
            if (!empty($sourceColumns)) {
                $colsStr = implode(' + ', $sourceColumns);
                $result = "Suma({$colsStr}) = Columna {$column}";
            } else {
                $result = "Suma = Columna {$column}";
            }
            if ($rangeStr) $result .= " ({$rangeStr})";
            return $result;
        }

        if ($type === 'required_and_le_parent') {
            $childCol = $config['child_column'] ?? $config['column'] ?? '';
            $parentCol = $config['parent_column'] ?? '';
            $rangeStr = $rowRangeStr ?? '';
            $parentLabel = $parentCol ? "Columna {$parentCol}" : 'Total';
            $result = "Columna {$childCol} es requerida y debe ser ≤ {$parentLabel}";
            if ($rangeStr) $result .= " ({$rangeStr})";
            return $result;
        }

        return $rule->description;
    }

    public function findStructureEvidence(Rule $rule, ?array $structureArr): ?array
    {
        if (!$structureArr) return null;

        $config = $rule->config ?? [];
        $sheet = $config['sheet'] ?? $this->extractSheetFromKey($rule->rule_key);
        $section = $config['section'] ?? $this->extractSectionFromKey($rule->rule_key);
        $column = $config['column'] ?? $this->extractColumnFromKey($rule->rule_key);

        $forms = $structureArr['forms'] ?? [];

        foreach ($forms as $form) {
            if (strtoupper($form['sheetName'] ?? '') !== strtoupper($sheet)) continue;

            foreach ($form['sections'] ?? [] as $sec) {
                $secCodigo = $sec['codigo'] ?? '';
                if (strtolower(trim($secCodigo)) !== strtolower(trim($section))) continue;

                foreach ($sec['fields'] ?? [] as $field) {
                    $letra = $field['letra'] ?? '';
                    if (strtolower(trim($letra)) !== strtolower(trim($column))) continue;

                    $regla = $field['reglaDetectada'] ?? null;
                    if (!$regla) {
                        return [
                            'encontrada' => true,
                            'titulo_seccion' => $sec['titulo'] ?? '',
                            'label_columna' => $field['label'] ?? '',
                            'es_total' => $field['esTotal'] ?? false,
                            'es_control_oculto' => $field['esControlOculto'] ?? false,
                            'regla_detectada' => null,
                        ];
                    }

                    return [
                        'encontrada' => true,
                        'titulo_seccion' => $sec['titulo'] ?? '',
                        'label_columna' => $field['label'] ?? '',
                        'es_total' => $field['esTotal'] ?? false,
                        'es_control_oculto' => $field['esControlOculto'] ?? false,
                        'regla_detectada' => [
                            'tipo' => $regla['tipo'] ?? $regla,
                            'columnas_origen' => $regla['columnasOrigen'] ?? [],
                            'columna_destino' => $regla['columnaDestino'] ?? null,
                            'rango_filas' => $regla['rangoFilas'] ?? null,
                        ],
                    ];
                }
            }
        }

        return null;
    }

    public function loadCertificationStatus(): array
    {
        $path = self::CERT_DIR . '/' . self::CERT_FILE;
        if (!Storage::disk('local')->exists($path)) {
            return [];
        }
        return json_decode(Storage::disk('local')->get($path), true) ?? [];
    }

    public function saveCertificationStatus(string $ruleKey, string $estado, string $observaciones = '', string $certificadoPor = ''): void
    {
        $certStatus = $this->loadCertificationStatus();
        $certStatus[$ruleKey] = [
            'estado' => $estado,
            'observaciones' => $observaciones,
            'certificado_por' => $certificadoPor,
            'certificado_en' => now()->toIso8601String(),
        ];

        $path = self::CERT_DIR . '/' . self::CERT_FILE;
        Storage::disk('local')->put($path, json_encode($certStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function getStats(string $serie = 'A'): array
    {
        $totalRules = $this->getRules([], $serie)->count();
        $certStatus = $this->loadCertificationStatus();
        $certificadas = 0;
        $requiereRevision = 0;

        foreach ($certStatus as $status) {
            match ($status['estado'] ?? 'Pendiente') {
                'Certificada' => $certificadas++,
                'Requiere revisión' => $requiereRevision++,
                default => 0,
            };
        }

        $pendientes = $totalRules - $certificadas - $requiereRevision;

        return [
            'total' => $totalRules,
            'pendientes' => max(0, $pendientes),
            'certificadas' => $certificadas,
            'requiere_revision' => $requiereRevision,
        ];
    }

    public function getAvailableSheets(string $serie = 'A'): array
    {
        $structure = $this->getActiveStructure($serie);
        if (!$structure) return [];
        $est = $this->parseEstructura($structure);
        $names = array_map(fn($f) => $f['sheetName'] ?? '', $est['forms'] ?? []);
        $names = array_filter($names);
        sort($names);
        return array_values($names);
    }

    public function getRuleByKey(string $ruleKey, string $serie = 'A'): ?Rule
    {
        return $this->getRules(['rule_key' => $ruleKey], $serie)->first();
    }

    public function getSectionRules(string $sheet, string $section, string $serie = 'A'): array
    {
        $rules = $this->getRules(['sheet' => $sheet], $serie);
        $sectionRules = $rules->filter(function ($rule) use ($section) {
            $config = $rule->config ?? [];
            $ruleSection = $config['section'] ?? $this->extractSectionFromKey($rule->rule_key);
            return strtolower(trim($ruleSection)) === strtolower(trim($section));
        });

        $cards = [];
        foreach ($sectionRules as $rule) {
            $cards[] = $this->buildCertificationCard($rule, $serie);
        }

        usort($cards, function ($a, $b) {
            $rowA = $this->extractRowNumber($a['rango_filas'] ?? '');
            $rowB = $this->extractRowNumber($b['rango_filas'] ?? '');
            return $rowA <=> $rowB;
        });

        return $cards;
    }

    public function getSectionInfo(string $sheet, string $section, string $serie = 'A'): ?array
    {
        $structure = $this->getActiveStructure($serie);
        if (!$structure) return null;
        $est = $this->parseEstructura($structure);

        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper($form['sheetName'] ?? '') !== strtoupper($sheet)) continue;
            foreach ($form['sections'] ?? [] as $sec) {
                if (strtolower(trim($sec['codigo'] ?? '')) === strtolower(trim($section))) {
                    $fields = $sec['fields'] ?? [];
                    $rulesDetected = 0;
                    foreach ($fields as $f) {
                        if (!empty($f['reglaDetectada'])) $rulesDetected++;
                    }
                    return [
                        'codigo' => $sec['codigo'] ?? $section,
                        'titulo' => $sec['titulo'] ?? '',
                        'fila_inicio' => $sec['filaInicioDatos'] ?? null,
                        'fila_fin' => $sec['filaFinDatos'] ?? null,
                        'total_campos' => count($fields),
                        'reglas_detectadas_estructura' => $rulesDetected,
                        'columnas' => array_map(fn($f) => [
                            'letra' => $f['letra'] ?? '',
                            'label' => $f['label'] ?? '',
                            'es_total' => $f['esTotal'] ?? false,
                            'es_control_oculto' => $f['esControlOculto'] ?? false,
                        ], $fields),
                    ];
                }
            }
        }
        return null;
    }

    public function getStructureForCard(string $serie = 'A'): ?array
    {
        $structure = $this->getActiveStructure($serie);
        if (!$structure) return null;
        return [
            'hash' => $structure->hash_estructura,
            'version' => $structure->version_number,
            'anio' => $structure->anio,
            'serie' => $structure->serie,
        ];
    }

    public function exportAllCards(string $serie = 'A'): array
    {
        $rules = $this->getRules([], $serie);
        $cards = [];
        foreach ($rules as $rule) {
            $cards[] = $this->buildCertificationCard($rule, $serie);
        }
        return $cards;
    }

    // string $serie (BM-2, 2026-09-11): antes hardcodeado a 'A' (anio se
    // mantiene fijo a 2026 -- fuera del alcance de esta generalizacion,
    // explicitamente acotada a serie). Nunca cae a Serie A si se pide otra
    // serie: la consulta filtra exactamente por $serie y simplemente
    // devuelve null si no hay estructura activa para ella.
    private function getActiveStructure(string $serie = 'A'): ?RemTemplateStructure
    {
        return RemTemplateStructure::where('anio', 2026)
            ->where('serie', $serie)
            ->where('status', 'active')
            ->first();
    }

    // BM-2 (2026-09-11): cache keyeada por $structure->id -- ver
    // $structureDataByStructureId arriba. Antes: un solo slot compartido
    // por toda la instancia, sin importar que estructura se pidiera.
    private function parseEstructura(RemTemplateStructure $structure): ?array
    {
        if (isset($this->structureDataByStructureId[$structure->id])) {
            return $this->structureDataByStructureId[$structure->id];
        }
        $est = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        $this->structureDataByStructureId[$structure->id] = $est;
        return $est;
    }

    private function getSourceColumns(Rule $rule, ?array $evidence = null): array
    {
        // Priority 1: evidence from XLSM structure
        if ($evidence && $evidence['encontrada'] && $evidence['regla_detectada']) {
            $cols = $evidence['regla_detectada']['columnas_origen'] ?? [];
            if (!empty($cols)) {
                $letters = array_map(fn($c) => preg_replace('/\d+$/', '', strtoupper($c)), $cols);
                $unique = array_unique($letters);
                if (count($unique) === 1) {
                    $range = $evidence['regla_detectada']['rango_filas'] ?? '';
                    return [reset($unique) . ($range ? " ({$range})" : '')];
                }
                return array_values($unique);
            }
        }

        // Priority 2: config columns
        $config = $rule->config ?? [];
        $columns = $config['columns'] ?? [];
        if (!empty($columns)) {
            $upper = array_map('strtoupper', $columns);
            $unique = array_unique($upper);
            return array_values($unique);
        }

        // Priority 3: extract from rule_logic
        $logic = $config['rule_logic'] ?? '';
        if (preg_match('/Suma\((.+?)\)/', $logic, $m)) {
            $parts = array_map('trim', explode(' + ', $m[1]));
            $upper = array_map('strtoupper', $parts);
            $unique = array_unique($upper);
            return array_values($unique);
        }

        return [];
    }

    private function getDestColumn(Rule $rule): ?string
    {
        $config = $rule->config ?? [];
        return $config['column']
            ?? $config['child_column']
            ?? $config['columnaDestino']
            ?? ($config['columnasDestino'][0] ?? null);
    }

    private function getRowRange(Rule $rule): ?string
    {
        $config = $rule->config ?? [];
        $range = $config['row_range'] ?? [];
        if (!empty($range['from']) && !empty($range['to'])) {
            return $range['from'] === $range['to']
                ? "Fila {$range['from']}"
                : "Filas {$range['from']}–{$range['to']}";
        }
        if (!empty($config['rangoFilas'])) {
            return $config['rangoFilas'];
        }

        // BM-11.15: cross_sheet_equals (y cualquier config futura sin
        // row_range/rangoFilas, per-celda por diseño) no declara un rango de
        // filas -- se deriva la fila unica desde la coordenada de origen
        // (config.source.cell, ej. "C42" -> fila 42). Generico para
        // cualquier serie/hoja/seccion; Serie A nunca tiene este shape de
        // config (siempre row_range/rangoFilas), asi que esta rama nunca se
        // activa para ninguna de sus reglas.
        $sourceCell = $config['source']['cell'] ?? null;
        if (is_string($sourceCell) && preg_match('/^[A-Z]+(\d+)$/', $sourceCell, $m)) {
            return "Fila {$m[1]}";
        }

        return null;
    }

    private function extractRowNumber(?string $rangeStr): int
    {
        if (!$rangeStr) return 9999;
        if (preg_match('/Fila[s]?\s+(\d+)/', $rangeStr, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/Fila[s]?\s+(\d+)–(\d+)/', $rangeStr, $m)) {
            return (int) $m[1];
        }
        return 9999;
    }

    private function extractSheetFromKey(string $key): string
    {
        if (preg_match('/^([a-z0-9]+)_/', $key, $m)) {
            return strtoupper($m[1]);
        }
        return '?';
    }

    private function extractSectionFromKey(string $key): string
    {
        $parts = explode('_', $key);
        return $parts[1] ?? '?';
    }

    private function extractColumnFromKey(string $key): string
    {
        $parts = explode('_', $key);
        return $parts[2] ?? '?';
    }
}
