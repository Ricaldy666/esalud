<?php

namespace App\Console\Commands;

use App\Domain\REM\Models\RemData;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\FormulaRangeCoverageAnalyzer;
use App\Domain\RuleEngine\Services\RuleBindingReconciliationService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3C-2 (CLAUDE.md punto 17.17/17.18). Activa UNA regla individual de
 * Categoria C (leading, TOTAL tecnico excluido, candidato YA dentro de
 * [filaInicioDatos:filaFinDatos]) escribiendo UNICAMENTE config.total_row.
 * NUNCA toca row_range.
 *
 * Deliberadamente NO requiere ningun cambio a RuleBindingReconciliationService
 * ni al guard generico de classifyRule() -- a diferencia de Fase 3C-1B
 * (trailing FUERA de bounds, que si necesito una excepcion aislada), estas
 * 29 reglas ya caen DENTRO de [filaInicioDatos:filaFinDatos]; el bounds-check
 * generico, sin modificar, ya las acepta en cuanto total_row se escribe --
 * confirmado empiricamente en la auditoria de Fase 3C-2 (punto 17.17).
 *
 * Es, en esencia, la Rama 1 de rule:activate-category-a (Fase 3C-1A)
 * aplicada a position=leading en vez de trailing -- mismo patron de guards,
 * misma trazabilidad (RuleVersion + activity log), pero comando separado
 * (rule:activate-category-a queda cerrado y sin tocar, ver prohibicion
 * vigente) para no reabrir un mecanismo ya ejecutado.
 *
 * NUNCA recibe el numero de fila como argumento -- se deriva exclusivamente
 * del candidato ya descubierto por Fase 1 (classifySingleRule()).
 *
 * La regla 461 se rechaza por los guards NORMALES (excluded=false Y
 * candidato fuera de [inicio:fin]) -- nunca por un caso especial
 * "if rule_id === 461".
 *
 * Guards, en este orden exacto (cualquier fallo aborta sin escribir):
 *  1. Regla existe, activa, rule_type=sum_equals.
 *  2. Clasificacion actual = BLOCKED_BY_ENGINE_GAP.
 *  3. Hoja NO marcada 'no_utilizada'.
 *  4. config.total_row ausente.
 *  5. row_range real en config (nunca el placeholder {0,0}).
 *  6. Candidato descubierto por Fase 1 (unico), position=leading,
 *     excluded=true.
 *  7. Candidato dentro de [filaInicioDatos:filaFinDatos] de la seccion
 *     viva -- guard explicito y separado (rechaza 461 aqui, sin caso
 *     especial).
 *  8. Fila candidata NO reclamada por ninguna otra seccion declarada de
 *     la misma hoja.
 *  9. Mecanismo #6 (isEmbeddedLeadingTotalRow) confirmado en vivo --
 *     independiente del flag ya visto en el guard 6 (misma redundancia
 *     deliberada usada en Fase 3C-1B con isEmbeddedBackwardSubtotalRow).
 *  10. Formula real completa/contigua/sin referencias externas, verificada
 *      de forma independiente (FormulaRangeCoverageAnalyzer) -- rechaza
 *      huecos y terminos externos sin caso especial.
 *  11. Simulacion final (SOLO total_row=candidato, row_range sin tocar)
 *      clasifica EXACTAMENTE SAFE_1_TO_1 -- usando classifySingleRule() sin
 *      ninguna modificacion.
 *  12. Ausencia de colision funcional con otra regla activa (patron
 *      529 vs 530).
 *
 * NUNCA toca bindings, status, rem_data, rem_technical_totals,
 * calibraciones, estructura, RuleBindingReconciliationService ni el guard
 * generico de classifyRule() -- solo config.total_row de la regla indicada.
 */
class RuleActivateCategoryCLeadingCommand extends Command
{
    protected $signature = 'rule:activate-category-c-leading
                            {rule_id : ID de la regla}
                            {--reason= : Motivo -- obligatorio para --commit}
                            {--by= : Responsable -- obligatorio para --commit}
                            {--commit : Persiste config.total_row. Sin este flag, el comando SOLO reporta (dry-run).}';

    protected $description = 'Fase 3C-2: activa una regla de Categoria C (leading, dentro de bounds) escribiendo total_row derivado de evidencia real (dry-run por defecto)';

    public function __construct(
        private CellDataStorageService $cellDataStorage,
        private SectionCalibrationMatrixService $calibrationMatrix,
    ) {
        parent::__construct();
    }

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
            $this->error('La regla (o su candidato) cambio entre la validacion y la escritura -- abortando sin persistir.');

            return self::FAILURE;
        }

        return $this->commit($revalidated, $reason, $by);
    }

    private function computeAndValidate(int $ruleId, RuleBindingReconciliationService $reconciliation): ?array
    {
        // Guard 1: existe, activa, sum_equals.
        $rule = Rule::find($ruleId);
        if (!$rule) {
            $this->error("No existe ninguna regla con id={$ruleId}.");

            return null;
        }
        if ($rule->status !== 'active') {
            $this->error("La regla {$ruleId} no esta activa (status={$rule->status}).");

            return null;
        }
        if ($rule->rule_type !== 'sum_equals') {
            $this->error("La regla {$ruleId} no es rule_type=sum_equals (es '{$rule->rule_type}'). Fuera de alcance.");

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

        // Guard 3: no_utilizada.
        if ($diagnostic['hoja_no_utilizada'] === true) {
            $this->error("La hoja de la regla {$ruleId} esta marcada 'no_utilizada' -- fuera de alcance de cualquier activacion funcional (Categoria E, ver punto 17.9). No se procede.");

            return null;
        }

        $config = $rule->config;

        // Guard 4: total_row ausente.
        if (isset($config['total_row'])) {
            $this->error("La regla {$ruleId} ya tiene total_row en config (=" . $config['total_row'] . '). No hay nada que activar.');

            return null;
        }

        // Guard 5: row_range real (nunca el placeholder {0,0}).
        $rowRange = $config['row_range'] ?? null;
        $isPlaceholderZeroZero = $rowRange === null
            || ((int) ($rowRange['from'] ?? -1) === 0 && (int) ($rowRange['to'] ?? -1) === 0);
        if ($isPlaceholderZeroZero) {
            $this->error("La regla {$ruleId} no tiene row_range real en config -- fuera de alcance de este comando (Categoria B/F, ver punto 17.10, requieren diseno propio).");

            return null;
        }

        // Guard 6: candidato descubierto por Fase 1, unico, leading, excluded=true.
        $candidate = $diagnostic['total_row_candidate'];
        if ($candidate === null) {
            $this->error("La Fase 1 no encontro un candidato unico de total_row para la regla {$ruleId} (ausente o ambiguo). No se puede activar automaticamente.");

            return null;
        }
        if ($diagnostic['total_row_position'] !== 'leading') {
            $this->error("El candidato de la regla {$ruleId} es '{$diagnostic['total_row_position']}', no 'leading'. Este comando (Fase 3C-2, Categoria C) solo opera sobre candidatos leading -- los trailing son Categoria A/3C-1, fuera de alcance.");

            return null;
        }
        if ($diagnostic['total_row_excluded'] !== true) {
            $this->error("El candidato (fila {$candidate}) de la regla {$ruleId} NO esta confirmado como excluido de rem_data por el mecanismo #6 (isEmbeddedLeadingTotalRow=false). No se procede -- este es exactamente el guard que rechaza a la regla 461.");

            return null;
        }

        $sheet = strtoupper($config['sheet'] ?? '');
        $section = strtoupper($config['section'] ?? '');
        $column = strtoupper($config['column'] ?? '');
        $rawSection = $this->findRawSectionData($structure, $sheet, $section);
        $inicio = $rawSection['filaInicioDatos'] ?? null;
        $fin = $rawSection['filaFinDatos'] ?? null;

        // Guard 7: candidato dentro de [filaInicioDatos:filaFinDatos] --
        // rechaza a la regla 461 aqui tambien, sin caso especial (ademas
        // del guard 6, que ya la rechaza por excluded=false).
        if ($inicio === null || $fin === null || $candidate < $inicio || $candidate > $fin) {
            $this->error("El candidato (fila {$candidate}) de la regla {$ruleId} cae fuera del rango vivo de la seccion [{$inicio}:{$fin}]. Rechazado -- este es exactamente el patron de la regla 461 (punto 16.13), sin excepcion especial.");

            return null;
        }

        // Guard 8: fila candidata no reclamada por ninguna otra seccion.
        $est = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) !== $sheet) {
                continue;
            }
            foreach ($form['sections'] ?? [] as $sec) {
                if (strtoupper((string) ($sec['codigo'] ?? '')) === $section) {
                    continue; // la propia seccion, ya cubierta por el guard 7
                }
                $ini = $sec['filaInicioDatos'] ?? null;
                $secFin = $sec['filaFinDatos'] ?? null;
                if ($ini !== null && $secFin !== null && $candidate >= (int) $ini && $candidate <= (int) $secFin) {
                    $this->error("La fila candidata ({$candidate}) de la regla {$ruleId} esta reclamada por la seccion '{$sec['codigo']}' de la hoja {$sheet}. No se procede.");

                    return null;
                }
            }
        }

        // Guard 9: mecanismo #6 confirmado en vivo (independiente del flag ya visto en el guard 6).
        if ($rawSection === null || !$this->calibrationMatrix->isEmbeddedLeadingTotalRow($sheet, $section, $candidate, $rawSection)) {
            $this->error("isEmbeddedLeadingTotalRow() no confirma la fila {$candidate} de la regla {$ruleId} como TOTAL tecnico excluido. No se procede.");

            return null;
        }

        // Guard 10: formula completa/contigua/sin referencias externas, verificada de forma independiente.
        $from = (int) $rowRange['from'];
        $to = (int) $rowRange['to'];
        $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $column . $candidate);
        $formula = (string) ($cell['formula'] ?? '');
        if ($cell === null || ($cell['es_formula'] ?? false) !== true || !FormulaRangeCoverageAnalyzer::isCompleteContiguous($formula, $column, $from, $to)) {
            $this->error("La formula real en {$sheet}/{$section} {$column}{$candidate} no cubre de forma completa y contigua [{$from}:{$to}] (o referencia otra columna). No se procede.");

            return null;
        }

        // Guard 11: simulacion final -- SOLO total_row cambia, row_range intacto.
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

        // Guard 12: ausencia de colision funcional (patron 529 vs 530).
        $collision = Rule::where('status', 'active')
            ->where('id', '!=', $rule->id)
            ->get()
            ->first(function ($other) use ($sheet, $section, $column) {
                $oc = $other->config;

                return strtoupper($oc['sheet'] ?? '') === $sheet
                    && strtoupper($oc['section'] ?? '') === $section
                    && strtoupper($oc['column'] ?? '') === $column
                    && $other->rule_type === 'sum_equals';
            });
        if ($collision !== null) {
            $this->error("La regla {$ruleId} colisionaria con la regla {$collision->id} (misma clave funcional {$sheet}/{$section}/{$column}/sum_equals). No se procede -- ver caso 529 vs 530.");

            return null;
        }

        return [
            'rule' => $rule,
            'rule_id' => $ruleId,
            'sheet' => $sheet,
            'section' => $section,
            'old_config' => $config,
            'new_config' => $newConfig,
            'candidate' => $candidate,
            'inicio' => $inicio,
            'fin' => $fin,
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
        $this->info("Regla {$d['rule_id']} ({$d['rule']->rule_key}) -- total_row propuesto: {$d['candidate']} (leading, dentro de [{$d['inicio']}:{$d['fin']}])");
        $this->newLine();

        $this->line('Config ANTES:');
        $this->line('  ' . json_encode($d['old_config'], JSON_UNESCAPED_UNICODE));
        $this->line('Config PROPUESTA:');
        $this->line('  ' . json_encode($d['new_config'], JSON_UNESCAPED_UNICODE));
        $this->line("Diff exacto: total_row: (ausente) -> {$d['candidate']} (unica clave que cambia -- row_range NO se toca)");
        $this->newLine();

        $this->line("Clasificacion ANTES: {$d['before_clasificacion']}");
        $this->line("Clasificacion SIMULADA DESPUES (nada persistido): {$d['after_clasificacion']}");
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
        $this->line('Este comando NUNCA crea/modifica bindings, calibraciones, rem_data, rem_technical_totals, estructura, RuleBindingReconciliationService ni el guard generico de classifyRule().');
        $this->newLine();

        $this->line('Escritura EXACTA que ejecutaria --commit:');
        $this->line("  rem_rules.id={$d['rule_id']}.config->total_row = {$d['candidate']}");
        $this->line('  + 1 fila nueva en rem_rule_versions (snapshot del config ANTERIOR)');
        $this->line('  + 1 entrada en el activity log (rule_category_c_leading_activated)');
    }

    private function commit(array $d, string $reason, string $by): int
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
                    'changelog' => "rule:activate-category-c-leading: total_row={$d['candidate']} (leading, dentro de [{$d['inicio']}:{$d['fin']}]). Motivo: {$reason}. Responsable: {$by}. " . now()->toIso8601String(),
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
                        'inicio' => $d['inicio'],
                        'fin' => $d['fin'],
                        'reason' => $reason,
                        'by' => $by,
                    ])
                    ->log('rule_category_c_leading_activated');
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Regla {$d['rule_id']}: config.total_row activado con {$d['candidate']}. RuleVersion de auditoria creado. Activity log registrado.");

        return self::SUCCESS;
    }
}
