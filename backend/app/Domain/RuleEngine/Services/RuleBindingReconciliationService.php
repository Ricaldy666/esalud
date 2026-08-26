<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use Illuminate\Support\Collection;

/**
 * Clasifica reglas activas de rem_rules segun si un binding structure->
 * $targetStructure seria seguro (SAFE_1_TO_1), necesita revision humana
 * (REQUIRES_REMAP/DUPLICATE), no tiene destino (ORPHAN), esta bloqueada
 * por una deuda del evaluador independiente del binding
 * (BLOCKED_BY_ENGINE_GAP), o ya funciona sin depender de la estructura
 * (ALREADY_STRUCTURE_AGNOSTIC). Ver auditoria Fase 3 (2026-08-11).
 *
 * Solo lectura -- no persiste nada. `findSafeCandidatesForStructure()` es
 * el metodo consumido tanto por el dry-run como por el paso de escritura
 * de `rule:rebind-safe-to-structure`, para garantizar que ambos evaluan
 * exactamente la misma logica contra el estado real de la base de datos
 * en el momento en que se llaman.
 */
class RuleBindingReconciliationService
{
    public const SAFE_1_TO_1 = 'SAFE_1_TO_1';
    public const REQUIRES_REMAP = 'REQUIRES_REMAP';
    public const DUPLICATE = 'DUPLICATE';
    public const ORPHAN = 'ORPHAN';
    public const BLOCKED_BY_ENGINE_GAP = 'BLOCKED_BY_ENGINE_GAP';
    public const ALREADY_STRUCTURE_AGNOSTIC = 'ALREADY_STRUCTURE_AGNOSTIC';

    public function __construct(
        private ?RemSheetUsageStatusService $usageStatus = null,
    ) {
        $this->usageStatus = $usageStatus ?? new RemSheetUsageStatusService();
    }

    /**
     * Reglas SAFE_1_TO_1 para $targetStructure, excluyendo explicitamente
     * las hojas marcadas no_utilizada aunque estructuralmente calificaran.
     * Recalcula todo en vivo contra el estado actual -- nunca acepta una
     * lista de rule_id precomputada de una corrida anterior.
     *
     * @return Collection<int, array>
     */
    public function findSafeCandidatesForStructure(RemTemplateStructure $targetStructure): Collection
    {
        $classified = $this->classifyAllActiveRules($targetStructure);

        return $classified
            ->filter(fn (array $row) => $row['clasificacion'] === self::SAFE_1_TO_1 && !$row['hoja_no_utilizada'])
            ->values();
    }

    /**
     * Reclasifica una unica regla contra el estado actual de la BD --
     * usado como segunda verificacion inmediatamente antes de persistir
     * cada binding (protege contra un cambio concurrente de estructura o
     * de la regla entre el calculo de candidatos y la escritura real).
     */
    public function isStillSafe(Rule $rule, RemTemplateStructure $targetStructure): bool
    {
        $rule->refresh();
        $current = $this->classifyRule(
            $rule,
            $this->buildSectionIndex($targetStructure),
            $this->buildSectionsBySheet($targetStructure),
            $this->buildDuplicateKeySet(),
            $this->buildSerieOrGlobalRuleIds(),
            $targetStructure,
        );

        return $current['clasificacion'] === self::SAFE_1_TO_1 && !$current['hoja_no_utilizada'];
    }

    /**
     * @return Collection<int, array> una fila por regla activa, con su
     * clasificacion completa (misma logica para las 6 categorias).
     */
    public function classifyAllActiveRules(RemTemplateStructure $targetStructure): Collection
    {
        $sectionIndex = $this->buildSectionIndex($targetStructure);
        $sectionsBySheet = $this->buildSectionsBySheet($targetStructure);
        $dupKeySet = $this->buildDuplicateKeySet();
        $serieOrGlobalRuleIds = $this->buildSerieOrGlobalRuleIds();

        return Rule::where('status', 'active')->orderBy('id')->get()
            ->map(fn (Rule $rule) => $this->classifyRule($rule, $sectionIndex, $sectionsBySheet, $dupKeySet, $serieOrGlobalRuleIds, $targetStructure));
    }

    private function classifyRule(
        Rule $rule,
        array $sectionIndex,
        array $sectionsBySheet,
        array $dupKeySet,
        Collection $serieOrGlobalRuleIds,
        ?RemTemplateStructure $targetStructure = null,
    ): array {
        $config = $rule->config;
        $sheet = strtoupper($config['sheet'] ?? '');
        $section = strtoupper($config['section'] ?? '');

        $base = [
            'rule_id' => $rule->id,
            'rule_key' => $rule->rule_key,
            'tipo' => $rule->rule_type,
            'hoja' => $sheet,
            'seccion_antigua' => $section,
            'hoja_no_utilizada' => $this->isNoUtilizada($sheet, $targetStructure),
        ];

        if ($serieOrGlobalRuleIds->has($rule->id)) {
            return $base + [
                'clasificacion' => self::ALREADY_STRUCTURE_AGNOSTIC,
                'destino' => 'N/A', 'alcance' => $config['scope'] ?? null,
                'row_range' => $config['row_range'] ?? null, 'columnas' => null,
                'motivo' => 'Ya tiene binding serie/global activo.',
            ];
        }

        $dupKey = "{$sheet}|{$section}|" . strtoupper((string) ($config['column'] ?? '')) . "|{$rule->rule_type}";
        $isDuplicate = isset($dupKeySet[$dupKey]);

        $key = "{$sheet}|{$section}";
        $current = $sectionIndex[$key] ?? null;

        $sourceLetters = $this->deriveSourceLetters($config);
        $targetColumn = strtoupper($config['column'] ?? $config['target_column'] ?? '');
        $columnas = array_values(array_unique(array_filter(array_merge($sourceLetters, [$targetColumn]))));
        $rowRange = $config['row_range'] ?? null;

        if ($current === null) {
            $splits = array_values(array_filter($sectionsBySheet[$sheet] ?? [], fn ($c) => str_starts_with($c, $section) && $c !== $section));
            return $base + [
                'clasificacion' => self::REQUIRES_REMAP,
                'destino' => !empty($splits) ? implode('/', $splits) : null,
                'alcance' => $config['scope'] ?? null, 'row_range' => $rowRange, 'columnas' => $columnas,
                'motivo' => !empty($splits)
                    ? "Seccion dividida -- candidatos: " . implode(',', $splits)
                    : "Seccion '{$section}' no existe en la estructura destino.",
            ];
        }

        $missingColumns = array_values(array_diff($columnas, $current['fields']));
        $rowsOk = true;
        $rowsNote = '';
        // {"from":0,"to":0} es el placeholder que usan las reglas sum_equals
        // horizontales (formula dentro de la misma fila, ej. "Suma(E+G+I+K) =
        // Columna C") -- estas reglas nunca tuvieron un rango vertical real,
        // asi que {0,0} equivale a row_range ausente/null, no a un rango
        // invalido. Auditado contra las 753 reglas activas (2026-08-26): el
        // unico patron fuera de "normal" (from>0) es exactamente {0,0} --
        // no existen {0,N}/{N,0}/negativos/invertidos en los datos reales,
        // asi que esta excepcion no abre la puerta a ningun rango
        // parcialmente invalido.
        $hasRealRowRange = $rowRange !== null
            && !((int) ($rowRange['from'] ?? -1) === 0 && (int) ($rowRange['to'] ?? -1) === 0);
        if ($hasRealRowRange) {
            $from = (int) ($rowRange['from'] ?? -1);
            $to = (int) ($rowRange['to'] ?? -1);
            if ($current['inicio'] !== null && $current['fin'] !== null) {
                if ($from < $current['inicio'] || $to > $current['fin'] || $from <= 0) {
                    $rowsOk = false;
                    $rowsNote = "rango [{$from}:{$to}] fuera de o invalido contra [{$current['inicio']}:{$current['fin']}]";
                }
            }
        }

        $isVerticalPattern = count($sourceLetters) === 1 && $targetColumn !== '' && $sourceLetters[0] === $targetColumn && $rowRange !== null;
        $engineGap = null;
        if ($isVerticalPattern) {
            if (!isset($config['total_row'])) {
                $engineGap = 'invalid_row_range_configuration: falta total_row en config.';
            } else {
                $totalRow = (int) $config['total_row'];
                if ($current['fin'] !== null && ($totalRow > $current['fin'] || $totalRow < ($current['inicio'] ?? 0))) {
                    $engineGap = "missing_total_row probable: total_row={$totalRow} fuera de [{$current['inicio']}:{$current['fin']}].";
                }
            }
        }

        $destino = "{$current['sheetName']}/{$current['codigo']}";

        if ($isDuplicate) {
            $clasificacion = self::DUPLICATE;
            $motivo = 'Parte de un grupo de reglas equivalentes (mismo sheet+seccion+columna+tipo).';
        } elseif (!empty($missingColumns)) {
            $clasificacion = self::REQUIRES_REMAP;
            $motivo = 'Columnas [' . implode(',', $missingColumns) . '] ya no existen en la seccion actual.';
        } elseif (!$rowsOk) {
            $clasificacion = self::REQUIRES_REMAP;
            $motivo = "Rango de filas desactualizado: {$rowsNote}.";
        } elseif ($engineGap !== null) {
            $clasificacion = self::BLOCKED_BY_ENGINE_GAP;
            $motivo = $engineGap;
        } else {
            $clasificacion = self::SAFE_1_TO_1;
            $motivo = 'Misma hoja, seccion, columnas y rango de filas vigentes.';
        }

        return $base + [
            'clasificacion' => $clasificacion, 'destino' => $destino,
            'alcance' => $config['scope'] ?? null, 'row_range' => $rowRange, 'columnas' => $columnas,
            'motivo' => $motivo,
        ];
    }

    private function isNoUtilizada(string $sheetUpper, ?RemTemplateStructure $structure): bool
    {
        if ($structure === null) return false;

        $sheetNameOriginal = null;
        $est = $this->parseEstructura($structure);
        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper($form['sheetName']) === $sheetUpper) { $sheetNameOriginal = $form['sheetName']; break; }
        }
        if ($sheetNameOriginal === null) return false;

        return $this->usageStatus->getStatusFor((int) $structure->anio, $structure->serie, $sheetNameOriginal)
            === RemSheetUsageStatusService::STATUS_NO_UTILIZADA;
    }

    private function buildSectionIndex(RemTemplateStructure $structure): array
    {
        $est = $this->parseEstructura($structure);
        $index = [];
        foreach ($est['forms'] ?? [] as $form) {
            $sheet = strtoupper($form['sheetName']);
            foreach ($form['sections'] ?? [] as $section) {
                $codigoUpper = strtoupper($section['codigo']);
                $index["{$sheet}|{$codigoUpper}"] = [
                    'fields' => array_map(fn ($f) => strtoupper($f['letra']), $section['fields'] ?? []),
                    'inicio' => $section['filaInicioDatos'] ?? null,
                    'fin' => $section['filaFinDatos'] ?? null,
                    'sheetName' => $form['sheetName'],
                    'codigo' => $section['codigo'],
                ];
            }
        }
        return $index;
    }

    private function buildSectionsBySheet(RemTemplateStructure $structure): array
    {
        $est = $this->parseEstructura($structure);
        $bySheet = [];
        foreach ($est['forms'] ?? [] as $form) {
            $sheet = strtoupper($form['sheetName']);
            foreach ($form['sections'] ?? [] as $section) {
                $bySheet[$sheet][] = strtoupper($section['codigo']);
            }
        }
        return $bySheet;
    }

    private function buildDuplicateKeySet(): array
    {
        $groups = Rule::selectRaw("JSON_UNQUOTE(JSON_EXTRACT(config,'$.sheet')) as sheet, JSON_UNQUOTE(JSON_EXTRACT(config,'$.section')) as section, JSON_UNQUOTE(JSON_EXTRACT(config,'$.column')) as col, rule_type, count(*) as c")
            ->where('status', 'active')
            ->groupBy('sheet', 'section', 'col', 'rule_type')
            ->having('c', '>', 1)
            ->get();

        $set = [];
        foreach ($groups as $g) {
            $set[strtoupper($g->sheet) . '|' . strtoupper($g->section) . '|' . strtoupper((string) $g->col) . '|' . $g->rule_type] = true;
        }
        return $set;
    }

    private function buildSerieOrGlobalRuleIds(): Collection
    {
        return RuleBinding::whereIn('bindable_type', ['serie', 'global'])
            ->where('active', true)
            ->pluck('rule_id')
            ->unique()
            ->flip();
    }

    private function deriveSourceLetters(array $config): array
    {
        if (!empty($config['source_letters'])) return array_map('strtoupper', $config['source_letters']);
        if (isset($config['rule_logic']) && preg_match('/\(([^)]+)\)/', $config['rule_logic'], $m)) {
            $parts = explode('+', str_replace(' ', '', $m[1]));
            return array_map('strtoupper', array_filter($parts));
        }
        return [];
    }

    private array $structureCache = [];

    private function parseEstructura(RemTemplateStructure $structure): array
    {
        if (isset($this->structureCache[$structure->id])) {
            return $this->structureCache[$structure->id];
        }
        $est = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        return $this->structureCache[$structure->id] = ($est ?? []);
    }
}
