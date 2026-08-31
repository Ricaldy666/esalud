<?php

namespace App\Console\Commands;

use App\Domain\REM\Models\RemData;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Domain\RuleEngine\Services\RuleBindingReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Completa rem_rules.config.total_row de UNA regla individual, EXCLUSIVAMENTE
 * a partir del candidato descubierto por la Fase 1 de auto-discovery
 * (RuleBindingReconciliationService::classifySingleRule() ->
 * total_row_candidate/total_row_position/total_row_excluded, 2026-08-27,
 * ver CLAUDE.md punto 16.10/16.11). Deliberadamente NO recibe el numero de
 * fila como argumento -- un operador nunca puede introducir un total_row
 * arbitrario, solo confirmar (o no) el candidato que el propio motor ya
 * descubrio y valido.
 *
 * Disenado para la Fase 2 (punto 16.13): las 11 reglas confirmadas
 * (50,51,53,72,73,110,111,187,429,430,431). La regla 461 (A30/F) queda
 * explicitamente rechazada por el guard 7 (candidato fuera de
 * [filaInicioDatos:filaFinDatos]) -- NUNCA una excepcion especial para
 * ella, el mismo guard que aplica a cualquier otra regla.
 *
 * Dry-run por defecto -- persistir exige --commit + --reason + --by.
 *
 * Guards, en este orden exacto (cualquier fallo aborta sin escribir):
 *  1. La regla existe y esta 'active'.
 *  2. Clasificacion actual (via classifySingleRule) es BLOCKED_BY_ENGINE_GAP.
 *  3. config.total_row esta ausente/null.
 *  4. Hay EXACTAMENTE un candidato descubierto (total_row_candidate !== null
 *     -- la propia Fase 1 ya se abstiene en caso de ambiguedad).
 *  5. total_row_position === 'leading'.
 *  6. total_row_excluded === false.
 *  7. El candidato cae DENTRO de [filaInicioDatos:filaFinDatos] de la
 *     seccion viva -- guard explicito y separado de la simulacion (guard 9),
 *     para que quede un motivo especifico y auditable (ver hallazgo regla
 *     461, punto 16.13: candidato 123 fuera de [124:129]).
 *  8. Existe evidencia real persistida (rem_data) para esa fila exacta.
 *  9. Simulando en memoria UNICAMENTE config.total_row=candidato (nunca
 *     persistido), la regla reclasifica exactamente SAFE_1_TO_1.
 *  10. Ausencia de colision funcional: ninguna OTRA regla activa comparte
 *      ya la misma clave sheet+seccion+columna+tipo (aprendido del caso
 *      529 vs 530, 2026-08-27) -- total_row nunca es parte de esa clave,
 *      asi que este guard es estructuralmente redundante con el 9, pero se
 *      verifica de forma explicita e independiente de todos modos.
 *
 * NUNCA toca bindings, status, RuleExecutionLog, rem_validation_results,
 * calibraciones, rem_data ni estructura -- solo 'config.total_row' de la
 * regla indicada, preservando el resto de config byte-identico.
 */
class RuleSetTotalRowFromDiscoveryCommand extends Command
{
    protected $signature = 'rule:set-total-row
                            {rule_id : ID de la regla}
                            {--reason= : Motivo -- obligatorio para --commit}
                            {--by= : Responsable -- obligatorio para --commit}
                            {--commit : Persiste el cambio de config.total_row. Sin este flag, el comando SOLO reporta (dry-run).}';

    protected $description = 'Completa config.total_row a partir del candidato de Fase 1 (dry-run por defecto, --commit para persistir)';

    public function handle(RuleBindingReconciliationService $reconciliation): int
    {
        $ruleId = (int) $this->argument('rule_id');
        $reason = $this->option('reason');
        $by = $this->option('by');
        $commit = (bool) $this->option('commit');

        $data = $this->computeAndValidate($ruleId, $reconciliation);
        if ($data === null) {
            return self::FAILURE;
        }

        $this->printReport($data);

        if (!$commit) {
            $this->newLine();
            $this->info('DRY-RUN: no se persistio ningun cambio. Ejecuta con --commit --reason=... --by=... para persistir.');

            return self::SUCCESS;
        }

        if (!$reason || !$by) {
            $this->error('--reason y --by son obligatorios para --commit.');

            return self::FAILURE;
        }

        $revalidated = $this->computeAndValidate($ruleId, $reconciliation);
        if ($revalidated === null
            || $revalidated['old_config'] !== $data['old_config']
            || $revalidated['candidate'] !== $data['candidate']
        ) {
            $this->error('La regla (o su candidato descubierto) cambio entre la validacion y la escritura -- abortando sin persistir.');

            return self::FAILURE;
        }

        return $this->commitTotalRow($revalidated, $reason, $by);
    }

    private function computeAndValidate(int $ruleId, RuleBindingReconciliationService $reconciliation): ?array
    {
        // Guard 1: existe y activa.
        $rule = Rule::find($ruleId);
        if (!$rule) {
            $this->error("No existe ninguna regla con id={$ruleId}.");

            return null;
        }
        if ($rule->status !== 'active') {
            $this->error("La regla {$ruleId} no esta activa (status={$rule->status}).");

            return null;
        }

        $structure = RemTemplateStructure::where('status', 'active')->first();
        if (!$structure) {
            $this->error('No hay ninguna estructura activa.');

            return null;
        }

        $diagnostic = $reconciliation->classifySingleRule($rule, $structure);

        // Guard 2: clasificacion actual.
        if ($diagnostic['clasificacion'] !== RuleBindingReconciliationService::BLOCKED_BY_ENGINE_GAP) {
            $this->error("La regla {$ruleId} esta clasificada '{$diagnostic['clasificacion']}', no BLOCKED_BY_ENGINE_GAP. No aplica este comando.");

            return null;
        }

        $config = $rule->config;

        // Guard 3: total_row ausente.
        if (isset($config['total_row'])) {
            $this->error("La regla {$ruleId} ya tiene total_row en config (=" . $config['total_row'] . '). No hay nada que completar.');

            return null;
        }

        // Guard 4: candidato unico.
        $candidate = $diagnostic['total_row_candidate'];
        if ($candidate === null) {
            $this->error("La Fase 1 no encontro un candidato unico de total_row para la regla {$ruleId} (ausente o ambiguo). No se puede completar automaticamente.");

            return null;
        }

        // Guard 5: posicion leading.
        if ($diagnostic['total_row_position'] !== 'leading') {
            $this->error("El candidato de la regla {$ruleId} es '{$diagnostic['total_row_position']}', no 'leading'. Este comando solo opera sobre candidatos leading no excluidos.");

            return null;
        }

        // Guard 6: no excluido.
        if ($diagnostic['total_row_excluded'] !== false) {
            $this->error("El candidato (fila {$candidate}) de la regla {$ruleId} esta excluido de rem_data (mecanismo #6/#12) -- completar total_row no lo haria evaluable. No se procede.");

            return null;
        }

        // Guard 7: candidato dentro de [filaInicioDatos:filaFinDatos] de la
        // seccion viva -- guard explicito, separado de la simulacion, para
        // que la razon del rechazo quede clara (ver regla 461, punto 16.13).
        $sheet = strtoupper($config['sheet'] ?? '');
        $section = strtoupper($config['section'] ?? '');
        $rawSection = $this->findRawSectionData($structure, $sheet, $section);
        $inicio = $rawSection['filaInicioDatos'] ?? null;
        $fin = $rawSection['filaFinDatos'] ?? null;
        if ($inicio === null || $fin === null || $candidate < $inicio || $candidate > $fin) {
            $this->error("El candidato (fila {$candidate}) de la regla {$ruleId} cae fuera del rango vivo de la seccion [{$inicio}:{$fin}]. Rechazado -- ver deuda auditada (CLAUDE.md punto 16.13) para el caso conocido de la regla 461.");

            return null;
        }

        // Guard 8: evidencia real persistida (rem_data) para esa fila exacta.
        $configSection = $config['section'] ?? '';
        $hasEvidence = RemData::where('section', $sheet)->get()->contains(function ($r) use ($configSection, $candidate) {
            return ($r->data['rem_section_code'] ?? null) === $configSection && ($r->data['row_number'] ?? null) === $candidate;
        });
        if (!$hasEvidence) {
            $this->error("No existe evidencia real en rem_data para {$sheet}/{$configSection} fila {$candidate}. No se procede sin evidencia persistida.");

            return null;
        }

        // Guard 9: simulacion en memoria -- SOLO total_row cambia.
        $newConfig = $config;
        $newConfig['total_row'] = $candidate;

        $simulatedRule = $rule->replicate();
        $simulatedRule->id = $rule->id;
        $simulatedRule->config = $newConfig;

        $simulatedDiagnostic = $reconciliation->classifySingleRule($simulatedRule, $structure);
        if ($simulatedDiagnostic['clasificacion'] !== RuleBindingReconciliationService::SAFE_1_TO_1) {
            $this->error("Simulando total_row={$candidate}, la regla {$ruleId} clasifica '{$simulatedDiagnostic['clasificacion']}' (motivo: {$simulatedDiagnostic['motivo']}), no SAFE_1_TO_1. No se procede.");

            return null;
        }

        // Guard 10: ausencia de colision funcional con otra regla activa
        // (aprendido del caso 529 vs 530). total_row nunca es parte de la
        // clave funcional (sheet+seccion+columna+tipo) -- este chequeo es
        // estructuralmente redundante con el guard 9 (una colision real
        // habria devuelto DUPLICATE, no SAFE_1_TO_1), pero se verifica de
        // forma explicita e independiente de todos modos.
        $column = strtoupper($config['column'] ?? '');
        $type = $rule->rule_type;
        $collision = Rule::where('status', 'active')
            ->where('id', '!=', $rule->id)
            ->get()
            ->first(function ($other) use ($sheet, $configSection, $column, $type) {
                $oc = $other->config;

                return strtoupper($oc['sheet'] ?? '') === $sheet
                    && strtoupper($oc['section'] ?? '') === strtoupper($configSection)
                    && strtoupper($oc['column'] ?? '') === $column
                    && $other->rule_type === $type;
            });
        if ($collision !== null) {
            $this->error("La regla {$ruleId} colisionaria con la regla {$collision->id} (misma clave funcional {$sheet}/{$configSection}/{$column}/{$type}). No se procede -- ver caso 529 vs 530.");

            return null;
        }

        return [
            'rule' => $rule,
            'rule_id' => $ruleId,
            'sheet' => $sheet,
            'section' => $configSection,
            'old_config' => $config,
            'new_config' => $newConfig,
            'candidate' => $candidate,
            'position' => $diagnostic['total_row_position'],
            'excluded' => $diagnostic['total_row_excluded'],
            'structure' => $structure,
            'before_clasificacion' => $diagnostic['clasificacion'],
            'after_clasificacion' => $simulatedDiagnostic['clasificacion'],
        ];
    }

    private function findRawSectionData(RemTemplateStructure $structure, string $sheet, string $section): ?array
    {
        $est = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) !== $sheet) {
                continue;
            }
            foreach ($form['sections'] ?? [] as $sec) {
                if (strtoupper((string) ($sec['codigo'] ?? '')) === $section) {
                    return $sec;
                }
            }
        }

        return null;
    }

    private function printReport(array $d): void
    {
        $this->info("Regla {$d['rule_id']} ({$d['rule']->rule_key}) -- total_row propuesto: {$d['candidate']} (posicion={$d['position']}, excluded=" . ($d['excluded'] ? 'si' : 'no') . ')');
        $this->newLine();

        $this->line('Config ANTES:');
        $this->line('  ' . json_encode($d['old_config'], JSON_UNESCAPED_UNICODE));
        $this->line('Config PROPUESTA:');
        $this->line('  ' . json_encode($d['new_config'], JSON_UNESCAPED_UNICODE));
        $this->line("Diff exacto: total_row: (ausente) -> {$d['candidate']} (unica clave que cambia)");
        $this->newLine();

        $this->line("Clasificacion ANTES: {$d['before_clasificacion']}");
        $this->line("Clasificacion SIMULADA DESPUES (solo total_row, nada persistido): {$d['after_clasificacion']}");
        $this->newLine();

        $bindings = RuleBinding::where('rule_id', $d['rule_id'])->get();
        if ($bindings->isEmpty()) {
            $this->line('Bindings actuales: (ninguno)');
        } else {
            $this->table(
                ['binding_id', 'bindable_type', 'bindable_id', 'active'],
                $bindings->map(fn ($b) => [$b->id, $b->bindable_type, $b->bindable_id, $b->active ? 'si' : 'no'])->all()
            );
        }
        $this->line('Este comando NUNCA crea/modifica bindings.');
        $this->newLine();

        $this->line('Escritura EXACTA que ejecutaria --commit:');
        $this->line("  rem_rules.id={$d['rule_id']}.config->total_row = {$d['candidate']} (resto de config sin cambios)");
        $this->line('  + 1 fila nueva en rem_rule_versions (snapshot del config ANTERIOR)');
        $this->line('  + 1 entrada en el activity log (rule_total_row_set)');
        $this->line('  Ningun binding, status, RuleExecutionLog, rem_validation_results, calibracion, rem_data ni estructura se toca.');
    }

    private function commitTotalRow(array $d, string $reason, string $by): int
    {
        try {
            DB::transaction(function () use ($d, $reason, $by): void {
                $rule = Rule::find($d['rule_id']);
                if (!$rule || $rule->config !== $d['old_config']) {
                    throw new \RuntimeException('La regla cambio entre la validacion y la escritura -- abortando sin persistir.');
                }

                RuleVersion::create([
                    'rule_id' => $rule->id,
                    'version' => $rule->version,
                    'config' => $d['old_config'],
                    'changelog' => "rule:set-total-row: total_row completado desde candidato Fase 1 = {$d['candidate']} (posicion={$d['position']}). Motivo: {$reason}. Responsable: {$by}. " . now()->toIso8601String(),
                    'created_by' => null,
                ]);

                $rule->update([
                    'config' => $d['new_config'],
                    'updated_by' => null,
                ]);

                activity()
                    ->performedOn($rule)
                    ->withProperties([
                        'rule_id' => $rule->id,
                        'rule_key' => $rule->rule_key,
                        'total_row_set' => $d['candidate'],
                        'total_row_position' => $d['position'],
                        'reason' => $reason,
                        'by' => $by,
                    ])
                    ->log('rule_total_row_set');
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Regla {$d['rule_id']}: config.total_row completado con {$d['candidate']}. RuleVersion de auditoria creado. Activity log registrado.");

        return self::SUCCESS;
    }
}
