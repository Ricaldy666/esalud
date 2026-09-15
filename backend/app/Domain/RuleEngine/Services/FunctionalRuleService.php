<?php

namespace App\Domain\RuleEngine\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
            // BM-9.2 (2026-09-14): un registro solo pertenece al resultado si
            // su propio sheet/section (no la clave del array, ni el
            // sheet/section solicitado) coincide realmente con lo pedido --
            // antes cualquier registro con el mismo numero de fila, de
            // CUALQUIER hoja/seccion/serie, se remapeaba a la clave
            // solicitada, contaminando la evidencia mostrada (ver
            // getFunctionalRuleByRow(), que ya aplicaba este mismo filtro).
            if (strtoupper($data['sheet'] ?? '') !== strtoupper($sheet)
                || strtolower($data['section'] ?? '') !== strtolower($section)) {
                continue;
            }
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

    /**
     * Campos de metadata canonica v2 -- una vez que una pregunta los tiene
     * (fingerprint_version=2), son SERVER-OWNED: solo
     * applyQuickRevalidation() puede escribirlos/actualizarlos. El flujo
     * normal de guardado (saveQuestions(), abajo) nunca debe confiar en lo
     * que el navegador envie para estos campos -- el frontend legacy
     * (QuickCalibrationPanel) siempre manda pattern_fingerprint en formato
     * v1 (row_fingerprint), sin saber que la pregunta ya fue migrada/
     * revalidada a v2. Ver hallazgo A01/G.-G.2, 2026-08-12.
     */
    private const PROTECTED_V2_FIELDS = [
        'fingerprint_version',
        'pattern_fingerprint',
        'pattern_rows',
        'revalidated_by',
        'revalidated_at',
        'revalidation_source_type',
    ];

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

            // Si la pregunta existente ya es canonica v2, se descartan del
            // payload entrante los 6 campos protegidos ANTES del merge --
            // asi el array_merge de abajo nunca puede pisarlos, sin importar
            // que valores traiga $q. El resto de campos (response,
            // review_status, reviewed_by, reviewed_at, source_type,
            // observation, etc.) sigue actualizandose normalmente: esto NO
            // bloquea decisiones funcionales legitimas del flujo normal,
            // solo protege la metadata tecnica del mecanismo v2.
            $incoming = ($old['fingerprint_version'] ?? null) === 2
                ? array_diff_key($q, array_flip(self::PROTECTED_V2_FIELDS))
                : $q;

            $existing[$i] = array_merge($old, $incoming, [
                'updated_at' => now()->toIso8601String(),
            ]);
        }

        $all['_questions'][$key] = $existing;
        $all['_questions_history'][$key] = $history;
        $this->persistAll($all, [
            'source' => 'saveQuestions',
            'sheet' => $sheet,
            'section' => $section,
        ]);

        // Invalida el agregado de progreso cacheado (ver
        // SectionCalibrationMatrixService::buildStructureCalibrationSummary())
        // -- cualquier respuesta guardada puede cambiar effective_section_reviewed.
        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);

        return $existing;
    }

    /**
     * Fase 1 (flujo QUICK_CONFIRMATION, 2026-08-12): aplica una revalidacion
     * rapida a TODAS las preguntas (pattern_question/pattern_confirmation)
     * de un pattern_id puntual -- exclusivamente los campos tecnicos de
     * fingerprint v2 + metadata de revalidacion. Deliberadamente NO acepta
     * response/reviewed_by/reviewed_at/source_type como parametros -- esos
     * campos nunca se tocan, precisamente para que esta funcion no pueda
     * usarse (ni por error) para sobrescribir la decision funcional
     * original. El llamador (CalibrationViewController::confirmQuickRevalidation())
     * ya valido que la clasificacion en vivo es QUICK_CONFIRMATION antes de
     * llegar aqui -- este metodo no vuelve a clasificar, solo escribe.
     *
     * @param  int[]  $patternRows  Filas del patron vigente, ya ordenadas.
     * @return array Las preguntas de la seccion tras el cambio (mismo shape que getQuestions()).
     *
     * $revalidationSourceType/$historicalRowsBeforeExclusion/$excludedTotalRows/
     * $exclusionMechanism son EXCLUSIVOS del flujo structural_row_exclusion
     * (2026-08-24, CalibrationViewController::confirmMismatchResolution()) --
     * parametros nuevos, opcionales, al final de la firma con el MISMO valor
     * por defecto que el codigo ya escribia antes ('manual_revalidation'):
     * la llamada existente de confirmQuickRevalidation() (safe_reconfirm via
     * QuickRevalidationPanel) sigue escribiendo exactamente lo mismo, sin
     * ningun campo nuevo. Los 6 campos protegidos siguen siendo exactamente
     * los mismos 6 -- $historicalRowsBeforeExclusion/$excludedTotalRows/
     * $exclusionMechanism NUNCA se escriben en la pregunta, solo en la
     * entrada de $history (append-only, fuera del objeto protegido) para
     * dejar trazabilidad completa sin tocar el invariante de "solo 6 campos".
     *
     * $historicalPatternId (2026-08-24, hallazgo de corrupcion real A09/G
     * P3 -- ver PatternMigrationScanner::scanSection() 'historical_pattern_id'):
     * ANTES este parametro se llamaba $patternId y los llamadores le pasaban
     * el pattern_id VIVO/posicional (el mismo que ve el usuario en la UI).
     * Cuando un patron se desplaza de posicion (ej. tras excluir una fila
     * TOTAL lider, mecanismo #6), ese numero posicional puede coincidir por
     * pura casualidad con el pattern_id CRUDO de un patron historico
     * TOTALMENTE DISTINTO -- la fila 'if (pattern_id !== $patternId)' de
     * abajo entonces sobrescribia las preguntas equivocadas. Ahora el
     * parametro se llama explicitamente $historicalPatternId y DEBE ser el
     * valor devuelto por matchLivePatternsToHistorical() (expuesto como
     * 'historical_pattern_id' en cada patron de scanSection()) -- la MISMA
     * identidad que ya se uso para resolver el tag/gate, nunca el numero
     * posicional vivo. Los llamadores (CalibrationViewController) son
     * responsables de pasar el valor correcto; este metodo no vuelve a
     * resolver identidad, solo escribe contra la que se le indique.
     *
     * @throws \RuntimeException si no existe ninguna pregunta con ese pattern_id en la seccion.
     */
    public function applyQuickRevalidation(
        string $sheet,
        string $section,
        int $historicalPatternId,
        string $canonicalFingerprint,
        array $patternRows,
        string $revalidatedBy,
        string $revalidationSourceType = 'manual_revalidation',
        ?array $historicalRowsBeforeExclusion = null,
        ?array $excludedTotalRows = null,
        ?string $exclusionMechanism = null,
    ): array {
        $all = $this->loadAll();
        $key = "{$sheet}_{$section}";
        $existing = $all['_questions'][$key] ?? [];
        $history = $all['_questions_history'][$key] ?? [];

        $revalidatedAt = now()->toIso8601String();
        $touchedAny = false;

        foreach ($existing as $i => $q) {
            if (!in_array($q['type'] ?? '', ['pattern_question', 'pattern_confirmation'], true)) {
                continue;
            }
            if (($q['pattern_id'] ?? null) !== $historicalPatternId) {
                continue;
            }

            // Deliberadamente SOLO estos 6 campos -- nunca response,
            // reviewed_by, reviewed_at, source_type, question, id,
            // pattern_id, pattern_key, review_status, closure_reason.
            $existing[$i]['fingerprint_version'] = 2;
            $existing[$i]['pattern_fingerprint'] = $canonicalFingerprint;
            $existing[$i]['pattern_rows'] = $patternRows;
            $existing[$i]['revalidated_by'] = $revalidatedBy;
            $existing[$i]['revalidated_at'] = $revalidatedAt;
            $existing[$i]['revalidation_source_type'] = $revalidationSourceType;

            $touchedAny = true;
        }

        if (!$touchedAny) {
            throw new \RuntimeException("No se encontraron preguntas de patron con pattern_id={$historicalPatternId} en {$key}.");
        }

        $historyEntry = [
            'type' => 'pattern_revalidation',
            'pattern_id' => $historicalPatternId,
            'by' => $revalidatedBy,
            'at' => $revalidatedAt,
            'fingerprint_version' => 2,
            'revalidation_source_type' => $revalidationSourceType,
            'pattern_rows' => $patternRows,
        ];
        if ($historicalRowsBeforeExclusion !== null) {
            $historyEntry['historical_rows_before_exclusion'] = $historicalRowsBeforeExclusion;
        }
        if ($excludedTotalRows !== null) {
            $historyEntry['excluded_total_rows'] = $excludedTotalRows;
        }
        if ($exclusionMechanism !== null) {
            $historyEntry['exclusion_mechanism'] = $exclusionMechanism;
        }
        $history[] = $historyEntry;

        $all['_questions'][$key] = $existing;
        $all['_questions_history'][$key] = $history;
        $this->persistAll($all, [
            'source' => 'applyQuickRevalidation',
            'sheet' => $sheet,
            'section' => $section,
            'pattern_id' => $historicalPatternId,
        ]);

        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);

        return $existing;
    }

    /**
     * Resuelve formalmente un patron clasificado MISMATCH + human_review
     * (2026-09-11, diseño auditado por el usuario): a diferencia de
     * applyQuickRevalidation() (que NUNCA toca response/reviewed_by/
     * reviewed_at/review_status -- exclusivo para safe_reconfirm/
     * structural_row_exclusion, donde la respuesta original se conserva tal
     * cual), este metodo SI reemplaza la decision funcional del patron --
     * exige una respuesta nueva completa, y junto con ella escribe el
     * fingerprint/filas/version de estructura ACTUALES.
     *
     * El fingerprint/filas/version de estructura llegan como parametros ya
     * calculados y verificados por el llamador (CalibrationViewController::
     * confirmHumanReviewResolution(), via PatternMigrationScanner::
     * scanSection() en vivo) -- este metodo NUNCA los recalcula ni confia en
     * ningun valor que pudiera venir del cliente ($newQuestions solo trae
     * id/response/observation/review_status por pregunta, nunca metadata
     * tecnica). Misma division de responsabilidades que ya usa
     * applyQuickRevalidation()/confirmMismatchResolution(): el controlador
     * gatea y recalcula en vivo, el servicio solo persiste lo ya verificado.
     *
     * Nota de diseño: este servicio NO puede depender de
     * SectionCalibrationMatrixService/PatternMigrationScanner para
     * recalcular esto por si mismo -- ambos ya dependen (constructor) de
     * FunctionalRuleService, un ciclo de inyeccion de dependencias real, no
     * hipotetico (verificado leyendo ambos constructores antes de diseñar
     * esto). Por eso el calculo vive en el controlador, igual que
     * confirmMismatchResolution() ya hace para applyQuickRevalidation().
     *
     * Preserva la decision anterior completa en _questions_history: una
     * entrada 'response_changed'-style (mismo mecanismo generico y formato
     * que ya usa saveQuestions()) por cada pregunta cuya respuesta cambio,
     * MAS una entrada agregada 'human_review_resolution' con
     * fingerprint/structure_version antes y despues -- trazabilidad exacta
     * de que reemplazo a que, sin ambigüedad.
     *
     * mismatch-resolution-audit.json NUNCA se toca desde aqui -- mismo
     * comportamiento que ya tiene confirmMismatchResolution() para
     * safe_reconfirm: el tag queda como registro historico inerte, sin
     * ningun consumidor una vez que el patron deja de estar en MISMATCH
     * (PatternMigrationScanner solo llama a getTag() mientras la seccion
     * siga clasificada MISMATCH).
     *
     * Generico por diseño: opera exclusivamente sobre
     * sheet/section/historicalPatternId (la misma identidad estable ya
     * usada por applyQuickRevalidation()) y sobre valores ya calculados en
     * vivo -- cero referencia a ninguna hoja/seccion en particular. Sirve
     * igual para cualquier MISMATCH+human_review futuro de BM/BS/D/P.
     *
     * @param  array<int,array{id:string,response:string,observation?:?string,review_status?:?string}>  $newQuestions
     *         Una entrada por CADA pregunta pattern_question/pattern_confirmation
     *         del pattern_id -- el llamador ya valido que el conjunto de
     *         ids coincide exacto (ni de mas ni de menos) antes de invocar
     *         este metodo; aqui se revalida de todas formas (ver el
     *         RuntimeException de abajo si falta alguna), nunca se asume.
     * @return array Las preguntas de la seccion tras el cambio (mismo shape que getQuestions()).
     *
     * @throws \RuntimeException si no existe ninguna pregunta de
     *         pattern_question/pattern_confirmation con ese pattern_id en
     *         la seccion, o si el conjunto de $newQuestions no cubre alguna
     *         de las preguntas existentes de ese patron -- en cualquiera de
     *         los dos casos no se persiste nada (la mutacion ocurre solo en
     *         memoria hasta el persistAll() final).
     */
    public function resolveHumanReviewPattern(
        string $sheet,
        string $section,
        int $historicalPatternId,
        array $newQuestions,
        string $canonicalFingerprint,
        array $patternRows,
        string $structureVersion,
        string $reviewedBy,
        string $reviewSourceType = 'manual',
    ): array {
        $all = $this->loadAll();
        $key = "{$sheet}_{$section}";
        $existing = $all['_questions'][$key] ?? [];
        $history = $all['_questions_history'][$key] ?? [];

        $reviewedAt = now()->toIso8601String();
        $touchedAny = false;
        $fingerprintBefore = null;
        $structureVersionBefore = null;

        $incomingById = [];
        foreach ($newQuestions as $q) {
            if (!empty($q['id'])) {
                $incomingById[$q['id']] = $q;
            }
        }

        foreach ($existing as $i => $q) {
            if (!in_array($q['type'] ?? '', ['pattern_question', 'pattern_confirmation'], true)) {
                continue;
            }
            if (($q['pattern_id'] ?? null) !== $historicalPatternId) {
                continue;
            }

            $incoming = $incomingById[$q['id'] ?? ''] ?? null;
            if ($incoming === null) {
                // El controlador ya valido que el conjunto de ids coincide
                // exacto -- si esto ocurre de todas formas (defensivo, no
                // se confia ciegamente en esa validacion previa), se aborta
                // sin persistir ningun cambio parcial: la excepcion corta
                // el flujo ANTES de llegar a persistAll(), y $all/$existing
                // solo existen en memoria de esta invocacion.
                throw new \RuntimeException("Falta respuesta para la pregunta '{$q['id']}' del pattern_id={$historicalPatternId} en {$key}.");
            }

            if ($fingerprintBefore === null && !empty($q['pattern_fingerprint'])) {
                $fingerprintBefore = $q['pattern_fingerprint'];
            }
            if ($structureVersionBefore === null && !empty($q['structure_version'])) {
                $structureVersionBefore = $q['structure_version'];
            }

            $oldResponse = $q['response'] ?? '';
            $newResponse = $incoming['response'];
            if ($newResponse !== $oldResponse) {
                $history[] = [
                    'type' => $q['type'],
                    'previous' => $oldResponse,
                    'new' => $newResponse,
                    'by' => $reviewedBy,
                    'at' => $reviewedAt,
                ];
            }

            $existing[$i] = array_merge($q, [
                'response' => $newResponse,
                'observation' => $incoming['observation'] ?? null,
                'review_status' => $incoming['review_status'] ?? 'reviewed',
                'status' => 'answered',
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => $reviewedAt,
                'source_type' => $reviewSourceType,
                'fingerprint_version' => 2,
                'pattern_fingerprint' => $canonicalFingerprint,
                'pattern_rows' => $patternRows,
                'structure_version' => $structureVersion,
                'updated_at' => $reviewedAt,
            ]);

            $touchedAny = true;
        }

        if (!$touchedAny) {
            throw new \RuntimeException("No se encontraron preguntas de patron con pattern_id={$historicalPatternId} en {$key}.");
        }

        $history[] = [
            'type' => 'human_review_resolution',
            'pattern_id' => $historicalPatternId,
            'fingerprint_before' => $fingerprintBefore,
            'fingerprint_after' => $canonicalFingerprint,
            'structure_version_before' => $structureVersionBefore,
            'structure_version_after' => $structureVersion,
            'by' => $reviewedBy,
            'at' => $reviewedAt,
            'review_source_type' => $reviewSourceType,
        ];

        $all['_questions'][$key] = $existing;
        $all['_questions_history'][$key] = $history;
        $this->persistAll($all, [
            'source' => 'resolveHumanReviewPattern',
            'sheet' => $sheet,
            'section' => $section,
            'pattern_id' => $historicalPatternId,
        ]);

        // Invalida el agregado de progreso cacheado SOLO despues de que la
        // persistencia real ya ocurrio -- mismo orden que saveQuestions()/
        // applyQuickRevalidation() (nunca antes: si persistAll() lanzara,
        // no queremos una cache invalidada sin escritura real detras).
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

        // BM-11.6 (ENGINE_UI_REDUNDANCY, BM-11.3/BM-11.4): resuelve el
        // alcance por establecimiento a partir de las preguntas all_est/
        // exceptions -- ver resolveScope() para el contrato completo
        // (Casos A-E). Reemplaza el bloqueo binario anterior
        // (patternQuestionsDeclareExceptions(), retirado en esta fase):
        // exceptions='si' ya NO descarta el patron entero por si solo --
        // solo lo hace cuando no trae un scope estructurado valido, que es
        // exactamente el comportamiento seguro que ya tenia el codigo
        // anterior para ese caso degenerado (preservado a proposito).
        $allEstQuestion = $this->findQuestionByIdSubstring($questions, ['all_est']);
        $exceptionsQuestion = $this->findQuestionByIdSubstring($questions, ['exception', 'excepcion']);

        [$includedHealthCenters, $excludedHealthCenters, $scopeIsValid] = $this->resolveScope(
            $allEstQuestion['response'] ?? null,
            $exceptionsQuestion['response'] ?? null,
            $exceptionsQuestion['scope'] ?? null,
        );

        if (!$scopeIsValid) {
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
            'included_health_centers' => $includedHealthCenters,
            'excluded_health_centers' => $excludedHealthCenters,
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

    /**
     * BM-11.6: busca la pregunta de un patron cuyo id contenga alguno de los
     * hints dados, SIN filtrar por response (a diferencia de
     * findPatternQuestion()) -- necesario aqui porque resolveScope() debe
     * poder inspeccionar tambien respuestas "invalidas"/inesperadas para
     * decidir el fallback seguro, no solo las respuestas ya conocidas.
     */
    private function findQuestionByIdSubstring(array $questions, array $idHints): ?array
    {
        foreach ($questions as $question) {
            $id = strtolower((string) ($question['id'] ?? ''));
            foreach ($idHints as $hint) {
                if (str_contains($id, $hint)) {
                    return $question;
                }
            }
        }

        return null;
    }

    /**
     * BM-11.6 (contrato aprobado en BM-11.4 §1/§4): decide el alcance por
     * establecimiento de un patron a partir de sus preguntas all_est/
     * exceptions. Devuelve [included_health_centers, excluded_health_centers, valido].
     *
     * Casos validos:
     *   A) all_est!='depende' + exceptions='no' (o ausente)      -> [],  []
     *   B) all_est!='depende' + exceptions='si' + scope.excluded -> [],  excluded
     *   C) all_est=='depende' + exceptions='si' + scope.included -> included, []
     *
     * Cualquier otra combinacion (D: depende+no: sin universo por defecto;
     * E: exceptions='si' sin scope/sin centros; scope con included Y
     * excluded a la vez; valores de exceptions distintos de 'si'/'no' --
     * legado defensivo 'si'/'depende'/'por_definir', igual que el codigo
     * anterior) es INVALIDA: nunca se asume aplicacion, requiere revision
     * manual (el llamador debe devolver null, igual que el comportamiento
     * pre-fix para estos casos degenerados).
     *
     * all_est distinto de 'depende' (incluye 'si', ausente, o cualquier
     * valor legado atipico como el unico caso real conocido en Serie A,
     * A01/C patron_2 = 'no') se trata como equivalente a 'si' -- preserva
     * exactamente el comportamiento anterior para ese registro real.
     */
    private function resolveScope(?string $allEstResponse, ?string $exceptionsResponse, ?array $scope): array
    {
        $isDepende = $allEstResponse === 'depende';
        $isNoException = $exceptionsResponse === null || $exceptionsResponse === '' || $exceptionsResponse === 'no';
        $isException = $exceptionsResponse === 'si';

        if (!$isDepende && $isNoException) {
            // Caso A -- legado exacto, sin scope necesario.
            return [[], [], true];
        }

        if (!$isDepende && $isException) {
            // Caso B -- aplica a todos, excepto los establecimientos declarados.
            $excluded = $this->normalizeScopeList($scope['excluded_health_centers'] ?? null);
            $included = $this->normalizeScopeList($scope['included_health_centers'] ?? null);
            if ($excluded !== [] && $included === []) {
                return [[], $excluded, true];
            }

            return [[], [], false];
        }

        if ($isDepende && $isException) {
            // Caso C -- aplica UNICAMENTE a los establecimientos declarados.
            $included = $this->normalizeScopeList($scope['included_health_centers'] ?? null);
            $excluded = $this->normalizeScopeList($scope['excluded_health_centers'] ?? null);
            if ($included !== [] && $excluded === []) {
                return [$included, [], true];
            }

            return [[], [], false];
        }

        // Caso D (depende + no) y cualquier valor de exceptions distinto de
        // 'si'/'no' (legado defensivo) -- requiere revision, nunca se asume.
        return [[], [], false];
    }

    /**
     * BM-11.6: normaliza un valor de scope.included_health_centers/
     * excluded_health_centers a una lista de nombres de establecimiento
     * limpia (strings no vacios, sin duplicados) -- fail-safe: cualquier
     * valor que no sea un array de strings colapsa a [] (nunca se asume ni
     * se amplia aplicacion a partir de datos malformados).
     */
    private function normalizeScopeList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($v) => is_string($v) ? trim($v) : '', $value),
            static fn ($v) => $v !== '',
        )));
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

    /**
     * Diagnostico minimo (2026-08-12, investigacion "POST 200 sin
     * persistencia" de A01/D) alrededor de la unica escritura real del
     * archivo -- deliberadamente NO cambia el comportamiento funcional: el
     * valor de retorno de Storage::put() nunca se verifico antes de esto y
     * sigue sin verificarse (no se agrega throw/retry/fallback todavia,
     * solo se deja evidencia). $context es opcional y por defecto vacio
     * para los demas call-sites de persistAll() -- solo
     * applyQuickRevalidation() pasa sheet/section/pattern_id hoy. Nunca se
     * loguea el contenido completo del JSON, solo su tamano en bytes.
     */
    private function persistAll(array $data, array $context = []): void
    {
        $path = self::STORAGE_DIR . '/' . self::STORAGE_FILE;
        $absolutePath = Storage::disk('local')->path($path);
        $before = $this->diagnosticSnapshot($path);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $putResult = Storage::disk('local')->put($path, $json);

        $after = $this->diagnosticSnapshot($path);

        Log::info('[FunctionalRuleService][persistAll-diagnostic] Escritura de reglas-funcionales.json', [
            'put_result' => $putResult,
            'absolute_path' => $absolutePath,
            'content_size_bytes' => strlen($json),
            'timestamp' => now()->toIso8601String(),
            'context' => $context,
            'before' => $before,
            'after' => $after,
        ]);

        $this->cachedAll = $data;
    }

    /**
     * @return array{exists:bool, mtime:?string, size:?int}
     */
    private function diagnosticSnapshot(string $relativePath): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($relativePath)) {
            return ['exists' => false, 'mtime' => null, 'size' => null];
        }

        return [
            'exists' => true,
            'mtime' => date('c', $disk->lastModified($relativePath)),
            'size' => $disk->size($relativePath),
        ];
    }
}
