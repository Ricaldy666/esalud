<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Services\RuleBindingReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea bindings structure->{--structure} UNICAMENTE para reglas activas
 * clasificadas SAFE_1_TO_1 por RuleBindingReconciliationService (misma
 * hoja, seccion, columnas y rango de filas vigentes; nunca en hojas
 * marcadas no_utilizada). Ver auditoria Fase 3 / reconciliacion Fase 3b
 * (2026-08-11).
 *
 * Diseño deliberadamente distinto de rem:set-sheet-usage-status: aqui el
 * modo por defecto es DRY-RUN (solo reporta), y persistir exige el flag
 * explicito --commit -- el usuario pidio ver el dry-run completo antes de
 * autorizar cualquier escritura, asi que "sin --commit" es el camino mas
 * seguro por defecto.
 *
 * Nunca toca bindings existentes (no los borra, no los desactiva) ni
 * modifica rem_rules -- solo AGREGA bindings nuevos, vía
 * RuleBinding::updateOrCreate() (idempotente: si ya existe un binding
 * equivalente para la estructura destino, no crea uno duplicado).
 *
 * Cada regla candidata se reclasifica dos veces: una vez al construir la
 * lista de candidatos, y una segunda vez inmediatamente antes de
 * persistir cada binding (RuleBindingReconciliationService::isStillSafe()).
 * Si CUALQUIER regla deja de calificar como SAFE_1_TO_1 entre esos dos
 * momentos, se aborta el lote COMPLETO (rollback) -- no se persiste nada
 * parcialmente.
 */
class RuleRebindSafeToStructureCommand extends Command
{
    protected $signature = 'rule:rebind-safe-to-structure
                            {--structure= : ID de la estructura destino (ej. 63) -- obligatorio}
                            {--reason= : Motivo del re-vinculo -- obligatorio}
                            {--by= : Responsable -- obligatorio}
                            {--commit : Persiste los bindings. Sin este flag, el comando SOLO reporta (dry-run).}';

    protected $description = 'Crea bindings structure->N solo para reglas SAFE_1_TO_1 (dry-run por defecto, --commit para persistir)';

    public function handle(RuleBindingReconciliationService $reconciliation): int
    {
        $structureId = $this->option('structure');
        $reason = $this->option('reason');
        $by = $this->option('by');
        $commit = (bool) $this->option('commit');

        if (!$structureId || !$reason || !$by) {
            $this->error('--structure, --reason y --by son obligatorios. Nunca se re-vincula sin motivo y responsable explicitos.');
            return self::FAILURE;
        }

        $structure = RemTemplateStructure::find((int) $structureId);
        if (!$structure) {
            $this->error("No existe ninguna estructura con id={$structureId}.");
            return self::FAILURE;
        }

        $this->line("Estructura destino: ID {$structure->id} (v{$structure->version_number}, {$structure->anio}/{$structure->serie}, status={$structure->status})");
        $this->line('Modo: ' . ($commit ? '*** COMMIT (persistira) ***' : 'DRY-RUN (no persiste nada)'));
        $this->newLine();

        $candidates = $reconciliation->findSafeCandidatesForStructure($structure);

        if ($candidates->isEmpty()) {
            $this->warn('No hay reglas SAFE_1_TO_1 para vincular a esta estructura.');
            return self::SUCCESS;
        }

        $existingBindingsCount = RuleBinding::where('bindable_type', 'structure')
            ->where('bindable_id', $structure->id)
            ->where('active', true)
            ->count();

        $ruleIds = $candidates->pluck('rule_id');
        $oldBindingsByRule = RuleBinding::where('bindable_type', 'structure')
            ->whereIn('rule_id', $ruleIds)
            ->where('active', true)
            ->get()
            ->groupBy('rule_id');

        $alreadyBoundToTarget = RuleBinding::where('bindable_type', 'structure')
            ->where('bindable_id', $structure->id)
            ->whereIn('rule_id', $ruleIds)
            ->where('active', true)
            ->pluck('rule_id')
            ->flip();

        $this->table(
            ['rule_id', 'binding antiguo', 'hoja', 'seccion', 'destino 63', 'alcance', 'row_range', 'columnas', 'regla cambia?', 'ya vinculada'],
            $candidates->map(function (array $c) use ($oldBindingsByRule, $alreadyBoundToTarget) {
                $oldIds = ($oldBindingsByRule[$c['rule_id']] ?? collect())->pluck('bindable_id')->unique()->implode(',');
                $rowRange = $c['row_range'] ? "[{$c['row_range']['from']}:{$c['row_range']['to']}]" : '-';
                return [
                    $c['rule_id'],
                    $oldIds !== '' ? "structure:{$oldIds}" : '(ninguno)',
                    $c['hoja'],
                    $c['seccion_antigua'],
                    $c['destino'],
                    $c['alcance'] ?? 'per_row',
                    $rowRange,
                    implode(',', $c['columnas']),
                    'NO',
                    $alreadyBoundToTarget->has($c['rule_id']) ? 'SI (no-op)' : 'no',
                ];
            })->all()
        );

        $newBindingsNeeded = $candidates->count() - $alreadyBoundToTarget->count();

        $this->newLine();
        $this->line("Candidatos SAFE_1_TO_1 evaluados: {$candidates->count()}");
        $this->line("Ya vinculados a la estructura destino (no-op): {$alreadyBoundToTarget->count()}");
        $this->line("Bindings NUEVOS que se crearian: {$newBindingsNeeded}");
        $this->line("Bindings activos hacia estructura {$structure->id} -- antes: {$existingBindingsCount}, despues (esperado): " . ($existingBindingsCount + $newBindingsNeeded));
        $this->line('rem_rules: 0 filas modificadas (este comando nunca escribe en rem_rules).');
        $this->line('Bindings antiguos (19/21 u otros): 0 modificados/eliminados (este comando solo agrega).');

        if (!$commit) {
            $this->newLine();
            $this->info('DRY-RUN: no se persistio ningun cambio. Ejecuta con --commit para persistir.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('*** Persistiendo bindings (--commit) ***');

        $created = 0;
        $skippedAlready = 0;

        try {
            DB::transaction(function () use ($candidates, $structure, $reason, $by, $reconciliation, &$created, &$skippedAlready) {
                foreach ($candidates as $c) {
                    $rule = Rule::find($c['rule_id']);

                    if (!$rule || !$reconciliation->isStillSafe($rule, $structure)) {
                        throw new \RuntimeException(
                            "Abortando lote completo: la regla {$c['rule_id']} ({$c['rule_key']}) ya no cumple SAFE_1_TO_1 al momento de persistir. "
                            . 'No se creo ningun binding de este lote.'
                        );
                    }

                    $alreadyExists = RuleBinding::where('rule_id', $rule->id)
                        ->where('bindable_type', 'structure')
                        ->where('bindable_id', $structure->id)
                        ->where('active', true)
                        ->exists();

                    if ($alreadyExists) {
                        $skippedAlready++;
                        continue;
                    }

                    $binding = RuleBinding::updateOrCreate(
                        [
                            'rule_id' => $rule->id,
                            'bindable_type' => 'structure',
                            'bindable_id' => $structure->id,
                        ],
                        [
                            'serie' => $structure->serie,
                            'anio' => $structure->anio,
                            'active' => true,
                            'conditions' => [
                                'created_via' => 'rule:rebind-safe-to-structure',
                                'classification' => RuleBindingReconciliationService::SAFE_1_TO_1,
                                'reason' => $reason,
                                'decided_by' => $by,
                                'decided_at' => now()->toIso8601String(),
                                'rule_unchanged' => true,
                            ],
                        ]
                    );

                    activity()
                        ->performedOn($binding)
                        ->withProperties([
                            'rule_id' => $rule->id,
                            'rule_key' => $rule->rule_key,
                            'target_structure_id' => $structure->id,
                            'reason' => $reason,
                            'by' => $by,
                        ])
                        ->log('rule_binding_rebind_safe');

                    $created++;
                }
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Bindings nuevos creados: {$created}. Ya existentes (no-op): {$skippedAlready}.");

        return self::SUCCESS;
    }
}
