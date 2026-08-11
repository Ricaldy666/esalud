<?php

namespace App\Domain\RuleEngine\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class FunctionalRuleService
{
    private const STORAGE_DIR = 'certificacion';
    private const STORAGE_FILE = 'reglas-funcionales.json';

    public function getFunctionalRule(string $ruleKey): ?array
    {
        $all = $this->loadAll();
        $existing = $all[$ruleKey] ?? null;
        if (!$existing) return null;

        return array_merge([
            'rule_key' => $ruleKey,
            'empty_behavior' => null,
            'applies_to_types' => [],
            'included_health_centers' => [],
            'excluded_health_centers' => [],
            'functional_condition' => '',
            'justification' => '',
            'informed_by' => '',
            'informed_at' => null,
            'status' => 'pending',
            'updated_by' => '',
            'updated_at' => null,
        ], $existing);
    }

    public function getFunctionalRulesBySheetSection(string $sheet, string $section): array
    {
        $all = $this->loadAll();
        $result = [];
        foreach ($all as $key => $data) {
            if (!empty($data['row'])) continue;
            $parts = explode('_', $key);
            $keySheet = strtoupper($parts[0] ?? '');
            $keySection = $parts[1] ?? '';
            if ($keySheet === strtoupper($sheet) && strtolower($keySection) === strtolower($section)) {
                $result[$key] = $this->getFunctionalRule($key);
            }
        }
        return $result;
    }

    public function getFunctionalRulesByRow(string $sheet, string $section): array
    {
        $all = $this->loadAll();
        $result = [];
        foreach ($all as $key => $data) {
            if (empty($data['row'])) continue;
            $rowKey = $data['row'];
            $result["{$sheet}_{$section}_{$rowKey}"] = $data;
        }
        return $result;
    }

    public function getFunctionalRuleByRow(string $sheet, string $section, int $row): ?array
    {
        $all = $this->loadAll();
        foreach ($all as $data) {
            if (!empty($data['row']) && (int) $data['row'] === $row
                && strtoupper($data['sheet'] ?? '') === strtoupper($sheet)
                && strtolower($data['section'] ?? '') === strtolower($section)) {
                return $data;
            }
        }
        return null;
    }

    // ─── Save per-row with versioning ────────────────────────────────

    public function saveFunctionalRuleByRow(string $sheet, string $section, int $row, array $data): array
    {
        $all = $this->loadAll();
        $rowKey = "{$sheet}_{$section}_{$row}";

        $existing = $all[$rowKey] ?? [];

        $previousStatus = $existing['status'] ?? null;
        $newStatus = $data['status'] ?? $existing['status'] ?? 'pending';

        $record = array_merge($existing, [
            'rule_key' => $data['rule_key'] ?? $existing['rule_key'] ?? '',
            'sheet' => $sheet,
            'section' => $section,
            'row' => $row,
            'empty_behavior' => $data['empty_behavior'] ?? $existing['empty_behavior'] ?? null,
            'applies_to_types' => $data['applies_to_types'] ?? $existing['applies_to_types'] ?? [],
            'included_health_centers' => $data['included_health_centers'] ?? $existing['included_health_centers'] ?? [],
            'excluded_health_centers' => $data['excluded_health_centers'] ?? $existing['excluded_health_centers'] ?? [],
            'functional_condition' => $data['functional_condition'] ?? $existing['functional_condition'] ?? '',
            'justification' => $data['justification'] ?? $existing['justification'] ?? '',
            'informed_by' => $data['informed_by'] ?? $existing['informed_by'] ?? '',
            'informed_at' => $data['informed_at'] ?? $existing['informed_at'] ?? now()->toIso8601String(),
            'status' => $newStatus,
            'updated_by' => $data['updated_by'] ?? $existing['updated_by'] ?? '',
            'updated_at' => now()->toIso8601String(),
        ]);

        // Record version
        $version = [
            'row' => $row,
            'sheet' => $sheet,
            'section' => $section,
            'previous' => $existing,
            'record' => $record,
            'changed_by' => $data['updated_by'] ?? $record['updated_by'] ?? '',
            'changed_at' => now()->toIso8601String(),
            'change_type' => empty($existing) ? 'create' : 'update',
        ];

        if ($previousStatus !== null && $previousStatus !== $newStatus) {
            $version['change_type'] = 'status_change';
            $version['status_from'] = $previousStatus;
            $version['status_to'] = $newStatus;
        }

        $all['_row_versions'][] = $version;
        $all[$rowKey] = $record;
        $this->persistAll($all);

        return $record;
    }

    public function clearFunctionalRuleByRow(string $sheet, string $section, int $row, array $metadata = []): ?array
    {
        $all = $this->loadAll();
        $rowKey = "{$sheet}_{$section}_{$row}";
        $existing = $all[$rowKey] ?? null;

        $all['_row_versions'][] = [
            'row' => $row,
            'sheet' => $sheet,
            'section' => $section,
            'previous' => $existing,
            'record' => null,
            'changed_by' => $metadata['updated_by'] ?? $metadata['informed_by'] ?? '',
            'changed_at' => now()->toIso8601String(),
            'change_type' => 'clear_for_inheritance',
            'inheritance_mode' => $metadata['inheritance_mode'] ?? null,
        ];

        unset($all[$rowKey]);
        $this->persistAll($all);

        return $existing;
    }

    // ─── Version history ─────────────────────────────────────────────

    public function getRowVersions(string $sheet, string $section, int $row): array
    {
        $all = $this->loadAll();
        $versions = $all['_row_versions'] ?? [];
        return array_values(array_filter($versions, fn($v) =>
            (int)($v['row'] ?? 0) === $row
            && strtoupper($v['sheet'] ?? '') === strtoupper($sheet)
            && strtolower($v['section'] ?? '') === strtolower($section)
        ));
    }

    // ─── Questions ───────────────────────────────────────────────────

    public function getQuestions(string $sheet, string $section): array
    {
        $all = $this->loadAll();
        return $all['_questions']["{$sheet}_{$section}"] ?? [];
    }

    public function saveQuestions(string $sheet, string $section, array $questions): array
    {
        $all = $this->loadAll();
        $key = "{$sheet}_{$section}";

        $existing = $all['_questions'][$key] ?? [];
        $history = $all['_questions_history'][$key] ?? [];

        foreach ($questions as $i => $q) {
            $old = $existing[$i] ?? [];
            $newStatus = $q['response'] ?? $old['response'] ?? '';
            $oldStatus = $old['response'] ?? '';

            if ($newStatus !== $oldStatus && !empty($newStatus)) {
                $history[] = [
                    'type' => $q['type'] ?? $old['type'] ?? 'unknown',
                    'previous' => $old['response'] ?? '',
                    'new' => $newStatus,
                    'by' => $q['responsible'] ?? $old['responsible'] ?? '',
                    'at' => now()->toIso8601String(),
                ];
            }

            $existing[$i] = array_merge($old, $q, [
                'updated_at' => now()->toIso8601String(),
            ]);
        }

        $all['_questions'][$key] = $existing;
        $all['_questions_history'][$key] = $history;
        $this->persistAll($all);

        // Invalida el agregado de progreso cacheado (ver
        // SectionCalibrationMatrixService::buildStructureCalibrationSummary())
        // -- cualquier respuesta guardada puede cambiar effective_section_reviewed.
        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);

        return $existing;
    }

    public function getQuestionsHistory(string $sheet, string $section): array
    {
        $all = $this->loadAll();
        return $all['_questions_history']["{$sheet}_{$section}"] ?? [];
    }

    // ─── Bulk operations ─────────────────────────────────────────────

    public function bulkSaveFunctionalRuleByRow(string $sheet, string $section, array $rowNumbers, array $data): array
    {
        $saved = [];
        foreach ($rowNumbers as $row) {
            $saved[] = $this->saveFunctionalRuleByRow($sheet, $section, (int) $row, $data);
        }
        return $saved;
    }

    // ─── Decision history ────────────────────────────────────────────

    public function getDecisionHistory(string $sheet, string $section): array
    {
        $all = $this->loadAll();
        return $all['_history']["{$sheet}_{$section}"] ?? [];
    }

    public function addDecisionHistory(string $sheet, string $section, array $entry): void
    {
        $all = $this->loadAll();
        $key = "{$sheet}_{$section}";
        $all['_history'][$key][] = array_merge($entry, [
            'timestamp' => now()->toIso8601String(),
        ]);
        $this->persistAll($all);
    }

    // ─── Per-rule functional rules ───────────────────────────────────

    public function saveFunctionalRule(string $ruleKey, array $data): array
    {
        $all = $this->loadAll();

        $existing = $all[$ruleKey] ?? [];

        $record = array_merge($existing, [
            'rule_key' => $ruleKey,
            'empty_behavior' => $data['empty_behavior'] ?? $existing['empty_behavior'] ?? null,
            'applies_to_types' => $data['applies_to_types'] ?? $existing['applies_to_types'] ?? [],
            'included_health_centers' => $data['included_health_centers'] ?? $existing['included_health_centers'] ?? [],
            'excluded_health_centers' => $data['excluded_health_centers'] ?? $existing['excluded_health_centers'] ?? [],
            'functional_condition' => $data['functional_condition'] ?? $existing['functional_condition'] ?? '',
            'justification' => $data['justification'] ?? $existing['justification'] ?? '',
            'informed_by' => $data['informed_by'] ?? $existing['informed_by'] ?? '',
            'informed_at' => $data['informed_at'] ?? $existing['informed_at'] ?? now()->toIso8601String(),
            'status' => $data['status'] ?? $existing['status'] ?? 'pending',
            'updated_by' => $data['updated_by'] ?? $existing['updated_by'] ?? '',
            'updated_at' => now()->toIso8601String(),
        ]);

        $all[$ruleKey] = $record;
        $this->persistAll($all);

        return $record;
    }

    // ─── Rule Engine consumer ────────────────────────────────────────
    // Returns functional rules in a format the engine can consume directly

    public function getFunctionalRulesForEngine(string $sheet, string $section): array
    {
        $all = $this->loadAll();
        $result = [];
        foreach ($all as $key => $data) {
            if (empty($data['row'])) continue;
            if (strtoupper($data['sheet'] ?? '') !== strtoupper($sheet)) continue;
            if (strtolower($data['section'] ?? '') !== strtolower($section)) continue;
            $result[(int)$data['row']] = $data;
        }
        return $result;
    }

    public function getPatternFunctionalRulesForRows(string $sheet, string $section, array $patterns): array
    {
        $questionsByPattern = $this->questionsGroupedByPattern($sheet, $section);
        if (empty($questionsByPattern)) {
            return [];
        }

        $result = [];
        foreach ($patterns as $pattern) {
            $patternKey = (string) ($pattern['key'] ?? $pattern['pattern_key'] ?? '');
            $patternId = (string) ($pattern['id'] ?? '');
            $questions = $questionsByPattern[$patternKey]
                ?? $questionsByPattern['pattern_' . $patternId]
                ?? $questionsByPattern[$patternId]
                ?? [];

            if (empty($questions)) {
                continue;
            }

            $rule = $this->patternQuestionsToFunctionalRule($sheet, $section, $pattern, $questions);
            if ($rule === null) {
                continue;
            }

            foreach ($pattern['rows'] ?? [] as $rowData) {
                $row = (int) ($rowData['fila'] ?? $rowData['row'] ?? 0);
                if ($row <= 0) {
                    continue;
                }

                $result[$row] = array_merge($rule, [
                    'row' => $row,
                    '_inheritance_scope' => 'pattern',
                    '_inheritance_source_pattern' => $rule['_source_pattern'] ?? $patternKey ?: $patternId,
                ]);
            }
        }

        return $result;
    }

    private function questionsGroupedByPattern(string $sheet, string $section): array
    {
        $grouped = [];
        foreach ($this->getQuestions($sheet, $section) as $question) {
            $patternKey = $question['pattern_key'] ?? null;
            $patternId = $question['pattern_id'] ?? null;
            if ($patternKey === null && $patternId === null) {
                continue;
            }

            $key = (string) ($patternKey ?? ('pattern_' . $patternId));
            $grouped[$key][] = $question;
            if ($patternId !== null) {
                $grouped[(string) $patternId][] = $question;
            }
        }

        return $grouped;
    }

    private function patternQuestionsToFunctionalRule(string $sheet, string $section, array $pattern, array $questions): ?array
    {
        $emptyQuestion = $this->findPatternQuestion($questions, ['empty', 'sin_datos'], [
            'debe_registrar_cero',
            'puede_quedar_vacio',
        ]);

        if ($emptyQuestion === null || !$this->isReviewedQuestion($emptyQuestion)) {
            return null;
        }

        $emptyBehavior = $emptyQuestion['response'] ?? null;
        if (!in_array($emptyBehavior, ['debe_registrar_cero', 'puede_quedar_vacio'], true)) {
            return null;
        }

        if ($this->patternQuestionsDeclareExceptions($questions)) {
            return null;
        }

        $severityQuestion = $this->findPatternQuestion($questions, ['inconsistency', 'severity', 'severidad'], [
            'error',
            'advertencia',
        ]);

        return [
            'rule_key' => '',
            'sheet' => $sheet,
            'section' => $section,
            'empty_behavior' => $emptyBehavior,
            'applies_to_types' => [],
            'included_health_centers' => [],
            'excluded_health_centers' => [],
            'functional_condition' => $emptyQuestion['question'] ?? '',
            'justification' => $emptyQuestion['observation'] ?? '',
            'informed_by' => $emptyQuestion['reviewed_by'] ?? $emptyQuestion['responsible'] ?? '',
            'informed_at' => $emptyQuestion['reviewed_at'] ?? $emptyQuestion['date'] ?? null,
            'status' => 'aprobada',
            'updated_by' => $emptyQuestion['reviewed_by'] ?? $emptyQuestion['responsible'] ?? '',
            'updated_at' => $emptyQuestion['reviewed_at'] ?? $emptyQuestion['date'] ?? null,
            'severity' => $severityQuestion['response'] ?? 'warning',
            '_source_type' => 'pattern_question',
            '_source_pattern' => $pattern['key'] ?? $pattern['pattern_key'] ?? ('pattern_' . ($pattern['id'] ?? '')),
            '_source_pattern_id' => $pattern['id'] ?? null,
        ];
    }

    private function findPatternQuestion(array $questions, array $idHints, array $responses): ?array
    {
        foreach ($questions as $question) {
            $id = strtolower((string) ($question['id'] ?? ''));
            $type = strtolower((string) ($question['type'] ?? ''));
            $response = $question['response'] ?? null;

            if (!in_array($response, $responses, true)) {
                continue;
            }

            foreach ($idHints as $hint) {
                if (str_contains($id, $hint) || str_contains($type, $hint)) {
                    return $question;
                }
            }
        }

        foreach ($questions as $question) {
            if (in_array($question['response'] ?? null, $responses, true)) {
                return $question;
            }
        }

        return null;
    }

    private function isReviewedQuestion(array $question): bool
    {
        return in_array($question['review_status'] ?? '', ['reviewed', 'certified', 'pattern_reviewed'], true)
            || in_array($question['status'] ?? '', ['reviewed', 'answered'], true);
    }

    private function patternQuestionsDeclareExceptions(array $questions): bool
    {
        foreach ($questions as $question) {
            $id = strtolower((string) ($question['id'] ?? ''));
            $text = strtolower((string) ($question['question'] ?? ''));
            if (!str_contains($id, 'exception') && !str_contains($id, 'excepcion') && !str_contains($text, 'excepcion') && !str_contains($text, 'excepción')) {
                continue;
            }

            $response = $question['response'] ?? null;
            if (in_array($response, ['si', 'sí', 'depende', 'por_definir'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cache de instancia: reglas-funcionales.json (1.3MB+) se releia y
     * re-decodificaba en cada llamada a loadAll(), y evaluateFunctionalRules()
     * llama a metodos que pasan por loadAll() una vez por cada seccion de la
     * carga (325 en una carga real de Serie A completa) -- 325 lecturas +
     * decodificaciones del mismo archivo sin cambios entre ellas. Como el
     * servicio se resuelve nuevo por request/job (no es singleton), cachear
     * a nivel de instancia no arrastra datos obsoletos entre cargas.
     */
    private ?array $cachedAll = null;

    private function loadAll(): array
    {
        if ($this->cachedAll !== null) {
            return $this->cachedAll;
        }

        $path = self::STORAGE_DIR . '/' . self::STORAGE_FILE;
        if (!Storage::disk('local')->exists($path)) {
            return $this->cachedAll = [];
        }

        return $this->cachedAll = json_decode(Storage::disk('local')->get($path), true) ?? [];
    }

    private function persistAll(array $data): void
    {
        $path = self::STORAGE_DIR . '/' . self::STORAGE_FILE;
        Storage::disk('local')->put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->cachedAll = $data;
    }
}
