<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\PatternMigrationScanner;
use App\Domain\RuleEngine\Services\PatternReconciliationService;
use Illuminate\Console\Command;

/**
 * Fase 8A (deuda tecnica #1, 2026-08-12): migracion segura del fingerprint
 * canonico v2 UNICAMENTE para secciones/patrones clasificados hoy como
 * AUTO_MIGRATE por PatternMigrationScanner -- nunca una lista de IDs
 * precalculada, siempre reclasificado en vivo (aqui y otra vez
 * inmediatamente antes de escribir).
 *
 * Escribe EXCLUSIVAMENTE estos campos, por pregunta (pattern_question /
 * pattern_confirmation) de cada patron AUTO_MIGRATE:
 *   - pattern_fingerprint  = huella canonica v2 vigente
 *   - fingerprint_version  = 2
 *   - pattern_rows         = filas del patron, ordenadas
 *   - fingerprint_migrated_at     = marca tecnica de ESTA migracion (nunca reviewed_at)
 *   - fingerprint_migration_source = 'auto_migrate_v2' (nunca source_type,
 *     que describe el origen de la respuesta ORIGINAL -- una migracion
 *     tecnica no debe poder confundirse con una revision humana nueva)
 *
 * NUNCA toca: response, reviewed_by, reviewed_at, review_status, question,
 * source_type, closure_reason, pattern_id, pattern_key, id -- ni ningun
 * otro campo. La autoria historica de cada respuesta queda intacta.
 *
 * Idempotente: un patron cuyo pattern_fingerprint v2 ya coincide con el
 * vigente se reporta pero no se reescribe -- una segunda ejecucion tras
 * --commit no modifica nada.
 *
 * Seguridad de lote: justo antes de escribir, TODOS los candidatos se
 * reclasifican de nuevo contra el estado actual. Si cualquiera dejo de ser
 * AUTO_MIGRATE (por cualquier motivo, incluida una carrera con otro
 * proceso), se aborta el lote COMPLETO sin escribir nada -- nunca una
 * persistencia parcial.
 */
class RemMigrateAutoFingerprintsCommand extends Command
{
    protected $signature = 'rem:migrate-auto-fingerprints
                            {--dry-run : Modo simulacion -- por defecto si no se pasa --commit}
                            {--commit : Persiste los cambios -- requiere --confirm y --target}
                            {--confirm= : Debe ser exactamente CONFIRMAR-MIGRACION-AUTO-V2 para habilitar --commit}
                            {--target= : Ruta real a reglas-funcionales.json -- requerido con --commit}';

    protected $description = 'Migra pattern_fingerprint/fingerprint_version/pattern_rows a v2 UNICAMENTE para secciones AUTO_MIGRATE reclasificadas en vivo -- dry-run por defecto, --commit para persistir';

    public function handle(PatternMigrationScanner $scanner): int
    {
        $commit = (bool) $this->option('commit');
        $dryRun = (bool) $this->option('dry-run') || ! $commit;

        if ($commit && $this->option('dry-run')) {
            $this->error('--dry-run y --commit son mutuamente excluyentes.');

            return self::FAILURE;
        }

        $target = null;
        if ($commit) {
            if ($this->option('confirm') !== 'CONFIRMAR-MIGRACION-AUTO-V2') {
                $this->error('--commit requiere --confirm=CONFIRMAR-MIGRACION-AUTO-V2 exacto.');

                return self::FAILURE;
            }
            $target = $this->option('target');
            if (! $target || ! file_exists($target)) {
                $this->error('--commit requiere --target=<ruta real a reglas-funcionales.json existente>.');

                return self::FAILURE;
            }
        }

        $activeStructure = RemTemplateStructure::where('status', 'active')->first();
        if (! $activeStructure) {
            $this->error('No hay ninguna estructura activa.');

            return self::FAILURE;
        }

        $this->line('Modo: '.($commit ? '*** COMMIT (persistira) ***' : 'DRY-RUN (no persiste nada)'));
        $this->newLine();

        $sections = $scanner->scanAllSections($activeStructure);
        $candidates = array_filter($sections, fn ($s) => $s['category'] === PatternReconciliationService::MIGRATION_AUTO_MIGRATE);

        $this->reportCandidates($candidates);

        if (! $commit) {
            $this->newLine();
            $this->info('DRY-RUN: '.count($candidates).' secciones AUTO_MIGRATE encontradas. Nada fue escrito. Ejecuta con --commit para persistir.');

            return self::SUCCESS;
        }

        // --- Reverificacion EN VIVO inmediatamente antes de escribir ---
        $this->newLine();
        $this->warn('Reverificando en vivo justo antes de escribir...');
        $freshSections = $scanner->scanAllSections($activeStructure);

        foreach (array_keys($candidates) as $key) {
            if (($freshSections[$key]['category'] ?? null) !== PatternReconciliationService::MIGRATION_AUTO_MIGRATE) {
                $this->error("ABORTANDO EL LOTE COMPLETO: {$key} dejo de ser AUTO_MIGRATE entre el analisis y la escritura (ahora: ".($freshSections[$key]['category'] ?? 'no encontrada').'). No se escribio nada.');

                return self::FAILURE;
            }
        }
        $this->info('Reverificacion OK: los '.count($candidates).' candidatos siguen siendo AUTO_MIGRATE. Continuando.');

        // --- Backup (solo el JSON -- esta migracion nunca toca BD) ---
        $backupPath = $target.'.bak-'.now()->format('Ymd_His');
        if (! copy($target, $backupPath)) {
            $this->error("No se pudo crear el respaldo en {$backupPath}. Abortando sin escribir.");

            return self::FAILURE;
        }
        $this->info("Respaldo creado: {$backupPath}");

        $all = json_decode(file_get_contents($target), true);

        $sectionsChanged = 0;
        $patternsChanged = 0;
        $questionsChanged = 0;
        $alreadyMigratedPatterns = 0;
        $migratedAt = now()->toIso8601String();

        foreach ($freshSections as $key => $section) {
            if ($section['category'] !== PatternReconciliationService::MIGRATION_AUTO_MIGRATE) {
                continue;
            }

            $jsonKey = "{$section['sheet']}_{$section['code']}";
            if (! isset($all['_questions'][$jsonKey])) {
                continue;
            }

            $sectionTouched = false;

            foreach ($section['patterns'] as $pattern) {
                if ($pattern['already_v2_matching']) {
                    $alreadyMigratedPatterns++;

                    continue;
                }

                $pid = $pattern['pattern_id'];
                $sortedRows = $pattern['live_rows'];
                sort($sortedRows, SORT_NUMERIC);

                $patternTouched = false;
                foreach ($all['_questions'][$jsonKey] as $idx => $q) {
                    if (! in_array($q['type'] ?? '', ['pattern_question', 'pattern_confirmation'], true)) {
                        continue;
                    }
                    if (($q['pattern_id'] ?? null) !== $pid) {
                        continue;
                    }

                    $all['_questions'][$jsonKey][$idx]['pattern_fingerprint'] = $pattern['live_canonical_fingerprint'];
                    $all['_questions'][$jsonKey][$idx]['fingerprint_version'] = PatternReconciliationService::FINGERPRINT_VERSION_CANONICAL;
                    $all['_questions'][$jsonKey][$idx]['pattern_rows'] = $sortedRows;
                    $all['_questions'][$jsonKey][$idx]['fingerprint_migrated_at'] = $migratedAt;
                    $all['_questions'][$jsonKey][$idx]['fingerprint_migration_source'] = 'auto_migrate_v2';
                    // Deliberadamente SIN TOCAR: response, reviewed_by, reviewed_at,
                    // review_status, question, source_type, closure_reason, id,
                    // pattern_id, pattern_key -- la autoria historica no cambia.

                    $patternTouched = true;
                    $questionsChanged++;
                }

                if ($patternTouched) {
                    $patternsChanged++;
                    $sectionTouched = true;
                }
            }

            if ($sectionTouched) {
                $sectionsChanged++;
            }
        }

        file_put_contents($target, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->info("Migración completada: {$sectionsChanged} secciones, {$patternsChanged} patrones, {$questionsChanged} preguntas actualizadas a fingerprint_version=2.");
        if ($alreadyMigratedPatterns > 0) {
            $this->line("{$alreadyMigratedPatterns} patrones ya tenían v2 coincidente -- sin cambios (idempotencia).");
        }

        return self::SUCCESS;
    }

    private function reportCandidates(array $candidates): void
    {
        $rows = [];
        foreach ($candidates as $key => $section) {
            foreach ($section['patterns'] as $p) {
                $rows[] = [
                    $key,
                    $p['pattern_id'],
                    $p['already_v2_matching'] ? 'ya migrado (sin cambio)' : 'pattern_fingerprint, fingerprint_version, pattern_rows',
                    substr((string) $p['live_canonical_fingerprint'], 0, 24),
                    implode(',', $p['live_rows']),
                ];
            }
        }

        $this->table(['Hoja/Sección', 'pattern_id', 'Campos a escribir', 'Fingerprint v2 (vigente)', 'Filas'], $rows);
    }
}
