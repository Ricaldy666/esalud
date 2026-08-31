<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleExecutionLog;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Domain\RuleEngine\Services\RuleBindingReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cambia UNICAMENTE rem_rules.status de una regla individual, dentro de una
 * lista blanca explicita ('active'/'inactive' -- ver auditoria 2026-08-27:
 * son los UNICOS valores realmente usados en el sistema hoy, 753/11 reglas
 * respectivamente; 'obsolete' nunca se ha usado y se decidio explicitamente
 * no introducirlo en esta etapa, adoptando la convencion existente).
 *
 * Disenado para el caso 529 (ver auditoria 529 vs 530, 2026-08-27): una
 * regla activa pero funcionalmente redundante (subconjunto estricto de otra
 * regla ya migrada) que debe dejar de participar en clasificacion/ejecucion
 * sin borrar ni alterar su historial real (RuleExecutionLog,
 * rem_validation_results, RuleVersion, bindings).
 *
 * Dry-run por defecto -- persistir exige --commit + --reason + --by.
 *
 * Guards:
 *  1. La regla existe.
 *  2. new_status pertenece a la lista blanca (ALLOWED_STATUSES).
 *  3. new_status difiere del status actual (si no, no hay nada que hacer).
 *  4. Doble validacion inmediata antes de --commit: re-verifica que la
 *     regla no cambio (status Y config) entre el reporte y la escritura --
 *     mismo patron de doble-chequeo que rule:remap-section y
 *     rule:restore-config-version.
 *
 * NUNCA toca config, rule_key, rule_type, name, bindings,
 * RuleExecutionLog, rem_validation_results, RuleVersion, calibraciones,
 * rem_data ni estructura -- solo 'status' (+'updated_by', que se deja en
 * null por ser foreignId hacia users.id sin usuario autenticado resoluble
 * en este flujo, igual que los comandos anteriores de esta campana).
 */
class RuleSetRuleStatusCommand extends Command
{
    public const ALLOWED_STATUSES = ['active', 'inactive'];

    protected $signature = 'rule:set-rule-status
                            {rule_id : ID de la regla}
                            {new_status : Nuevo status -- active o inactive}
                            {--reason= : Motivo del cambio -- obligatorio para --commit}
                            {--by= : Responsable -- obligatorio para --commit}
                            {--commit : Persiste el cambio de status. Sin este flag, el comando SOLO reporta (dry-run).}';

    protected $description = 'Cambia rem_rules.status de una regla individual dentro de una lista blanca (dry-run por defecto, --commit para persistir)';

    public function handle(RuleBindingReconciliationService $reconciliation): int
    {
        $ruleId = (int) $this->argument('rule_id');
        $newStatus = (string) $this->argument('new_status');
        $reason = $this->option('reason');
        $by = $this->option('by');
        $commit = (bool) $this->option('commit');

        $data = $this->computeAndValidate($ruleId, $newStatus);
        if ($data === null) {
            return self::FAILURE;
        }

        $this->printReport($data, $reconciliation);

        if (!$commit) {
            $this->newLine();
            $this->info('DRY-RUN: no se persistio ningun cambio. Ejecuta con --commit --reason=... --by=... para persistir.');

            return self::SUCCESS;
        }

        if (!$reason || !$by) {
            $this->error('--reason y --by son obligatorios para --commit.');

            return self::FAILURE;
        }

        $revalidated = $this->computeAndValidate($ruleId, $newStatus);
        if ($revalidated === null
            || $revalidated['current_status'] !== $data['current_status']
            || $revalidated['config'] !== $data['config']
        ) {
            $this->error('La regla cambio (status o config) entre la validacion y la escritura -- abortando sin persistir.');

            return self::FAILURE;
        }

        return $this->commitStatusChange($revalidated, $reason, $by);
    }

    private function computeAndValidate(int $ruleId, string $newStatus): ?array
    {
        $rule = Rule::find($ruleId);
        if (!$rule) {
            $this->error("No existe ninguna regla con id={$ruleId}.");

            return null;
        }

        if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
            $this->error("Status '{$newStatus}' no permitido. Valores permitidos: " . implode(', ', self::ALLOWED_STATUSES) . '.');

            return null;
        }

        if ($rule->status === $newStatus) {
            $this->error("La regla {$ruleId} ya tiene status='{$newStatus}'. No hay nada que cambiar.");

            return null;
        }

        return [
            'rule' => $rule,
            'rule_id' => $ruleId,
            'rule_key' => $rule->rule_key,
            'current_status' => $rule->status,
            'new_status' => $newStatus,
            'config' => $rule->config,
        ];
    }

    private function printReport(array $d, RuleBindingReconciliationService $reconciliation): void
    {
        $this->info("Regla {$d['rule_id']} ({$d['rule_key']}) -- status propuesto: {$d['current_status']} -> {$d['new_status']}");
        $this->newLine();

        $this->line('Config (no se modifica):');
        $this->line('  ' . json_encode($d['config'], JSON_UNESCAPED_UNICODE));
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
        $this->line('Este comando NUNCA crea/modifica/desactiva bindings.');
        $this->newLine();

        $execLogCount = RuleExecutionLog::where('rule_id', $d['rule_id'])->count();
        $validationResultsCount = DB::table('rem_validation_results')->where('rule_id', $d['rule_id'])->count();
        $versionCount = RuleVersion::where('rule_id', $d['rule_id'])->count();
        $this->line("RuleExecutionLog historicos: {$execLogCount} (se preservan intactos)");
        $this->line("rem_validation_results historicos: {$validationResultsCount} (se preservan intactos)");
        $this->line("RuleVersion existentes: {$versionCount} (se preservan intactos, este comando no crea ninguno nuevo)");
        $this->newLine();

        $structure = RemTemplateStructure::where('status', 'active')->first();
        if ($structure) {
            $before = $reconciliation->classifyAllActiveRules($structure)->countBy('clasificacion');
            $this->line('Clasificacion global ANTES: ' . json_encode($before));

            // Simulacion real via transaccion+rollback -- nunca se persiste.
            $after = null;
            DB::beginTransaction();
            try {
                $rule = Rule::find($d['rule_id']);
                $rule->status = $d['new_status'];
                $rule->save();
                $after = $reconciliation->classifyAllActiveRules($structure)->countBy('clasificacion');
            } finally {
                DB::rollBack();
            }
            $this->line('Clasificacion global SIMULADA DESPUES (transaccion+rollback, nada persistido): ' . json_encode($after));
            $activeBefore = Rule::where('status', 'active')->count();
            $activeAfter = $d['new_status'] === 'active' ? $activeBefore + 1 : ($d['current_status'] === 'active' ? $activeBefore - 1 : $activeBefore);
            $this->line("Reglas activas (status='active'): {$activeBefore} -> {$activeAfter} (esperado)");
        } else {
            $this->warn('No hay estructura activa -- no se pudo simular la clasificacion global.');
        }
        $this->newLine();

        $this->line('Escritura EXACTA que ejecutaria --commit:');
        $this->line("  UPDATE rem_rules SET status = '{$d['new_status']}' WHERE id = {$d['rule_id']}  (config, rule_key, rule_type, name sin cambios)");
        $this->line('  + 1 entrada en el activity log (rule_status_change)');
        $this->line('  Ningun binding, RuleExecutionLog, rem_validation_results, RuleVersion, calibracion, rem_data ni estructura se toca.');
    }

    private function commitStatusChange(array $d, string $reason, string $by): int
    {
        try {
            DB::transaction(function () use ($d, $reason, $by): void {
                $rule = Rule::find($d['rule_id']);
                if (!$rule || $rule->status !== $d['current_status'] || $rule->config !== $d['config']) {
                    throw new \RuntimeException('La regla cambio entre la validacion y la escritura -- abortando sin persistir.');
                }

                $rule->update([
                    'status' => $d['new_status'],
                    'updated_by' => null,
                ]);

                activity()
                    ->performedOn($rule)
                    ->withProperties([
                        'rule_id' => $rule->id,
                        'old_status' => $d['current_status'],
                        'new_status' => $d['new_status'],
                        'reason' => $reason,
                        'by' => $by,
                    ])
                    ->log('rule_status_change');
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Regla {$d['rule_id']}: status actualizado de '{$d['current_status']}' a '{$d['new_status']}'. Activity log registrado.");

        return self::SUCCESS;
    }
}
