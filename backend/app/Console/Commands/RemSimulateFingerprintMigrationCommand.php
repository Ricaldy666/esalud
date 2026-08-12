<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\PatternMigrationScanner;
use App\Domain\RuleEngine\Services\PatternReconciliationService;
use Illuminate\Console\Command;

/**
 * Fase 5 (deuda tecnica #1, 2026-08-12): simula, para TODAS las secciones
 * reales de la estructura activa (no solo las que ya tienen historial en
 * reglas-funcionales.json -- eso es justamente lo que permite detectar
 * secciones nuevas de forma generica, sin listarlas a mano), en que
 * categoria de migracion caeria cada una si se activara hoy el mecanismo
 * de fingerprint canonico v2.
 *
 * 100% lectura: solo consulta la estructura activa real, cell-data real ya
 * escaneado y reglas-funcionales.json real -- ninguno de los tres se
 * modifica. No existe ningun camino de escritura en este comando.
 *
 * Desde Fase 8A (2026-08-12) el recorrido/clasificacion vive en
 * PatternMigrationScanner (unica fuente de verdad, tambien usada por
 * RemMigrateAutoFingerprintsCommand) -- este comando es ahora solo el
 * reporte de esa clasificacion.
 *
 * Categorias (a nivel de seccion -- una seccion con multiples patrones usa
 * la categoria mas conservadora entre todos sus patrones):
 *   - NO_UTILIZADA: la hoja esta marcada no_utilizada (RemSheetUsageStatusService).
 *   - NOT_CALIBRATABLE: cerrada via calibration_applicability.status=not_calibratable
 *     (section_review con response=no_calibrable).
 *   - NEW_SECTION: no existe ninguna entrada en reglas-funcionales.json para
 *     esta hoja/seccion (nunca fue revisada).
 *   - AUTO_MIGRATE: todos sus patrones tienen evidencia suficiente para
 *     migrar sin intervencion humana (ver PatternReconciliationService).
 *   - QUICK_CONFIRMATION: al menos un patron tiene filas confirmadas pero
 *     columnas/formulas/editabilidad sin verificar (estructura declarada
 *     cambio desde la revision).
 *   - MISMATCH: al menos un patron tiene evidencia concreta de que ya no
 *     corresponde a lo revisado.
 *   - FULL_REVALIDATION: sin evidencia suficiente para ninguna de las
 *     anteriores.
 */
class RemSimulateFingerprintMigrationCommand extends Command
{
    protected $signature = 'rem:simulate-fingerprint-migration {--dry-run : Obligatorio -- este comando nunca escribe nada, el flag es solo para dejarlo explicito en el historial de comandos}';

    protected $description = 'Simula, 100% en lectura, en que categoria de migracion (AUTO_MIGRATE/QUICK_CONFIRMATION/FULL_REVALIDATION/MISMATCH/NEW_SECTION/NOT_CALIBRATABLE/NO_UTILIZADA) caeria cada seccion real bajo el nuevo fingerprint canonico v2';

    public function handle(PatternMigrationScanner $scanner): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Este comando solo soporta --dry-run -- no existe ningun camino de escritura.');

            return self::FAILURE;
        }

        $activeStructure = RemTemplateStructure::where('status', 'active')->first();
        if (! $activeStructure) {
            $this->error('No hay ninguna estructura activa.');

            return self::FAILURE;
        }

        $sections = $scanner->scanAllSections($activeStructure);

        $counts = [
            'NO_UTILIZADA' => 0,
            'NOT_CALIBRATABLE' => 0,
            'NEW_SECTION' => 0,
            PatternReconciliationService::MIGRATION_AUTO_MIGRATE => 0,
            PatternReconciliationService::MIGRATION_QUICK_CONFIRMATION => 0,
            PatternReconciliationService::MIGRATION_FULL_REVALIDATION => 0,
            PatternReconciliationService::MIGRATION_MISMATCH => 0,
        ];
        $detail = [];
        foreach ($sections as $key => $section) {
            $counts[$section['category']] = ($counts[$section['category']] ?? 0) + 1;
            $detail[$key] = $section['category'];
        }

        $this->table(['Categoría', 'Cantidad'], array_map(fn ($k, $v) => [$k, $v], array_keys($counts), $counts));

        $this->newLine();
        $this->warn('No AUTO_MIGRATE (requieren algo de atención):');
        $notAuto = array_filter($detail, fn ($v) => $v !== PatternReconciliationService::MIGRATION_AUTO_MIGRATE && $v !== 'NO_UTILIZADA' && $v !== 'NOT_CALIBRATABLE');
        $this->table(['Hoja/Sección', 'Categoría'], array_map(fn ($k, $v) => [$k, $v], array_keys($notAuto), $notAuto));

        $this->newLine();
        $this->warn('DRY-RUN: solo lectura, nada fue escrito.');

        return self::SUCCESS;
    }
}
