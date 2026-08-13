<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\REM\Models\RemValidationResult;
use App\Domain\REM\Models\RemData;
use App\Domain\RuleEngine\Models\RuleExecutionLog;

class ValidationErrorFormatterService
{
    private array $sectionMetaCache = [];

    /**
     * Indice en memoria de RemData por upload, construido UNA sola vez por
     * uploadId: uploadId -> SHEET (mayusculas) -> rowNumber -> RemData.
     * Hallazgo de rendimiento (produccion, upload 8, 2026-08-13):
     * resolveFunctionalSectionMeta() ejecutaba RemData::query()->get() de
     * las ~2177 filas del upload en CADA combinacion (sheet,row) sin cache
     * previo -- 1001 errores -> cientos de recargas completas (193s /
     * 86.5MB). Con este indice, la carga es O(1) por lookup despues de la
     * primera construccion.
     *
     * @var array<int, array<string, array<int, RemData>>>
     */
    private array $remDataIndexCache = [];

    /**
     * Cache de CertificationService::getSectionInfo() por "sheet|seccion"
     * -- ese metodo vuelve a consultar la estructura activa (sin cache
     * propia, ver deuda tecnica #6 documentada) en cada llamada; varias
     * filas de una misma seccion disparaban la misma consulta repetida.
     *
     * @var array<string, array|null>
     */
    private array $sectionInfoCache = [];

    public function __construct(private ?CertificationService $certificationService = null)
    {
        $this->certificationService ??= app(CertificationService::class);
    }

    public function formatErrors(int $uploadId, array $filters = []): array
    {
        $results = $this->queryResults($uploadId, $filters);
        $logs = $this->queryLogs($uploadId, $filters);

        $hasFunctionalResults = $results->contains(fn ($r) => $r->rule_type === 'functional_rule');

        $formatted = collect();

        // From RemValidationResult: all entries (functional rules are correctly classified)
        $formatted = $formatted->merge(
            $results->map(fn ($r) => $this->formatFromResult($r))
        );

        // From RuleExecutionLog: only entries NOT related to functional rules,
        // to avoid duplicates with RemValidationResult entries above.
        foreach ($logs as $log) {
            $details = $log->context['details'] ?? [];
            $hasFunctionalDecision = collect($details)->contains(fn ($d) => !empty($d['functional_decision']));

            // Skip log entries that duplicate functional results from RemValidationResult
            if ($hasFunctionalDecision && $hasFunctionalResults) {
                continue;
            }

            $formatted->push($this->formatFromLog($log));
        }

        // Re-apply tipo_error filter after formatting, since formatFromLog may
        // have overridden tipo_error based on functional_decision presence.
        if (!empty($filters['tipo_error'])) {
            $formatted = $formatted->filter(fn ($e) => ($e['tipo_error'] ?? 'tecnico') === $filters['tipo_error']);
        }

        // Deduplicate: same form + section + campo + tipo_error + mensaje = duplicate.
        // $seen como array asociativo (isset O(1)) en vez de in_array() sobre
        // una lista (O(n) por chequeo, O(n^2) total con muchos errores) --
        // misma semantica de comparacion estricta de string, solo cambia el
        // costo.
        $seen = [];
        $formatted = $formatted->filter(function ($e) use (&$seen) {
            $key = ($e['form'] ?? '') . '|'
                 . ($e['section'] ?? '') . '|'
                 . ($e['campo'] ?? '') . '|'
                 . ($e['tipo_error'] ?? '') . '|'
                 . ($e['mensaje'] ?? '');
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        });

        return $formatted->values()->toArray();
    }

    private function queryResults(int $uploadId, array $filters)
    {
        $query = RemValidationResult::where('rem_upload_id', $uploadId)
            ->where('passed', false)
            ->with('rule');

        if (!empty($filters['form'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('context->section', $filters['form']);
            });
        }

        if (!empty($filters['severidad'])) {
            $query->where('severity', $filters['severidad']);
        }

        if (!empty($filters['tipo_regla'])) {
            $query->where('rule_type', $filters['tipo_regla']);
        }

        if (!empty($filters['tipo_error'])) {
            if ($filters['tipo_error'] === 'funcional') {
                $query->where('rule_type', 'functional_rule');
            } elseif ($filters['tipo_error'] === 'tecnico') {
                $query->where('rule_type', '!=', 'functional_rule');
            }
        }

        return $query->get();
    }

    private function queryLogs(int $uploadId, array $filters)
    {
        $query = RuleExecutionLog::where('rem_upload_id', $uploadId)
            ->where('status', 'failed')
            ->with('rule');

        if (!empty($filters['form'])) {
            $query->whereHas('rule', function ($q) use ($filters) {
                $q->where(function ($q) use ($filters) {
                    $q->where('config->sheet', $filters['form'])
                      ->orWhere('metadata->sheet', $filters['form']);
                });
            });
        }

        if (!empty($filters['severidad'])) {
            $query->whereHas('rule', function ($q) use ($filters) {
                $q->where('severity', $filters['severidad']);
            });
        }

        if (!empty($filters['tipo_regla'])) {
            $query->whereHas('rule', function ($q) use ($filters) {
                $q->where('rule_type', $filters['tipo_regla']);
            });
        }

        if (!empty($filters['tipo_error'])) {
            if ($filters['tipo_error'] === 'funcional') {
                $query->whereHas('rule', function ($q) {
                    $q->where('rule_type', 'functional_rule');
                });
            } elseif ($filters['tipo_error'] === 'tecnico') {
                $query->whereHas('rule', function ($q) {
                    $q->where('rule_type', '!=', 'functional_rule');
                });
            }
        }

        return $query->get();
    }

    public function formatFromLog(RuleExecutionLog $log): array
    {
        $rule = $log->rule;
        $meta = $rule?->metadata ?? [];
        $config = $rule?->config ?? [];

        $sheet = $config['sheet'] ?? $meta['sheet'] ?? '';
        $section = $config['section'] ?? $meta['section'] ?? '';
        $letra = $meta['letra'] ?? '';
        $label = $meta['label'] ?? '';
        $type = $rule?->rule_type ?? '';
        $severity = $rule?->severity ?? 'error';

        $sourceLetters = $config['source_letters'] ?? [];
        $sourceLettersStr = !empty($sourceLetters)
            ? implode(' + ', array_map('strtoupper', $sourceLetters))
            : '';

        $campoLimpio = $this->extractCleanFieldName($label, $letra, $type, $sourceLettersStr);
        $recomendacion = $this->buildRecommendation($type, $severity, $sheet, $section, $campoLimpio, $sourceLettersStr);

        $details = $log->context['details'] ?? [];

        $functionalMessages = [];
        $rowInfos = [];
        $functionalDetail = null;
        foreach ($details as $d) {
            if (!empty($d['functional_decision'])) {
                if ($functionalDetail === null) {
                    $functionalDetail = $d;
                }
                $rowInfo = 'Fila ' . ($d['row_number'] ?? '?');
                if (!empty($d['concept'])) {
                    $rowInfo .= ' - ' . $d['concept'];
                }
                $rowInfos[] = $rowInfo;
                if (!empty($d['message'])) {
                    $functionalMessages[] = $d['message'];
                }
            }
        }

        $logConcept = '';
        $logFd = [];
        $pendingCells = [];
        $rowTotal = null;
        if ($functionalDetail) {
            $logConcept = $functionalDetail['concept'] ?? '';
            $logFd = $functionalDetail['functional_decision'] ?? [];
            $pendingCells = $functionalDetail['pending_cells'] ?? [];
            $rowTotal = $functionalDetail['row_total'] ?? null;
        }

        if (!empty($functionalMessages)) {
            $uniqueMsgs = array_unique($functionalMessages);
            $rowInfoStr = implode(', ', $rowInfos);
            $campoLimpio = $rowInfoStr ?: $campoLimpio;

            $cleanMsg = $this->formatFunctionalMessage($logConcept, '', $logFd);
            $mensaje = $cleanMsg ?? implode('; ', $uniqueMsgs);
            $recomendacion = $logFd
                ? $this->buildFunctionalRecommendation($logFd, $functionalDetail['row_number'] ?? 0, $logConcept, '')
                : 'Revise la decision funcional aplicada a la fila y corrija el valor segun corresponda.';
        } else {
            $mensaje = $this->buildMessage($type, $severity, $sheet, $section, $letra, $campoLimpio, $sourceLettersStr);
        }

        $tipoError = $type === 'functional_rule' ? 'funcional' : 'tecnico';
        if (!empty($functionalMessages)) {
            $tipoError = 'funcional';
        }

        return [
            'id' => $log->id,
            'form' => $sheet,
            'section' => $section,
            'campo' => $campoLimpio,
            'columna' => $letra,
            'tipo' => $type,
            'tipo_error' => $tipoError,
            'mensaje' => $mensaje,
            'recomendacion' => $recomendacion,
            'filas_afectadas' => (int) $log->failed_rows,
            'filas_totales' => (int) $log->total_rows,
            'severidad' => $severity,
            'criterio' => $logFd ?: null,
            'row_concept' => $logConcept,
            'row_profesional' => '',
            'row_total' => $rowTotal,
            'pending_cells' => $pendingCells,
            'pending_cells_count' => count($pendingCells),
        ];
    }

    public function formatFromResult(RemValidationResult $result): array
    {
        $rule = $result->rule;
        $meta = $rule?->metadata ?? [];
        $config = $rule?->config ?? [];
        $ctx = $result->context ?? [];

        $sheet = $config['sheet'] ?? $meta['sheet'] ?? $ctx['section'] ?? '';
        $section = $config['section'] ?? $meta['section'] ?? $ctx['section'] ?? '';
        $letra = $meta['letra'] ?? '';
        $label = $meta['label'] ?? '';
        $type = $result->rule_type;
        $severity = $result->severity ?? 'error';

        $tipoError = $type === 'functional_rule' ? 'funcional' : 'tecnico';

        $sourceLetters = $config['source_letters'] ?? [];
        $sourceLettersStr = !empty($sourceLetters)
            ? implode(' + ', array_map('strtoupper', $sourceLetters))
            : '';

        $concept = $ctx['concept'] ?? $ctx['description'] ?? '';
        $professional = $ctx['profesional'] ?? $ctx['professional'] ?? '';
        $campoLimpio = trim("{$concept} / {$professional}", ' /');
        if (empty($campoLimpio)) {
            $campoLimpio = $this->extractCleanFieldName($label, $letra, $type, $sourceLettersStr);
        }
        if (isset($ctx['row_number'])) {
            $campoLimpio = 'Fila ' . $ctx['row_number'] . ($campoLimpio ? ' - ' . $campoLimpio : '');
        }

        $criterio = $ctx['criterio'] ?? null;
        $pendingCells = $ctx['pending_cells'] ?? [];
        $rowNumber = isset($ctx['row_number']) ? (int) $ctx['row_number'] : null;
        $sectionMeta = $type === 'functional_rule'
            ? $this->resolveFunctionalSectionMeta($result->rem_upload_id, $sheet, $rowNumber)
            : ['section_code' => null, 'section_name' => null, 'section_order' => null];

        if ($type === 'functional_rule') {
            $mensaje = $result->message ?? $mensaje ?? '';
            $cleanMsg = $criterio ? $this->formatFunctionalMessage($concept, $professional, $criterio) : null;
            if ($cleanMsg) {
                $mensaje = $cleanMsg;
            }
            $recomendacion = $criterio
                ? $this->buildFunctionalRecommendation($criterio, $ctx['row_number'] ?? 0, $concept, $professional)
                : 'Revise la decisión funcional aplicada a la fila y corrija el valor según corresponda.';
        } else {
            $recomendacion = $this->buildRecommendation($type, $severity, $sheet, $section, $campoLimpio, $sourceLettersStr);
            $mensaje = $result->message ?? $this->buildMessage($type, $severity, $sheet, $section, $letra, $campoLimpio, $sourceLettersStr);
        }

        return [
            'id' => $result->id,
            'form' => $sheet,
            'section' => $section,
            'section_code' => $sectionMeta['section_code'],
            'section_name' => $sectionMeta['section_name'],
            'section_order' => $sectionMeta['section_order'],
            'row_number' => $rowNumber,
            'campo' => $campoLimpio,
            'columna' => $letra,
            'tipo' => $type,
            'tipo_error' => $tipoError,
            'mensaje' => $mensaje,
            'recomendacion' => $recomendacion,
            'filas_afectadas' => 1,
            'filas_totales' => 1,
            'severidad' => $severity,
            'criterio' => $criterio,
            'row_concept' => $concept,
            'row_profesional' => $professional,
            'row_total' => $ctx['row_total'] ?? null,
            'pending_cells' => $pendingCells,
            'pending_cells_count' => $ctx['pending_cells_count'] ?? count($pendingCells),
        ];
    }

    private function resolveFunctionalSectionMeta(int $uploadId, string $sheet, ?int $rowNumber): array
    {
        if ($sheet === '' || $rowNumber === null) {
            return ['section_code' => null, 'section_name' => null, 'section_order' => null];
        }

        $cacheKey = "{$uploadId}|{$sheet}|{$rowNumber}";
        if (isset($this->sectionMetaCache[$cacheKey])) {
            return $this->sectionMetaCache[$cacheKey];
        }

        $remData = $this->getRemDataIndex($uploadId)[strtoupper($sheet)][$rowNumber] ?? null;

        $sectionCode = $remData?->data['rem_section_code'] ?? null;
        if (!is_string($sectionCode) || trim($sectionCode) === '') {
            return $this->sectionMetaCache[$cacheKey] = [
                'section_code' => null,
                'section_name' => null,
                'section_order' => null,
            ];
        }

        $sectionInfo = $this->getSectionInfoCached($sheet, $sectionCode);

        return $this->sectionMetaCache[$cacheKey] = [
            'section_code' => $sectionCode,
            'section_name' => $sectionInfo['titulo'] ?? null,
            'section_order' => $sectionInfo['fila_inicio'] ?? null,
        ];
    }

    /**
     * Construye (una sola vez por uploadId) el indice SHEET->rowNumber de
     * RemData. Preserva EXACTAMENTE la semantica de
     * Collection::first(fn($row) => ...) del codigo original: si dos filas
     * de RemData coinciden en (sheet,row_number), se conserva la PRIMERA
     * encontrada en el orden natural de la consulta -- nunca se
     * sobreescribe con una posterior.
     *
     * @return array<string, array<int, RemData>>
     */
    private function getRemDataIndex(int $uploadId): array
    {
        if (isset($this->remDataIndexCache[$uploadId])) {
            return $this->remDataIndexCache[$uploadId];
        }

        $index = [];
        RemData::query()
            ->where('rem_upload_id', $uploadId)
            ->get()
            ->each(function (RemData $row) use (&$index) {
                $data = $row->data ?? [];
                $sheet = strtoupper((string) ($data['section'] ?? $row->section ?? ''));
                $rowNumber = (int) ($data['row_number'] ?? 0);

                if (!isset($index[$sheet][$rowNumber])) {
                    $index[$sheet][$rowNumber] = $row;
                }
            });

        return $this->remDataIndexCache[$uploadId] = $index;
    }

    private function getSectionInfoCached(string $sheet, string $sectionCode): ?array
    {
        $key = "{$sheet}|{$sectionCode}";
        if (array_key_exists($key, $this->sectionInfoCache)) {
            return $this->sectionInfoCache[$key];
        }

        return $this->sectionInfoCache[$key] = $this->certificationService?->getSectionInfo($sheet, $sectionCode);
    }

    private function formatFunctionalMessage(string $concept, string $professional, array $criterio): ?string
    {
        $emptyBehavior = $criterio['empty_behavior'] ?? '';
        $label = trim($concept ? ($professional ? "{$concept} ({$professional})" : $concept) : '');

        return match ($emptyBehavior) {
            'debe_registrar_cero' => $label
                ? "La fila {$label} debe registrar un valor 0. No puede quedar vacía según el criterio funcional aprobado."
                : 'La fila debe registrar un valor 0 según el criterio funcional aprobado.',
            'incluir' => $label
                ? "La fila {$label} debe incluir datos según el criterio funcional aprobado."
                : 'La fila debe incluir datos según el criterio funcional aprobado.',
            'excluir' => $label
                ? "La fila {$label} está excluida según el criterio funcional aprobado."
                : 'La fila está excluida según el criterio funcional aprobado.',
            default => null,
        };
    }

    private function buildFunctionalRecommendation(array $criterio, int $rowNum, string $concept, string $professional): string
    {
        $emptyBehavior = $criterio['empty_behavior'] ?? '';
        return match ($emptyBehavior) {
            'debe_registrar_cero' => "Revise la fila {$rowNum} y registre 0 en las celdas habilitadas si no hubo prestaciones durante el período.",
            'incluir' => "Revise la fila {$rowNum} y complete los datos requeridos según la decisión funcional.",
            'excluir' => "La fila {$rowNum} está excluida por decisión funcional. Verifique si corresponde mantener los datos.",
            default => "Revise la fila {$rowNum} y verifique los valores registrados.",
        };
    }

    private function isGenericLabel(string $label): bool
    {
        $trimmed = trim($label);
        if ($trimmed === '') {
            return true;
        }
        // Single or multi-letter column reference: "W", "AA", "Z"
        if (preg_match('/^[A-Z]{1,3}$/i', $trimmed)) {
            return true;
        }
        // Generic prefixed: "Campo W", "Campo AA", "Columna Z"
        if (preg_match('/^(Campo|Columna)\s+[A-Z]{1,3}$/i', $trimmed)) {
            return true;
        }
        return false;
    }

    private function extractCleanFieldName(string $rawLabel, string $letra, string $type, string $sourceLettersStr = ''): string
    {
        if ($type === 'sum_equals') {
            if ($this->isGenericLabel($rawLabel)) {
                if (!empty($sourceLettersStr)) {
                    return "Total (suma {$sourceLettersStr})";
                }
                return 'Total';
            }
            return $rawLabel;
        }

        if ($type === 'required_and_le_parent') {
            $name = $this->extractVariableNameFromFormula($rawLabel);
            if ($name !== null) {
                return $name;
            }
        }

        if (!$this->isGenericLabel($rawLabel)) {
            return $rawLabel;
        }

        return "columna {$letra}";
    }

    private function extractVariableNameFromFormula(string $formula): ?string
    {
        preg_match_all('/"([^"]{10,})"/', $formula, $matches);
        foreach ($matches[1] as $text) {
            if (preg_match('/variable\s+(.+?)[\.\"]/u', $text, $m)) {
                $name = trim($m[1]);
                if (!empty($name)) {
                    return $name;
                }
            }
        }
        return null;
    }

    private function buildMessage(string $type, string $severity, string $sheet, string $section, string $letra, string $campo, string $sourceLetters): string
    {
        if ($type === 'sum_equals') {
            $msg = "El campo {$campo} debe ser igual a la suma de las columnas {$sourceLetters}.";
            $location = $this->buildLocation($sheet, $section);
            return $location ? "{$location}: {$msg}" : $msg;
        }

        if ($type === 'required_and_le_parent') {
            $lines = [];
            $lines[] = "Debe ingresar un valor para {$campo}.";
            $lines[] = "Si la variable no corresponde, registre 0.";
            $lines[] = "El valor de {$campo} no puede ser mayor que el Total.";
            return implode(' ', $lines);
        }

        if ($type === 'required') {
            return "Debe ingresar un valor para {$campo}. Si la variable no corresponde, registre 0.";
        }

        return "La regla de validación no se cumple para {$campo}.";
    }

    private function buildRecommendation(string $type, string $severity, string $sheet, string $section, string $campo, string $sourceLetters): string
    {
        if ($type === 'sum_equals') {
            $base = "Revise los valores de las columnas involucradas en el formulario {$sheet}.";
            return $base . ' Corrija la suma antes de enviar el REM.';
        }

        if ($type === 'required_and_le_parent') {
            if ($severity === 'warning') {
                return "Revise los registros del formulario {$sheet}. Verifique los valores digitados para {$campo}. Si corresponde, registre 0.";
            }
            return "Revise los registros del formulario {$sheet}. El valor de {$campo} no puede superar el Total informado.";
        }

        if ($type === 'required') {
            return "Revise los registros del formulario {$sheet}. Si la variable {$campo} no corresponde, registre 0.";
        }

        return "Revise los registros del formulario {$sheet} y verifique los valores digitados.";
    }

    private function buildLocation(string $sheet, string $section): string
    {
        $parts = [];
        if (!empty($sheet)) {
            $parts[] = "Formulario {$sheet}";
        }
        if (!empty($section)) {
            $parts[] = "sección {$section}";
        }
        return implode(', ', $parts);
    }
}
